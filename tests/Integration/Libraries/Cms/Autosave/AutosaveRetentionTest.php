<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveStorage;
use Joomla\CMS\Autosave\GenerationState;
use Joomla\CMS\Date\Date;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Database integration tests for bounded Autosave retention cleanup.
 *
 * @package     Joomla.IntegrationTest
 * @subpackage  Autosave
 *
 * @testdox     Autosave retention cleanup
 *
 * @since       __DEPLOY_VERSION__
 */
class AutosaveRetentionTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    /**
     * Prepare empty Autosave tables for each test.
     *
     * @since  __DEPLOY_VERSION__
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
     * @since  __DEPLOY_VERSION__
     */
    public function getSchemasToLoad(): array
    {
        return ['autosave.sql'];
    }

    /**
     * @testdox active and recoverable drafts and recent tombstones are retained
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testIneligibleGenerationsAreRetained(): void
    {
        $storage     = $this->storage();
        $active      = $this->initialize($storage, 'active');
        $recoverable = $this->initialize($storage, 'recoverable');
        $this->preserve($storage, $recoverable, '2026-08-13 10:00:01');
        $discarded = $this->initialize($storage, 'discarded');
        $storage->discard(
            7,
            $discarded['continuation_id'],
            $discarded['generation_id'],
            new Date('2026-08-13 10:00:02', 'UTC')
        );

        $result = $storage->purgeRetainedData(new Date('2026-08-13 10:00:09', 'UTC'), 10);

        $this->assertSame($this->emptyResult(), $result);
        $this->assertSame(3, $this->countRows('#__autosave_generations'));
        $this->assertSame(GenerationState::Active->value, $this->loadGeneration($active['generation_id'])['state']);
        $this->assertNotNull($this->loadGeneration($recoverable['generation_id'])['payload']);
        $this->assertSame(GenerationState::Discarded->value, $this->loadGeneration($discarded['generation_id'])['state']);
    }

    /**
     * @testdox dormant recoverable drafts expire globally and are removed only after retention
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testDormantGenerationCompletesPhysicalLifecycle(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize($storage, 'dormant');
        $this->preserve($storage, $identities, '2026-08-13 10:00:01');

        $expired = $storage->purgeRetainedData(new Date('2026-08-13 10:00:12', 'UTC'), 10);
        $row     = $this->loadGeneration($identities['generation_id']);

        $this->assertSame(1, $expired['generations_expired']);
        $this->assertSame(GenerationState::Expired->value, $row['state']);
        $this->assertNull($row['payload']);
        $this->assertNull($row['payload_digest']);
        $this->assertSame('2026-08-13 10:00:42', $row['retain_until']);
        $this->assertSame(1, $this->countRows('#__autosave_continuations'));

        $withinRetention = $storage->purgeRetainedData(new Date('2026-08-13 10:00:41', 'UTC'), 10);
        $this->assertSame($this->emptyResult(), $withinRetention);

        $elapsed = $storage->purgeRetainedData(new Date('2026-08-13 10:00:42', 'UTC'), 10);
        $this->assertSame(1, $elapsed['generations_deleted']);
        $this->assertSame(1, $elapsed['continuations_deleted']);
        $this->assertSame(0, $this->countRows('#__autosave_generations'));
        $this->assertSame(0, $this->countRows('#__autosave_continuations'));
    }

    /**
     * @testdox canonical operation expiry protects closed payloads and successful tombstones until its boundary
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testCanonicalActionRetentionIsNotShortened(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize($storage, 'successful');
        $prepared   = $this->prepare($storage, $identities);
        $storage->finalizeCanonicalActionSuccess(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            'apply',
            'record-42',
            'revision-2',
            new Date('2026-08-13 10:00:12', 'UTC')
        );

        $recent = $storage->purgeRetainedData(new Date('2026-08-13 10:00:18', 'UTC'), 10);
        $this->assertSame($this->emptyResult(), $recent);
        $this->assertSame(1, $this->countRows('#__autosave_canonical_actions'));
        $this->assertSame(1, $this->countRows('#__autosave_generations'));

        $expired = $storage->purgeRetainedData(new Date('2026-08-13 10:00:19', 'UTC'), 10);
        $this->assertSame(1, $expired['canonical_actions_deleted']);
        $this->assertSame(0, $expired['generations_deleted']);
        $this->assertSame(0, $expired['continuations_deleted']);

        $retained = $storage->purgeRetainedData(new Date('2026-08-13 10:00:42', 'UTC'), 10);
        $this->assertSame(1, $retained['generations_deleted']);
        $this->assertSame(1, $retained['continuations_deleted']);
    }

    /**
     * @testdox failed canonical snapshots remain available until operation expiry and are then removed
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testFailedCanonicalSnapshotIsReleasedAtOperationExpiry(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize($storage, 'failed');
        $prepared   = $this->prepare($storage, $identities);
        $storage->finalizeCanonicalActionFailure(
            7,
            $prepared['operation_id'],
            'com_example.record',
            'record-42',
            'apply',
            'validation_failed',
            new Date('2026-08-13 10:00:12', 'UTC')
        );

        $storage->purgeRetainedData(new Date('2026-08-13 10:00:18', 'UTC'), 10);
        $this->assertNotNull($this->loadGeneration($identities['generation_id'])['payload']);

        $result = $storage->purgeRetainedData(new Date('2026-08-13 10:00:19', 'UTC'), 10);
        $this->assertSame(1, $result['closed_generations_released']);
        $this->assertSame(1, $result['canonical_actions_deleted']);
        $this->assertSame(1, $result['generations_deleted']);
        $this->assertSame(0, $result['continuations_deleted']);
        $this->assertSame(0, $this->countRows('#__autosave_canonical_actions'));

        $retained = $storage->purgeRetainedData(new Date('2026-08-13 10:00:42', 'UTC'), 10);
        $this->assertSame(1, $retained['continuations_deleted']);
    }

    /**
     * @testdox an expired pending operation and its closed snapshot are removed without owner activity
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testExpiredPendingCanonicalActionIsRemoved(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize($storage, 'pending');
        $this->prepare($storage, $identities);

        $recent = $storage->purgeRetainedData(new Date('2026-08-13 10:00:18', 'UTC'), 10);
        $this->assertSame($this->emptyResult(), $recent);
        $this->assertSame(1, $this->countRows('#__autosave_canonical_actions'));

        $result = $storage->purgeRetainedData(new Date('2026-08-13 10:00:19', 'UTC'), 10);

        $this->assertSame(1, $result['closed_generations_released']);
        $this->assertSame(1, $result['canonical_actions_deleted']);
        $this->assertSame(0, $this->countRows('#__autosave_canonical_actions'));
        $this->assertSame(0, $this->countRows('#__autosave_generations'));
    }

    /**
     * @testdox an unknown canonical outcome remains retained until its authoritative expiry
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testRecentUnknownCanonicalActionIsRetained(): void
    {
        $storage    = $this->storage();
        $identities = $this->initialize($storage, 'unknown');
        $operation  = $this->prepare($storage, $identities);
        $this->setCanonicalState($operation['operation_id'], 'unknown', '2026-08-13 10:00:30');

        $recent = $storage->purgeRetainedData(new Date('2026-08-13 10:00:20', 'UTC'), 10);

        $this->assertSame($this->emptyResult(), $recent);
        $this->assertSame(1, $this->countRows('#__autosave_canonical_actions'));
        $this->assertNotNull($this->loadGeneration($identities['generation_id'])['payload']);

        $expired = $storage->purgeRetainedData(new Date('2026-08-13 10:00:30', 'UTC'), 10);
        $this->assertSame(1, $expired['canonical_actions_deleted']);
        $this->assertSame(1, $expired['generations_deleted']);
        $this->assertSame(1, $expired['continuations_deleted']);
    }

    /**
     * @testdox physical deletion is bounded, progresses on later runs and is idempotent
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testCleanupIsBoundedProgressiveAndIdempotent(): void
    {
        $storage = $this->storage();

        foreach (['one', 'two', 'three'] as $key) {
            $identities = $this->initialize($storage, $key);
            $storage->discard(
                7,
                $identities['continuation_id'],
                $identities['generation_id'],
                new Date('2026-08-13 10:00:01', 'UTC')
            );
        }

        do {
            $result  = $storage->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 3);
            $deleted = $result['canonical_actions_deleted']
                + $result['generations_deleted']
                + $result['continuations_deleted'];
            $this->assertLessThanOrEqual(3, $deleted);
        } while ($deleted > 0);

        $this->assertSame(0, $this->countRows('#__autosave_generations'));
        $this->assertSame(0, $this->countRows('#__autosave_continuations'));
        $this->assertSame(
            $this->emptyResult(),
            $storage->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 3)
        );
    }

    /**
     * @testdox old orphan continuations are removed while recent empty rows are retained
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testOnlyOldOrphanContinuationsAreRemoved(): void
    {
        $this->insertOrphan('old', '2026-08-13 09:59:00');
        $this->insertOrphan('recent', '2026-08-13 10:00:00');

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:20', 'UTC'), 10);

        $this->assertSame(1, $result['continuations_deleted']);
        $this->assertSame(['recent'], $this->loadContinuationKeys());
    }

    /**
     * @testdox canonical-only cleanup reuses the complete physical deletion budget
     *
     * @dataProvider cleanupLimitProvider
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testCanonicalOnlyCleanupReusesBudget(int $limit): void
    {
        for ($index = 0; $index <= $limit; $index++) {
            $this->insertCanonicalFixture('canonical-' . $index);
        }

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), $limit);

        $this->assertSame($limit, $result['canonical_actions_deleted']);
        $this->assertSame(0, $result['generations_deleted']);
        $this->assertSame(0, $result['continuations_deleted']);
        $this->assertSame($limit, $this->physicalDeletes($result));
    }

    /**
     * @testdox generation-only cleanup reuses the complete physical deletion budget
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testGenerationOnlyCleanupReusesBudget(): void
    {
        $continuationId = $this->insertOrphan('generation-parent', '2026-08-13 10:00:31');

        for ($index = 0; $index <= 100; $index++) {
            $this->insertGeneration('generation-' . $index, $continuationId);
        }

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 100);

        $this->assertSame(0, $result['canonical_actions_deleted']);
        $this->assertSame(100, $result['generations_deleted']);
        $this->assertSame(0, $result['continuations_deleted']);
        $this->assertSame(100, $this->physicalDeletes($result));
    }

    /**
     * @testdox continuation-only cleanup uses the complete physical deletion budget
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testContinuationOnlyCleanupUsesBudget(): void
    {
        for ($index = 0; $index <= 100; $index++) {
            $this->insertOrphan('continuation-' . $index, '2026-08-13 09:59:00');
        }

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 100);

        $this->assertSame(0, $result['canonical_actions_deleted']);
        $this->assertSame(0, $result['generations_deleted']);
        $this->assertSame(100, $result['continuations_deleted']);
        $this->assertSame(100, $this->physicalDeletes($result));
    }

    /**
     * @testdox a saturated workload preserves first-pass fairness and the global bound
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testMixedSaturatedCleanupRemainsFairAndBounded(): void
    {
        $generationParent = $this->insertOrphan('mixed-generation-parent', '2026-08-13 10:00:31');

        for ($index = 0; $index <= 100; $index++) {
            $this->insertCanonicalFixture('mixed-canonical-' . $index);
            $this->insertGeneration('mixed-generation-' . $index, $generationParent);
            $this->insertOrphan('mixed-continuation-' . $index, '2026-08-13 09:59:00');
        }

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 100);

        $this->assertSame(33, $result['canonical_actions_deleted']);
        $this->assertSame(33, $result['generations_deleted']);
        $this->assertSame(34, $result['continuations_deleted']);
        $this->assertSame(100, $this->physicalDeletes($result));
    }

    /**
     * @testdox sparse canonical work leaves its unused share available to generations
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testSparseCanonicalBudgetFlowsToGenerations(): void
    {
        $generationParent = $this->insertOrphan('partial-generation-parent', '2026-08-13 10:00:31');

        for ($index = 0; $index < 10; $index++) {
            $this->insertCanonicalFixture('partial-canonical-' . $index);
        }

        for ($index = 0; $index <= 100; $index++) {
            $this->insertGeneration('partial-generation-' . $index, $generationParent);
        }

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 100);

        $this->assertSame(10, $result['canonical_actions_deleted']);
        $this->assertSame(90, $result['generations_deleted']);
        $this->assertSame(0, $result['continuations_deleted']);
        $this->assertSame(100, $this->physicalDeletes($result));
    }

    /**
     * @testdox unused generation capacity remains available to orphan continuations
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testUnusedGenerationBudgetFlowsToContinuations(): void
    {
        for ($index = 0; $index < 50; $index++) {
            $this->insertCanonicalFixture('continuation-canonical-' . $index);
        }

        for ($index = 0; $index <= 100; $index++) {
            $this->insertOrphan('partial-continuation-' . $index, '2026-08-13 09:59:00');
        }

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 100);

        $this->assertSame(33, $result['canonical_actions_deleted']);
        $this->assertSame(0, $result['generations_deleted']);
        $this->assertSame(67, $result['continuations_deleted']);
        $this->assertSame(100, $this->physicalDeletes($result));
    }

    /**
     * @testdox dependency order can remove an action, its generation and its continuation in one run
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testCleanupProgressesThroughDependencyChain(): void
    {
        $continuationId = $this->insertOrphan('dependency-chain', '2026-08-13 09:59:00');
        $generationId   = $this->insertGeneration('dependency-chain', $continuationId, 'closed', null);
        $this->insertCanonicalAction('dependency-chain', $continuationId, $generationId);

        $result = $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:31', 'UTC'), 3);

        $this->assertSame(1, $result['canonical_actions_deleted']);
        $this->assertSame(1, $result['generations_deleted']);
        $this->assertSame(1, $result['continuations_deleted']);
        $this->assertSame(3, $this->physicalDeletes($result));
    }

    /**
     * @testdox invalid cleanup limits fail before opening a transaction
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testCleanupLimitIsStrictlyBounded(): void
    {
        foreach ([0, -1, 2, 1001] as $limit) {
            try {
                $this->storage()->purgeRetainedData(new Date('2026-08-13 10:00:00', 'UTC'), $limit);
                $this->fail('The invalid cleanup limit was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('The Autosave cleanup limit is invalid.', $exception->getMessage());
            }
        }
    }

    /**
     * Create storage with short deterministic retention windows.
     */
    private function storage(): AutosaveStorage
    {
        return new AutosaveStorage(
            $this->getDBDriver(),
            [
                'idle_ttl'               => 10,
                'max_lifetime'           => 20,
                'tombstone_retention'    => 30,
                'max_active_generations' => 20,
                'max_payload_bytes'      => 1048576,
            ]
        );
    }

    /**
     * Initialize one fixture generation.
     */
    private function initialize(AutosaveStorage $storage, string $key): array
    {
        return $storage->initialize(
            7,
            'com_example.record',
            'record-42',
            'revision-1',
            $key,
            new Date('2026-08-13 10:00:00', 'UTC')
        );
    }

    /**
     * Preserve one recoverable payload.
     */
    private function preserve(AutosaveStorage $storage, array $identities, string $now): void
    {
        $storage->preserve(
            7,
            $identities['continuation_id'],
            $identities['generation_id'],
            'com_example.record',
            'record-42',
            'revision-1',
            1,
            ['value' => 'private draft'],
            1,
            new Date($now, 'UTC')
        );
    }

    /**
     * Prepare one canonical action.
     */
    private function prepare(AutosaveStorage $storage, array $identities): array
    {
        return $storage->prepareCanonicalAction(
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
            new Date('2026-08-13 10:00:09', 'UTC')
        );
    }

    /**
     * Load one generation fixture.
     */
    private function loadGeneration(string $publicId): array
    {
        $query = $this->getDBDriver()->createQuery()
            ->select('*')
            ->from($this->getDBDriver()->quoteName('#__autosave_generations'))
            ->where($this->getDBDriver()->quoteName('public_id') . ' = :public_id')
            ->bind(':public_id', $publicId);

        return $this->getDBDriver()->setQuery($query)->loadAssoc();
    }

    /**
     * Count rows in a table.
     */
    private function countRows(string $table): int
    {
        $query = $this->getDBDriver()->createQuery()
            ->select('COUNT(*)')
            ->from($this->getDBDriver()->quoteName($table));

        return (int) $this->getDBDriver()->setQuery($query)->loadResult();
    }

    /**
     * Insert one orphan continuation fixture.
     */
    private function insertOrphan(string $key, string $activity): int
    {
        $row = (object) [
            'public_id'          => hash('sha256', 'continuation-' . $key),
            'user_id'            => 7,
            'context'            => 'com_example.record',
            'target_id'          => 'record-42',
            'initialization_key' => $key,
            'created_at'         => $activity,
            'last_activity_at'   => $activity,
        ];
        $this->getDBDriver()->insertObject('#__autosave_continuations', $row, 'id');

        return (int) $row->id;
    }

    /**
     * Insert one terminal generation fixture.
     */
    private function insertGeneration(
        string $key,
        int $continuationId,
        string $state = 'retired',
        ?string $retainUntil = '2026-08-13 10:00:00'
    ): int {
        $row = (object) [
            'public_id'       => hash('sha256', 'generation-' . $key),
            'continuation_id' => $continuationId,
            'user_id'         => 7,
            'base_revision'   => 'revision-1',
            'state'           => $state,
            'client_revision' => 1,
            'created_at'      => '2026-08-13 09:59:00',
            'updated_at'      => '2026-08-13 09:59:00',
            'expires_at'      => '2026-08-13 10:00:00',
            'terminal_at'     => '2026-08-13 09:59:00',
            'retain_until'    => $retainUntil,
        ];
        $this->getDBDriver()->insertObject('#__autosave_generations', $row, 'id');

        return (int) $row->id;
    }

    /**
     * Insert one expired canonical action whose retained generation is not yet deletable.
     */
    private function insertCanonicalFixture(string $key): void
    {
        $continuationId = $this->insertOrphan($key, '2026-08-13 10:00:31');
        $generationId   = $this->insertGeneration($key, $continuationId, 'retired', '2026-08-13 11:00:00');
        $this->insertCanonicalAction($key, $continuationId, $generationId);
    }

    /**
     * Insert one expired canonical action fixture.
     */
    private function insertCanonicalAction(string $key, int $continuationId, int $generationId): void
    {
        $row = (object) [
            'public_id'              => hash('sha256', 'operation-' . $key),
            'user_id'                => 7,
            'continuation_id'        => $continuationId,
            'generation_id'          => $generationId,
            'context'                => 'com_example.record',
            'target_id'              => 'record-42',
            'intent'                 => 'apply',
            'expected_base_revision' => 'revision-1',
            'outcome'                => 'successful',
            'created_at'             => '2026-08-13 09:59:00',
            'updated_at'             => '2026-08-13 09:59:00',
            'expires_at'             => '2026-08-13 10:00:00',
            'completed_at'           => '2026-08-13 09:59:00',
        ];
        $this->getDBDriver()->insertObject('#__autosave_canonical_actions', $row);
    }

    /**
     * Return supported cleanup limits for capacity-bound tests.
     *
     * @return  array<string, array{int}>
     */
    public function cleanupLimitProvider(): array
    {
        return [
            'minimum' => [3],
            'default' => [100],
            'maximum' => [1000],
        ];
    }

    /**
     * Count physical rows deleted by one cleanup result.
     */
    private function physicalDeletes(array $result): int
    {
        return $result['canonical_actions_deleted']
            + $result['generations_deleted']
            + $result['continuations_deleted'];
    }

    /**
     * Set canonical metadata directly to exercise retention independently of owner-request transitions.
     */
    private function setCanonicalState(string $operationId, string $outcome, string $expiresAt): void
    {
        $query = $this->getDBDriver()->createQuery()
            ->update($this->getDBDriver()->quoteName('#__autosave_canonical_actions'))
            ->set($this->getDBDriver()->quoteName('outcome') . ' = :outcome')
            ->set($this->getDBDriver()->quoteName('expires_at') . ' = :expires_at')
            ->where($this->getDBDriver()->quoteName('public_id') . ' = :public_id')
            ->bind(':outcome', $outcome)
            ->bind(':expires_at', $expiresAt)
            ->bind(':public_id', $operationId);
        $this->getDBDriver()->setQuery($query)->execute();
    }

    /**
     * Load remaining continuation initialization keys.
     */
    private function loadContinuationKeys(): array
    {
        $query = $this->getDBDriver()->createQuery()
            ->select($this->getDBDriver()->quoteName('initialization_key'))
            ->from($this->getDBDriver()->quoteName('#__autosave_continuations'))
            ->order($this->getDBDriver()->quoteName('id') . ' ASC');

        return $this->getDBDriver()->setQuery($query)->loadColumn();
    }

    /**
     * Return the zero-work cleanup result.
     */
    private function emptyResult(): array
    {
        return [
            'generations_expired'         => 0,
            'closed_generations_released' => 0,
            'canonical_actions_deleted'   => 0,
            'generations_deleted'         => 0,
            'continuations_deleted'       => 0,
        ];
    }
}
