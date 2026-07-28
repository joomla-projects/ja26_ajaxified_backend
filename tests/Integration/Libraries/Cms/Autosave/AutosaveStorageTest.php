<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveStorage;
use Joomla\CMS\Autosave\GenerationState;
use Joomla\CMS\Date\Date;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Database integration tests for \Joomla\CMS\Autosave\AutosaveStorage.
 *
 * @package     Joomla.IntegrationTest
 * @subpackage  Autosave
 *
 * @testdox     The Autosave storage database integration
 *
 * @since       __DEPLOY_VERSION__
 */
class AutosaveStorageTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    /**
     * Prepare empty Autosave tables for each test.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['#__autosave_generations', '#__autosave_continuations'] as $table) {
            $query = $this->getDBDriver()->createQuery()
                ->delete($this->getDBDriver()->quoteName($table));

            $this->getDBDriver()->setQuery($query)->execute();
        }
    }

    /**
     * Return the schema required by this test class.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getSchemasToLoad(): array
    {
        return ['autosave.sql'];
    }

    /**
     * @testdox  creates one continuation and one correctly initialized generation
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testFirstInitializationPersistsInitialState(): void
    {
        $result       = $this->initialize();
        $continuation = $this->loadRow('#__autosave_continuations', 'public_id', $result['continuation_id']);
        $generation   = $this->loadRow('#__autosave_generations', 'public_id', $result['generation_id']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['continuation_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['generation_id']);
        $this->assertNotSame($result['continuation_id'], $result['generation_id']);
        $this->assertSame(1, $this->countRows('#__autosave_continuations'));
        $this->assertSame(1, $this->countRows('#__autosave_generations'));
        $this->assertSame(7, (int) $continuation['user_id']);
        $this->assertSame('com_example.record', $continuation['context']);
        $this->assertSame('record-42', $continuation['target_id']);
        $this->assertSame('initialization-1', $continuation['initialization_key']);
        $this->assertSame(GenerationState::Active->value, $generation['state']);
        $this->assertSame(0, (int) $generation['client_revision']);
        $this->assertNull($generation['payload']);
        $this->assertNull($generation['payload_schema_version']);
        $this->assertSame(1, (int) $generation['active_marker']);
        $this->assertSame(1, (int) $generation['quota_slot']);
        $this->assertSame('revision-1', $generation['base_revision']);
        $this->assertSame('2026-07-29 10:01:00', $generation['expires_at']);
        $this->assertNull($generation['terminal_at']);
        $this->assertNull($generation['retain_until']);
    }

    /**
     * @testdox  returns the same identities for an identical retry without consuming storage or quota
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testIdenticalRetryIsIdempotent(): void
    {
        $first  = $this->initialize();
        $second = $this->initialize();

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->countRows('#__autosave_continuations'));
        $this->assertSame(1, $this->countRows('#__autosave_generations'));
        $this->assertSame([1], $this->loadQuotaSlots(7));
    }

    /**
     * @testdox  rejects reuse of an initialization key with different semantic inputs without partial rows
     *
     * @param   array  $changes  Initialization argument replacements.
     *
     * @return  void
     *
     * @dataProvider semanticConflictProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInitializationKeyConflictIsRejected(array $changes): void
    {
        $this->initialize();

        try {
            $this->initialize($changes);
            $this->fail('The semantic idempotency-key conflict was not rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                'The Autosave initialization key is already bound to different inputs.',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $this->countRows('#__autosave_continuations'));
        $this->assertSame(1, $this->countRows('#__autosave_generations'));
    }

    /**
     * Semantic idempotency-key conflict cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function semanticConflictProvider(): array
    {
        return [
            'context differs'      => [['context' => 'com_example.other']],
            'target differs'       => [['targetId' => 'record-43']],
            'base revision differs' => [['baseRevision' => 'revision-2']],
        ];
    }

    /**
     * @testdox  preserves opaque values and creates independent continuations for different keys
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDifferentKeysCreateIndependentContinuationsWithoutNormalization(): void
    {
        $first = $this->initialize(
            [
                'targetId'          => ' target ',
                'baseRevision'      => ' revision ',
                'initializationKey' => ' key-one ',
            ]
        );
        $second = $this->initialize(
            [
                'targetId'          => ' target ',
                'baseRevision'      => ' revision ',
                'initializationKey' => ' key-two ',
            ]
        );

        $firstContinuation = $this->loadRow('#__autosave_continuations', 'public_id', $first['continuation_id']);
        $firstGeneration   = $this->loadRow('#__autosave_generations', 'public_id', $first['generation_id']);

        $this->assertNotSame($first['continuation_id'], $second['continuation_id']);
        $this->assertNotSame($first['generation_id'], $second['generation_id']);
        $this->assertSame(' target ', $firstContinuation['target_id']);
        $this->assertSame(' key-one ', $firstContinuation['initialization_key']);
        $this->assertSame(' revision ', $firstGeneration['base_revision']);
        $this->assertSame(2, $this->countRows('#__autosave_continuations'));
        $this->assertSame(2, $this->countRows('#__autosave_generations'));
    }

    /**
     * @testdox  isolates owner quota and rejects exhaustion without partial rows
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testQuotaIsEnforcedPerUser(): void
    {
        $policy = ['max_active_generations' => 1];

        $this->initialize([], $policy);
        $this->initialize(['userId' => 8], $policy);

        try {
            $this->initialize(['initializationKey' => 'initialization-2'], $policy);
            $this->fail('The exhausted owner quota was not rejected.');
        } catch (\OverflowException $exception) {
            $this->assertSame('The active Autosave generation quota has been reached.', $exception->getMessage());
        }

        $this->assertSame([1], $this->loadQuotaSlots(7));
        $this->assertSame([1], $this->loadQuotaSlots(8));
        $this->assertSame(2, $this->countRows('#__autosave_continuations'));
        $this->assertSame(2, $this->countRows('#__autosave_generations'));
    }

    /**
     * @testdox  keeps unexpired generations active and consuming quota
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testUnexpiredGenerationContinuesToConsumeQuota(): void
    {
        $policy = [
            'idle_ttl'              => 10,
            'max_lifetime'           => 20,
            'max_active_generations' => 1,
        ];

        $this->initialize([], $policy);

        $this->expectException(\OverflowException::class);
        $this->initialize(
            [
                'initializationKey' => 'initialization-2',
                'now'               => new Date('2026-07-29 10:00:09', 'UTC'),
            ],
            $policy
        );
    }

    /**
     * @testdox  expires eligible generations as tombstones and releases their quota slot
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExpiryCreatesTombstoneAndReleasesQuota(): void
    {
        $policy = [
            'idle_ttl'              => 10,
            'max_lifetime'           => 20,
            'tombstone_retention'    => 30,
            'max_active_generations' => 1,
        ];

        $first = $this->initialize([], $policy);
        $this->initialize(
            [
                'initializationKey' => 'initialization-2',
                'now'               => new Date('2026-07-29 10:00:11', 'UTC'),
            ],
            $policy
        );

        $expired = $this->loadRow('#__autosave_generations', 'public_id', $first['generation_id']);
        $rows    = $this->loadRows('#__autosave_generations');

        $this->assertSame(GenerationState::Expired->value, $expired['state']);
        $this->assertNull($expired['active_marker']);
        $this->assertNull($expired['quota_slot']);
        $this->assertSame('2026-07-29 10:00:11', $expired['terminal_at']);
        $this->assertSame('2026-07-29 10:00:41', $expired['retain_until']);
        $this->assertSame(2, $this->countRows('#__autosave_continuations'));
        $this->assertSame(2, $this->countRows('#__autosave_generations'));
        $this->assertSame(GenerationState::Active->value, $rows[1]['state']);
        $this->assertSame(1, (int) $rows[1]['quota_slot']);
    }

    /**
     * @testdox  expires a generation exactly at its expiry boundary and reuses its slot
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExpiryAtExactBoundaryCreatesTombstoneAndReusesQuota(): void
    {
        $policy = [
            'idle_ttl'              => 10,
            'max_lifetime'           => 20,
            'tombstone_retention'    => 30,
            'max_active_generations' => 1,
        ];

        $first  = $this->initialize([], $policy);
        $second = $this->initialize(
            [
                'initializationKey' => 'initialization-2',
                'now'               => new Date('2026-07-29 10:00:10', 'UTC'),
            ],
            $policy
        );

        $expired = $this->loadRow('#__autosave_generations', 'public_id', $first['generation_id']);
        $active  = $this->loadRow('#__autosave_generations', 'public_id', $second['generation_id']);

        $this->assertSame(GenerationState::Expired->value, $expired['state']);
        $this->assertSame('2026-07-29 10:00:10', $expired['terminal_at']);
        $this->assertSame('2026-07-29 10:00:40', $expired['retain_until']);
        $this->assertNull($expired['active_marker']);
        $this->assertNull($expired['quota_slot']);
        $this->assertSame(GenerationState::Active->value, $active['state']);
        $this->assertSame(1, (int) $active['active_marker']);
        $this->assertSame(1, (int) $active['quota_slot']);
    }

    /**
     * @testdox  does not exceed a reduced quota when a legacy high slot remains active
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReducedQuotaCountsActiveSlotsOutsideConfiguredRange(): void
    {
        $oldPolicy = [
            'idle_ttl'              => 10,
            'max_lifetime'           => 20,
            'tombstone_retention'    => 30,
            'max_active_generations' => 2,
        ];

        $this->initialize([], $oldPolicy);
        $this->initialize(
            [
                'initializationKey' => 'initialization-2',
                'now'               => new Date('2026-07-29 10:00:05', 'UTC'),
            ],
            $oldPolicy
        );

        try {
            $this->initialize(
                [
                    'initializationKey' => 'initialization-3',
                    'now'               => new Date('2026-07-29 10:00:11', 'UTC'),
                ],
                array_replace($oldPolicy, ['max_active_generations' => 1])
            );
            $this->fail('The reduced active-generation quota was not enforced.');
        } catch (\OverflowException $exception) {
            $this->assertSame('The active Autosave generation quota has been reached.', $exception->getMessage());
        }

        $generations = $this->loadRows('#__autosave_generations');

        $this->assertSame(2, $this->countRows('#__autosave_continuations'));
        $this->assertSame(2, $this->countRows('#__autosave_generations'));
        $this->assertSame(GenerationState::Active->value, $generations[0]['state']);
        $this->assertSame(1, (int) $generations[0]['active_marker']);
        $this->assertSame(1, (int) $generations[0]['quota_slot']);
        $this->assertSame(GenerationState::Active->value, $generations[1]['state']);
        $this->assertSame(1, (int) $generations[1]['active_marker']);
        $this->assertSame(2, (int) $generations[1]['quota_slot']);
    }

    /**
     * @testdox  enforces unique public continuation identities in the database
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDatabaseEnforcesUniquePublicContinuationId(): void
    {
        $result       = $this->initialize();
        $continuation = $this->loadRow('#__autosave_continuations', 'public_id', $result['continuation_id']);

        $continuation['initialization_key'] = 'initialization-2';

        $this->expectException(ExecutionFailureException::class);
        $this->insertContinuation($continuation);
    }

    /**
     * @testdox  enforces one initialization key per owner in the database
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDatabaseEnforcesOwnerScopedInitializationKey(): void
    {
        $result       = $this->initialize();
        $continuation = $this->loadRow('#__autosave_continuations', 'public_id', $result['continuation_id']);

        $continuation['public_id'] = str_repeat('c', 64);
        $continuation['target_id'] = 'record-43';

        $this->expectException(ExecutionFailureException::class);
        $this->insertContinuation($continuation);
    }

    /**
     * @testdox  enforces unique public generation identities in the database
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDatabaseEnforcesUniquePublicGenerationId(): void
    {
        $result     = $this->initialize();
        $generation = $this->loadRow('#__autosave_generations', 'public_id', $result['generation_id']);

        $generation['state']         = GenerationState::Expired->value;
        $generation['active_marker'] = null;
        $generation['quota_slot']    = null;
        $generation['terminal_at']   = $generation['updated_at'];
        $generation['retain_until']  = $generation['expires_at'];

        $this->expectException(ExecutionFailureException::class);
        $this->insertGeneration($generation);
    }

    /**
     * @testdox  enforces at most one active generation per continuation in the database
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDatabaseEnforcesOneActiveGenerationPerContinuation(): void
    {
        $result     = $this->initialize();
        $generation = $this->loadRow('#__autosave_generations', 'public_id', $result['generation_id']);

        $generation['public_id']  = str_repeat('c', 64);
        $generation['quota_slot'] = 2;

        $this->expectException(ExecutionFailureException::class);
        $this->insertGeneration($generation);
    }

    /**
     * @testdox  enforces one active quota-slot occupant per owner in the database
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDatabaseEnforcesPerOwnerQuotaSlotOccupancy(): void
    {
        $this->initialize();
        $second     = $this->initialize(['initializationKey' => 'initialization-2']);
        $generation = $this->loadRow('#__autosave_generations', 'public_id', $second['generation_id']);

        $this->terminalizeGeneration($second['generation_id']);
        $generation['public_id']   = str_repeat('c', 64);
        $generation['quota_slot']  = 1;
        $generation['terminal_at'] = null;
        $generation['retain_until'] = null;

        $this->expectException(ExecutionFailureException::class);
        $this->insertGeneration($generation);
    }

    /**
     * Initialize storage with optional argument and policy replacements.
     *
     * @param   array  $arguments  Initialization argument replacements.
     * @param   array  $policy     Policy replacements.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function initialize(array $arguments = [], array $policy = []): array
    {
        $arguments = array_replace(
            [
                'userId'            => 7,
                'context'           => 'com_example.record',
                'targetId'          => 'record-42',
                'baseRevision'      => 'revision-1',
                'initializationKey' => 'initialization-1',
                'now'               => new Date('2026-07-29 10:00:00', 'UTC'),
            ],
            $arguments
        );
        $policy = array_replace(
            [
                'idle_ttl'              => 60,
                'max_lifetime'           => 120,
                'tombstone_retention'    => 300,
                'max_active_generations' => 2,
            ],
            $policy
        );

        return (new AutosaveStorage($this->getDBDriver(), $policy))->initialize(...array_values($arguments));
    }

    /**
     * Load a row by a unique column value.
     *
     * @param   string  $table   The table name.
     * @param   string  $column  The column name.
     * @param   mixed   $value   The value.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadRow(string $table, string $column, $value): array
    {
        $query = $this->getDBDriver()->createQuery()
            ->select('*')
            ->from($this->getDBDriver()->quoteName($table))
            ->where($this->getDBDriver()->quoteName($column) . ' = :value')
            ->bind(':value', $value);

        return $this->getDBDriver()->setQuery($query)->loadAssoc();
    }

    /**
     * Load all table rows in internal identity order.
     *
     * @param   string  $table  The table name.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadRows(string $table): array
    {
        $query = $this->getDBDriver()->createQuery()
            ->select('*')
            ->from($this->getDBDriver()->quoteName($table))
            ->order($this->getDBDriver()->quoteName('id') . ' ASC');

        return $this->getDBDriver()->setQuery($query)->loadAssocList();
    }

    /**
     * Count rows in a table.
     *
     * @param   string  $table  The table name.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function countRows(string $table): int
    {
        $query = $this->getDBDriver()->createQuery()
            ->select('COUNT(*)')
            ->from($this->getDBDriver()->quoteName($table));

        return (int) $this->getDBDriver()->setQuery($query)->loadResult();
    }

    /**
     * Load the occupied quota slots for an owner.
     *
     * @param   integer  $userId  The owner identity.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadQuotaSlots(int $userId): array
    {
        $query = $this->getDBDriver()->createQuery()
            ->select($this->getDBDriver()->quoteName('quota_slot'))
            ->from($this->getDBDriver()->quoteName('#__autosave_generations'))
            ->where($this->getDBDriver()->quoteName('user_id') . ' = :user_id')
            ->where($this->getDBDriver()->quoteName('quota_slot') . ' IS NOT NULL')
            ->order($this->getDBDriver()->quoteName('quota_slot') . ' ASC')
            ->bind(':user_id', $userId, ParameterType::INTEGER);

        return array_map('intval', $this->getDBDriver()->setQuery($query)->loadColumn());
    }

    /**
     * Insert a complete continuation row to exercise database constraints.
     *
     * @param   array  $continuation  The continuation values.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function insertContinuation(array $continuation): void
    {
        unset($continuation['id']);

        $columns    = [];
        $parameters = [];
        $query      = $this->getDBDriver()->createQuery()
            ->insert($this->getDBDriver()->quoteName('#__autosave_continuations'));

        foreach ($continuation as $column => &$value) {
            $parameter    = ':' . $column;
            $columns[]    = $this->getDBDriver()->quoteName($column);
            $parameters[] = $parameter;
            $query->bind(
                $parameter,
                $value,
                $column === 'user_id' ? ParameterType::INTEGER : ParameterType::STRING
            );
        }

        unset($value);

        $query->columns($columns)->values(implode(', ', $parameters));
        $this->getDBDriver()->setQuery($query)->execute();
    }

    /**
     * Insert a complete generation row to exercise database constraints.
     *
     * @param   array  $generation  The generation values.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function insertGeneration(array $generation): void
    {
        unset($generation['id']);

        $columns    = [];
        $parameters = [];
        $types      = [
            'continuation_id'       => ParameterType::INTEGER,
            'user_id'               => ParameterType::INTEGER,
            'client_revision'       => ParameterType::INTEGER,
            'payload_schema_version' => ParameterType::INTEGER,
            'active_marker'         => ParameterType::INTEGER,
            'quota_slot'            => ParameterType::INTEGER,
        ];
        $query      = $this->getDBDriver()->createQuery()
            ->insert($this->getDBDriver()->quoteName('#__autosave_generations'));

        foreach ($generation as $column => &$value) {
            $parameter   = ':' . $column;
            $columns[]   = $this->getDBDriver()->quoteName($column);
            $parameters[] = $parameter;
            $query->bind($parameter, $value, $value === null ? ParameterType::NULL : ($types[$column] ?? ParameterType::STRING));
        }

        unset($value);

        $query->columns($columns)->values(implode(', ', $parameters));
        $this->getDBDriver()->setQuery($query)->execute();
    }

    /**
     * Convert a generation to a terminal row for a constraint fixture.
     *
     * @param   string  $publicId  The generation public identity.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function terminalizeGeneration(string $publicId): void
    {
        $state = GenerationState::Expired->value;
        $query = $this->getDBDriver()->createQuery()
            ->update($this->getDBDriver()->quoteName('#__autosave_generations'))
            ->set(
                [
                    $this->getDBDriver()->quoteName('state') . ' = :state',
                    $this->getDBDriver()->quoteName('active_marker') . ' = NULL',
                    $this->getDBDriver()->quoteName('quota_slot') . ' = NULL',
                ]
            )
            ->where($this->getDBDriver()->quoteName('public_id') . ' = :public_id')
            ->bind(':state', $state)
            ->bind(':public_id', $publicId);

        $this->getDBDriver()->setQuery($query)->execute();
    }
}
