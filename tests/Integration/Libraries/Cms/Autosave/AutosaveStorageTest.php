<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
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

        foreach (
            ['#__autosave_canonical_actions', '#__autosave_generations', '#__autosave_continuations'] as $table
        ) {
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
        $this->assertNull($generation['payload_digest']);
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
        } catch (AutosaveException $exception) {
            $this->assertSame('initialization_conflict', $exception->getErrorCode());
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
            'context differs'       => [['context' => 'com_example.other']],
            'target differs'        => [['targetId' => 'record-43']],
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
        } catch (AutosaveException $exception) {
            $this->assertSame('draft_limit_reached', $exception->getErrorCode());
            $this->assertSame('The active Autosave draft limit has been reached.', $exception->getMessage());
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
            'idle_ttl'               => 10,
            'max_lifetime'           => 20,
            'max_active_generations' => 1,
        ];

        $this->initialize([], $policy);

        $this->expectException(AutosaveException::class);
        $this->expectExceptionMessage('The active Autosave draft limit has been reached.');
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
            'idle_ttl'               => 10,
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
            'idle_ttl'               => 10,
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
            'idle_ttl'               => 10,
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
        } catch (AutosaveException $exception) {
            $this->assertSame('draft_limit_reached', $exception->getErrorCode());
            $this->assertSame('The active Autosave draft limit has been reached.', $exception->getMessage());
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
        $generation['public_id']    = str_repeat('c', 64);
        $generation['quota_slot']   = 1;
        $generation['terminal_at']  = null;
        $generation['retain_until'] = null;

        $this->expectException(ExecutionFailureException::class);
        $this->insertGeneration($generation);
    }

    /**
     * @testdox  compares owner-scoped initialization keys by exact bytes
     *
     * @param   string  $firstKey   The first exact key.
     * @param   string  $secondKey  The byte-distinct key.
     *
     * @return  void
     *
     * @dataProvider byteDistinctInitializationKeyProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testByteDistinctInitializationKeysCanCoexist(string $firstKey, string $secondKey): void
    {
        $first  = $this->initialize(['initializationKey' => $firstKey]);
        $second = $this->initialize(['initializationKey' => $secondKey]);

        $this->assertNotSame($first['continuation_id'], $second['continuation_id']);
        $this->assertSame(2, $this->countRows('#__autosave_continuations'));
    }

    /**
     * Byte-distinct initialization-key cases required by the storage contract.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function byteDistinctInitializationKeyProvider(): array
    {
        return [
            'trailing U+0020 space' => ['key', 'key '],
            'case difference'       => ['key', 'Key'],
            'Unicode normalization' => ["\u{00E9}", "e\u{0301}"],
        ];
    }

    /**
     * @testdox  scopes an identical initialization key to its owner
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testAnotherOwnerCanReuseAnInitializationKey(): void
    {
        $first  = $this->initialize();
        $second = $this->initialize(['userId' => 8]);

        $this->assertNotSame($first['continuation_id'], $second['continuation_id']);
        $this->assertSame(2, $this->countRows('#__autosave_continuations'));
    }

    /**
     * @testdox  preserves and inspects a deterministic owner-bound payload
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveAndInspectRoundTrip(): void
    {
        $identities = $this->initialize();
        $payload    = [
            'z'       => ['second' => 2, 'first' => 1],
            'article' => '<p>Draft &amp; text</p>',
            'list'    => [3, 1, 2],
            'float'   => 1.0,
        ];
        $storage    = $this->storage();

        $result = $storage->preserve(
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            'com_example.record',
            'record-42',
            'revision-1',
            1,
            $payload,
            1,
            new Date('2026-07-29 10:00:10', 'UTC')
        );
        $inspected = $storage->inspect(
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            new Date('2026-07-29 10:00:11', 'UTC')
        );
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);

        $this->assertSame('accepted', $result);
        $this->assertSame(1, $inspected['client_revision']);
        $this->assertSame(1, $inspected['payload_schema_version']);
        $this->assertSame(
            [
                'article' => '<p>Draft &amp; text</p>',
                'float'   => 1.0,
                'list'    => [3, 1, 2],
                'z'       => ['first' => 1, 'second' => 2],
            ],
            $inspected['payload']
        );
        $this->assertSame(
            '{"article":"<p>Draft &amp; text</p>","float":1.0,"list":[3,1,2],"z":{"first":1,"second":2}}',
            $row['payload']
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row['payload_digest']);
    }

    /**
     * @testdox  applies the complete monotonic revision and exact-retry matrix
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveRevisionMatrix(): void
    {
        $identities = $this->initialize();
        $storage    = $this->storage();
        $first      = ['value' => 'first'];
        $third      = ['value' => 'third'];

        $this->assertSame(
            'accepted',
            $this->preserve($storage, $identities, 1, $first, 1, '2026-07-29 10:00:10')
        );
        $beforeRetry = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);
        $this->assertSame(
            'idempotent',
            $this->preserve($storage, $identities, 1, $first, 1, '2026-07-29 10:00:20')
        );
        $afterRetry = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);

        $this->assertSame($beforeRetry['updated_at'], $afterRetry['updated_at']);
        $this->assertSame($beforeRetry['expires_at'], $afterRetry['expires_at']);
        $this->assertSame('accepted', $this->preserve($storage, $identities, 3, $third, 1, '2026-07-29 10:00:30'));
        $this->assertAutosaveFailure(
            'stale_client_revision',
            fn () => $this->preserve($storage, $identities, 2, ['value' => 'second'], 1, '2026-07-29 10:00:31')
        );
        $this->assertAutosaveFailure(
            'revision_conflict',
            fn () => $this->preserve($storage, $identities, 3, ['value' => 'changed'], 1, '2026-07-29 10:00:31')
        );
        $this->assertAutosaveFailure(
            'schema_version_conflict',
            fn () => $this->preserve($storage, $identities, 3, $third, 2, '2026-07-29 10:00:31')
        );
        $this->assertAutosaveFailure(
            'base_revision_conflict',
            fn () => $this->preserve(
                $storage,
                $identities,
                4,
                ['value' => 'fourth'],
                1,
                '2026-07-29 10:00:31',
                ['baseRevision' => 'revision-2']
            )
        );
    }

    /**
     * @testdox  keeps detection owner-bound, metadata-only and limited to acknowledged active payloads
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectReturnsOnlyRecoverableAcknowledgedMetadata(): void
    {
        $identities = $this->initialize();
        $storage    = $this->storage();
        $now        = new Date('2026-07-29 10:00:10', 'UTC');

        $this->assertNull($storage->detect(7, 'com_example.record', 'record-42', $now));
        $this->preserve($storage, $identities, 1, ['value' => 'draft'], 1, '2026-07-29 10:00:10');

        $detected = $storage->detect(
            7,
            'com_example.record',
            'record-42',
            new Date('2026-07-29 10:00:11', 'UTC')
        );

        $this->assertSame($identities['continuation_id'], $detected['continuation_id']);
        $this->assertSame($identities['generation_id'], $detected['generation_id']);
        $this->assertSame(1, $detected['client_revision']);
        $this->assertArrayNotHasKey('payload', $detected);
        $this->assertNull(
            $storage->detect(8, 'com_example.record', 'record-42', new Date('2026-07-29 10:00:11', 'UTC'))
        );
        $this->assertNull(
            $storage->detect(7, 'com_example.other', 'record-42', new Date('2026-07-29 10:00:11', 'UTC'))
        );
    }

    /**
     * @testdox  detects the generation with the newest acknowledged activity
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectOrdersAcknowledgedActivityNewestFirst(): void
    {
        $first   = $this->initialize();
        $storage = $this->storage();
        $this->preserve($storage, $first, 1, ['value' => 'first'], 1, '2026-07-29 10:00:10');
        $second = $this->initialize(
            [
                'initializationKey' => 'initialization-2',
                'now'               => new Date('2026-07-29 10:00:20', 'UTC'),
            ]
        );
        $this->preserve($storage, $second, 1, ['value' => 'second'], 1, '2026-07-29 10:00:30');

        $detected = $storage->detect(
            7,
            'com_example.record',
            'record-42',
            new Date('2026-07-29 10:00:31', 'UTC')
        );

        $this->assertSame($second['generation_id'], $detected['generation_id']);
    }

    /**
     * @testdox  discards atomically, erases recoverable content and preserves tombstone metadata
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDiscardClearsPayloadAndIsIdempotent(): void
    {
        $identities = $this->initialize();
        $storage    = $this->storage();
        $this->preserve($storage, $identities, 1, ['value' => 'draft'], 1, '2026-07-29 10:00:10');

        $this->assertSame(
            'discarded',
            $storage->discard(
                7,
                $identities['continuation_id'],
                $identities['generation_id'],
                new Date('2026-07-29 10:00:20', 'UTC')
            )
        );
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);

        $this->assertSame(GenerationState::Discarded->value, $row['state']);
        $this->assertSame(1, (int) $row['client_revision']);
        $this->assertSame(1, (int) $row['payload_schema_version']);
        $this->assertNull($row['payload']);
        $this->assertNull($row['payload_digest']);
        $this->assertNull($row['active_marker']);
        $this->assertNull($row['quota_slot']);
        $this->assertSame('2026-07-29 10:00:20', $row['terminal_at']);
        $this->assertSame('2026-07-29 10:05:20', $row['retain_until']);
        $this->assertSame(
            'idempotent',
            $storage->discard(
                7,
                $identities['continuation_id'],
                $identities['generation_id'],
                new Date('2026-07-29 10:00:30', 'UTC')
            )
        );
        $this->assertNull(
            $storage->detect(7, 'com_example.record', 'record-42', new Date('2026-07-29 10:00:30', 'UTC'))
        );
    }

    /**
     * @testdox  does not reveal missing or foreign-owner draft identities
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOwnerIsolationUsesOnePrivateNotFoundOutcome(): void
    {
        $identities = $this->initialize();
        $storage    = $this->storage();

        foreach (
            [
                'foreign inspect' => fn () => $storage->inspect(
                    8,
                    $identities['continuation_id'],
                    $identities['generation_id'],
                    new Date('2026-07-29 10:00:10', 'UTC')
                ),
                'foreign preserve' => fn () => $this->preserve(
                    $storage,
                    $identities,
                    1,
                    ['value' => 'draft'],
                    1,
                    '2026-07-29 10:00:10',
                    ['userId' => 8]
                ),
                'missing discard' => fn () => $storage->discard(
                    7,
                    str_repeat('c', 64),
                    str_repeat('d', 64),
                    new Date('2026-07-29 10:00:10', 'UTC')
                ),
            ] as $operation
        ) {
            $this->assertAutosaveFailure('draft_not_found', $operation);
        }
    }

    /**
     * @testdox  expires at equality and preserves revision metadata while erasing payload
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInspectExpiresAtDeadlineEquality(): void
    {
        $identities = $this->initialize([], ['idle_ttl' => 10, 'max_lifetime' => 20]);
        $storage    = $this->storage(['idle_ttl' => 10, 'max_lifetime' => 20]);
        $this->preserve($storage, $identities, 1, ['value' => 'draft'], 1, '2026-07-29 10:00:05');

        $this->assertAutosaveFailure(
            'draft_expired',
            fn () => $storage->inspect(
                7,
                $identities['continuation_id'],
                $identities['generation_id'],
                new Date('2026-07-29 10:00:15', 'UTC')
            )
        );
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);

        $this->assertSame(GenerationState::Expired->value, $row['state']);
        $this->assertSame(1, (int) $row['client_revision']);
        $this->assertSame(1, (int) $row['payload_schema_version']);
        $this->assertNull($row['payload']);
        $this->assertNull($row['payload_digest']);
        $this->assertNull($row['quota_slot']);
    }

    /**
     * @testdox  caps renewed idle expiry at the generation hard lifetime
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveRenewalHonoursHardLifetime(): void
    {
        $identities = $this->initialize([], ['idle_ttl' => 60, 'max_lifetime' => 120]);
        $storage    = $this->storage(['idle_ttl' => 60, 'max_lifetime' => 120]);

        $this->preserve($storage, $identities, 1, ['value' => 'first'], 1, '2026-07-29 10:00:50');
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);
        $this->assertSame('2026-07-29 10:01:50', $row['expires_at']);

        $this->preserve($storage, $identities, 2, ['value' => 'second'], 1, '2026-07-29 10:01:40');
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);
        $this->assertSame('2026-07-29 10:02:00', $row['expires_at']);
    }

    /**
     * @testdox  does not replace a terminal generation for the same initialization key
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testTerminalInitializationRetryDoesNotCreateReplacement(): void
    {
        $identities = $this->initialize();
        $this->storage()->discard(
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            new Date('2026-07-29 10:00:10', 'UTC')
        );

        $this->assertAutosaveFailure('draft_terminal', fn () => $this->initialize());
        $this->assertSame(1, $this->countRows('#__autosave_continuations'));
        $this->assertSame(1, $this->countRows('#__autosave_generations'));
    }

    /**
     * @testdox  atomically stores the exact submitted snapshot and closes one generation idempotently
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalPreparationClosesGenerationIdempotently(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize();
        $payload    = ['title' => 'Submitted', 'articletext' => '<p>Exact</p>'];
        $arguments  = [
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            'com_example.record',
            'record-42',
            'revision-1',
            1,
            $payload,
            1,
            'apply',
            new Date('2026-07-29 10:00:10', 'UTC'),
        ];

        $first  = $storage->prepareCanonicalAction(...$arguments);
        $second = $storage->prepareCanonicalAction(...$arguments);
        $row    = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['operation_id']);
        $this->assertSame('pending', $first['outcome']);
        $this->assertSame(GenerationState::Closed->value, $row['state']);
        $this->assertSame(1, (int) $row['client_revision']);
        $this->assertSame($payload, json_decode($row['payload'], true));
        $this->assertNull($row['active_marker']);
        $this->assertNull($row['quota_slot']);
        $this->assertSame('2026-07-29 10:00:10', $row['closed_at']);
        $this->assertSame(1, $this->countRows('#__autosave_canonical_actions'));
    }

    /**
     * @testdox  rejects late preserves without mutating the closed snapshot
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testLatePreserveCannotMutateClosedCanonicalSnapshot(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize();
        $storage->prepareCanonicalAction(
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            'com_example.record',
            'record-42',
            'revision-1',
            1,
            ['value' => 'submitted'],
            1,
            'apply',
            new Date('2026-07-29 10:00:10', 'UTC')
        );

        $this->assertAutosaveFailure(
            'draft_terminal',
            fn () => $this->preserve(
                $storage,
                $identities,
                2,
                ['value' => 'late'],
                1,
                '2026-07-29 10:00:11'
            )
        );
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);
        $this->assertSame(['value' => 'submitted'], json_decode($row['payload'], true));
        $this->assertSame(1, (int) $row['client_revision']);
    }

    /**
     * @testdox  verified success retires only the prepared generation and exposes metadata-only outcome
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalSuccessRetiresPreparedGeneration(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize();
        $prepared   = $storage->prepareCanonicalAction(
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            'com_example.record',
            'record-42',
            'revision-1',
            1,
            ['value' => 'submitted'],
            1,
            'save-exit',
            new Date('2026-07-29 10:00:10', 'UTC')
        );

        $storage->verifyCanonicalAction(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            'save-exit',
            'revision-1',
            new Date('2026-07-29 10:00:11', 'UTC')
        );
        $first = $storage->finalizeCanonicalActionSuccess(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            'save-exit',
            'record-42',
            'revision-2',
            new Date('2026-07-29 10:00:12', 'UTC')
        );
        $second = $storage->finalizeCanonicalActionSuccess(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            'save-exit',
            'record-42',
            'revision-2',
            new Date('2026-07-29 10:00:13', 'UTC')
        );
        $outcome = $storage->inspectCanonicalAction(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            new Date('2026-07-29 10:00:14', 'UTC')
        );
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);

        $this->assertSame($first, $second);
        $this->assertSame('successful', $outcome['outcome']);
        $this->assertSame('record-42', $outcome['final_target_id']);
        $this->assertSame('revision-2', $outcome['final_base_revision']);
        $this->assertArrayNotHasKey('payload', $outcome);
        $this->assertSame(GenerationState::Retired->value, $row['state']);
        $this->assertNull($row['payload']);
        $this->assertNull($storage->detect(7, 'com_example.record', 'record-42', new Date('2026-07-29 10:00:14', 'UTC')));
    }

    /**
     * @testdox  definitive failure retains the immutable closed snapshot
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalFailureRetainsClosedSnapshot(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize();
        $prepared   = $storage->prepareCanonicalAction(
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            'com_example.record',
            'record-42',
            'revision-1',
            1,
            ['value' => 'submitted'],
            1,
            'apply',
            new Date('2026-07-29 10:00:10', 'UTC')
        );

        $storage->finalizeCanonicalActionFailure(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            'apply',
            'canonical_save_failed',
            new Date('2026-07-29 10:00:12', 'UTC')
        );
        $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);

        $this->assertSame(GenerationState::Closed->value, $row['state']);
        $this->assertSame(['value' => 'submitted'], json_decode($row['payload'], true));
        $outcome = $storage->inspectCanonicalAction(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            new Date('2026-07-29 10:00:13', 'UTC')
        );
        $this->assertSame('failed', $outcome['outcome']);
        $this->assertSame('canonical_save_failed', $outcome['failure_code']);
    }

    /**
     * @testdox  canonical preparation rejects foreign, mismatched, stale and invalid requests without closing the draft
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalPreparationRejectsInvalidBindingsWithoutMutation(): void
    {
        $storage    = $this->storage(['max_payload_bytes' => 64]);
        $identities = $this->initialize([], ['max_payload_bytes' => 64]);
        $prepare    = fn (array $changes = []) => $storage->prepareCanonicalAction(...array_values(array_replace([
                        'userId'         => 7,
                        'continuationId' => $identities['continuation_id'],
                        'generationId'   => $identities['generation_id'],
                        'context'        => 'com_example.record',
                        'targetId'       => 'record-42',
                        'baseRevision'   => 'revision-1',
                        'clientRevision' => 1,
                        'payload'        => ['value' => 'submitted'],
                        'schemaVersion'  => 1,
                        'intent'         => 'apply',
                        'now'            => new Date('2026-07-29 10:00:10', 'UTC'),
                    ], $changes)));
        $this->assertAutosaveFailure('draft_not_found', fn () => $prepare(['userId' => 8]));
        $this->assertAutosaveFailure('draft_not_found', fn () => $prepare(['continuationId' => str_repeat('a', 64)]));
        $this->assertAutosaveFailure('draft_not_found', fn () => $prepare(['generationId' => str_repeat('b', 64)]));
        $this->assertAutosaveFailure('draft_not_found', fn () => $prepare(['context' => 'com_example.other']));
        $this->assertAutosaveFailure('draft_not_found', fn () => $prepare(['targetId' => 'record-43']));
        $this->assertAutosaveFailure('base_revision_conflict', fn () => $prepare(['baseRevision' => 'revision-stale']));
        $this->assertAutosaveFailure('payload_too_large', fn () => $prepare(['payload' => ['value' => str_repeat('x', 100)]]));
        $this->expectException(\InvalidArgumentException::class);
        try {
            $prepare(['intent' => 'unsupported']);
        } finally {
            $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);
            $this->assertSame(GenerationState::Active->value, $row['state']);
            $this->assertNull($row['payload']);
            $this->assertSame(0, $this->countRows('#__autosave_canonical_actions'));
        }
    }

    /**
     * @testdox  a closed preparation accepts only the exact idempotent retry and a retired generation stays terminal
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalPreparationCannotRewriteClosedOrRetiredGeneration(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize();
        $arguments  = [
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            'com_example.record',
            'record-42',
            'revision-1',
            1,
            ['value' => 'submitted'],
            1,
            'apply',
            new Date('2026-07-29 10:00:10', 'UTC'),
        ];
        $prepared             = $storage->prepareCanonicalAction(...$arguments);
        $conflicting          = $arguments;
        $conflicting[7]       = ['value' => 'different'];
        $conflicting[10]      = new Date('2026-07-29 10:00:11', 'UTC');
        $this->assertAutosaveFailure('canonical_action_conflict', fn () => $storage->prepareCanonicalAction(...$conflicting));
        $storage->finalizeCanonicalActionSuccess(7, $prepared['operation_id'], 'com_example.record', 'record-42', 'apply', 'record-42', 'revision-2', new Date('2026-07-29 10:00:12', 'UTC'));
        $arguments[10] = new Date('2026-07-29 10:00:13', 'UTC');
        $this->assertAutosaveFailure('draft_terminal', fn () => $storage->prepareCanonicalAction(...$arguments));
    }

    /**
     * @testdox  canonical verification protects ownership, binding, intent, base, expiry and consumption
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalVerificationRejectsInvalidOrConsumedOperations(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize();
        $prepared   = $storage->prepareCanonicalAction(7, $identities['continuation_id'], $identities['generation_id'], 'com_example.record', 'record-42', 'revision-1', 1, ['value' => 'submitted'], 1, 'apply', new Date('2026-07-29 10:00:10', 'UTC'));
        $verify     = fn (array $changes = []) => $storage->verifyCanonicalAction(...array_values(array_replace([
                        'userId'              => 7,
                        'operationId'         => $prepared['operation_id'],
                        'context'             => 'com_example.record',
                        'targetId'            => 'record-42',
                        'intent'              => 'apply',
                        'currentBaseRevision' => 'revision-1',
                        'now'                 => new Date('2026-07-29 10:00:11', 'UTC'),
                    ], $changes)));
        $this->assertSame('pending', $verify()['outcome']);
        $this->assertAutosaveFailure('canonical_action_not_found', fn () => $verify(['userId' => 8]));
        $this->assertAutosaveFailure('canonical_action_not_found', fn () => $verify(['operationId' => str_repeat('a', 64)]));
        $this->assertAutosaveFailure('canonical_action_not_found', fn () => $verify(['targetId' => 'record-43']));
        $this->assertAutosaveFailure('canonical_intent_conflict', fn () => $verify(['intent' => 'save-exit']));
        $this->assertAutosaveFailure('base_revision_conflict', fn () => $verify(['currentBaseRevision' => 'revision-2']));
        $this->assertAutosaveFailure('canonical_action_consumed', fn () => $verify(['now' => new Date('2026-07-30 10:00:11', 'UTC')]));
        $outcome = $storage->inspectCanonicalAction(7, $prepared['operation_id'], 'com_example.record', 'record-42', new Date('2026-07-30 10:00:12', 'UTC'));
        $this->assertSame('unknown', $outcome['outcome']);
        $this->assertArrayNotHasKey('generation_state', $outcome);
        $this->assertArrayNotHasKey('payload', $outcome);
    }

    /**
     * @testdox  retiring one submitted generation leaves another tab's active generation untouched
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalSuccessIsolatedFromAnotherTab(): void
    {
        $storage  = $this->storage();
        $first    = $this->initialize();
        $second   = $this->initialize(['initializationKey' => 'initialization-2']);
        $prepared = $storage->prepareCanonicalAction(7, $first['continuation_id'], $first['generation_id'], 'com_example.record', 'record-42', 'revision-1', 1, ['value' => 'first tab'], 1, 'apply', new Date('2026-07-29 10:00:10', 'UTC'));
        $storage->finalizeCanonicalActionSuccess(7, $prepared['operation_id'], 'com_example.record', 'record-42', 'apply', 'record-42', 'revision-2', new Date('2026-07-29 10:00:12', 'UTC'));
        $firstRow  = $this->loadRow('#__autosave_generations', 'public_id', $first['generation_id']);
        $secondRow = $this->loadRow('#__autosave_generations', 'public_id', $second['generation_id']);
        $this->assertSame(GenerationState::Retired->value, $firstRow['state']);
        $this->assertSame(GenerationState::Active->value, $secondRow['state']);
        $this->assertSame(1, (int) $secondRow['active_marker']);
        $this->assertNotNull($secondRow['quota_slot']);
    }

    /**
     * @testdox  canonical prepare rolls back the close when operation insertion fails
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalPreparationRollsBackPartialState(): void
    {
        $storage      = $this->storage();
        $identities   = $this->initialize();
        $continuation = $this->loadRow('#__autosave_continuations', 'public_id', $identities['continuation_id']);
        $generation   = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);
        $fixture      = (object) [
            'public_id'              => str_repeat('c', 64),
            'user_id'                => 7,
            'continuation_id'        => (int) $continuation['id'],
            'generation_id'          => (int) $generation['id'],
            'context'                => 'com_example.record',
            'target_id'              => 'record-42',
            'intent'                 => 'apply',
            'expected_base_revision' => 'revision-1',
            'outcome'                => 'pending',
            'created_at'             => '2026-07-29 10:00:00',
            'updated_at'             => '2026-07-29 10:00:00',
            'expires_at'             => '2026-07-29 10:02:00',
        ];
        $this->getDBDriver()->insertObject('#__autosave_canonical_actions', $fixture);
        try {
            $storage->prepareCanonicalAction(7, $identities['continuation_id'], $identities['generation_id'], 'com_example.record', 'record-42', 'revision-1', 1, ['value' => 'submitted'], 1, 'apply', new Date('2026-07-29 10:00:10', 'UTC'));
            $this->fail('The duplicate operation constraint did not reject the partial prepare.');
        } catch (ExecutionFailureException) {
            $row = $this->loadRow('#__autosave_generations', 'public_id', $identities['generation_id']);
            $this->assertSame(GenerationState::Active->value, $row['state']);
            $this->assertSame(0, (int) $row['client_revision']);
            $this->assertNull($row['payload']);
            $this->assertSame(1, $this->countRows('#__autosave_canonical_actions'));
        }
    }

    /**
     * @testdox  retirement failure rolls back success metadata and preserves the terminal write fence
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalRetirementFailureLeavesTruthfulPendingOutcome(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize();
        $prepared   = $storage->prepareCanonicalAction(7, $identities['continuation_id'], $identities['generation_id'], 'com_example.record', 'record-42', 'revision-1', 1, ['value' => 'submitted'], 1, 'apply', new Date('2026-07-29 10:00:10', 'UTC'));
        $retired    = GenerationState::Retired->value;
        $query      = $this->getDBDriver()->createQuery()
            ->update($this->getDBDriver()->quoteName('#__autosave_generations'))
            ->set($this->getDBDriver()->quoteName('state') . ' = :retired')
            ->where($this->getDBDriver()->quoteName('public_id') . ' = :generation_id')
            ->bind(':retired', $retired)
            ->bind(':generation_id', $identities['generation_id']);
        $this->getDBDriver()->setQuery($query)->execute();
        $this->expectException(\RuntimeException::class);
        try {
            $storage->finalizeCanonicalActionSuccess(7, $prepared['operation_id'], 'com_example.record', 'record-42', 'apply', 'record-42', 'revision-2', new Date('2026-07-29 10:00:12', 'UTC'));
        } finally {
            $outcome = $storage->inspectCanonicalAction(7, $prepared['operation_id'], 'com_example.record', 'record-42', new Date('2026-07-29 10:00:13', 'UTC'));
            $this->assertSame('pending', $outcome['outcome']);
            $this->assertNull($outcome['final_target_id']);
            $this->assertNull($outcome['final_base_revision']);
            $this->assertAutosaveFailure('draft_terminal', fn () => $this->preserve($storage, $identities, 2, ['value' => 'late'], 1, '2026-07-29 10:00:14'));
        }
    }
    /**
     * Create storage with the complete test policy.
     *
     * @param   array  $policy  Policy replacements.
     *
     * @return  AutosaveStorage
     *
     * @since   __DEPLOY_VERSION__
     */
    private function storage(array $policy = []): AutosaveStorage
    {
        return new AutosaveStorage(
            $this->getDBDriver(),
            array_replace(
                [
                    'idle_ttl'               => 60,
                    'max_lifetime'           => 120,
                    'tombstone_retention'    => 300,
                    'max_active_generations' => 2,
                    'max_payload_bytes'      => 1048576,
                ],
                $policy
            )
        );
    }

    /**
     * Preserve a payload with optional binding replacements.
     *
     * @param   AutosaveStorage  $storage     The storage under test.
     * @param   array            $identities  The continuation and generation public IDs.
     * @param   integer          $revision    The incoming client revision.
     * @param   array            $payload     The normalized payload.
     * @param   integer          $schema      The payload schema version.
     * @param   string           $now         The UTC operation time.
     * @param   array            $changes     Binding replacements.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function preserve(
        AutosaveStorage $storage,
        array $identities,
        int $revision,
        array $payload,
        int $schema,
        string $now,
        array $changes = []
    ): string {
        $arguments = array_replace(
            [
                'userId'         => 7,
                'continuationId' => $identities['continuation_id'],
                'generationId'   => $identities['generation_id'],
                'context'        => 'com_example.record',
                'targetId'       => 'record-42',
                'baseRevision'   => 'revision-1',
                'clientRevision' => $revision,
                'payload'        => $payload,
                'schemaVersion'  => $schema,
                'now'            => new Date($now, 'UTC'),
            ],
            $changes
        );

        return $storage->preserve(...array_values($arguments));
    }

    /**
     * Assert a transport-neutral Autosave failure identifier.
     *
     * @param   string    $code       The expected stable identifier.
     * @param   callable  $operation  The operation expected to fail.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function assertAutosaveFailure(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('The expected Autosave domain failure was not raised.');
        } catch (AutosaveException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
        }
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
                'idle_ttl'               => 60,
                'max_lifetime'           => 120,
                'tombstone_retention'    => 300,
                'max_active_generations' => 2,
                'max_payload_bytes'      => 1048576,
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
            'continuation_id'        => ParameterType::INTEGER,
            'user_id'                => ParameterType::INTEGER,
            'client_revision'        => ParameterType::INTEGER,
            'payload_schema_version' => ParameterType::INTEGER,
            'active_marker'          => ParameterType::INTEGER,
            'quota_slot'             => ParameterType::INTEGER,
        ];
        $query      = $this->getDBDriver()->createQuery()
            ->insert($this->getDBDriver()->quoteName('#__autosave_generations'));

        foreach ($generation as $column => &$value) {
            $parameter    = ':' . $column;
            $columns[]    = $this->getDBDriver()->quoteName($column);
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
