<?php

/**
 * @package   Joomla.IntegrationTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\QueryMonitorInterface;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Native-engine storage, contention and lifecycle integrity.
 */
class DraftStoreTest extends IntegrationTestCase implements DBTestInterface
{
    use DraftStoreCases;

    private DatabaseInterface $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->getDBDriver();
        $this->initializeStore();
    }

    protected function tearDown(): void
    {
        $this->db->setMonitor(null);
        parent::tearDown();
    }

    private function peer(): DatabaseInterface
    {
        return (new DatabaseFactory())->getDriver(
            getenv('JTEST_DB_ENGINE') ?: JTEST_DB_ENGINE,
            [
            'database' => getenv('JTEST_DB_NAME') ?: JTEST_DB_NAME,
            'host'     => getenv('JTEST_DB_HOST') ?: JTEST_DB_HOST,
            'user'     => getenv('JTEST_DB_USER') ?: JTEST_DB_USER,
            'password' => getenv('JTEST_DB_PASSWORD') ?: JTEST_DB_PASSWORD,
            'prefix'   => $this->db->getPrefix(),
            ]
        );
    }

    /**
     * Run a peer write immediately before a selected real SQL statement, without timing sleeps.
     */
    private function beforeStatement(string $prefix, callable $operation): void
    {
        $this->db->setMonitor(
            new class ($prefix, $operation) implements QueryMonitorInterface {
                private bool $called = false;

                public function __construct(private string $prefix, private $operation)
                {
                }

                public function startQuery(string $sql, ?array $boundParams = null): void
                {
                    if (!$this->called && str_starts_with($sql, $this->prefix)) {
                        $this->called = true;
                        ($this->operation)();
                    }
                }

                public function stopQuery(): void
                {
                }
            }
        );
    }

    public function testProviderWorkDoesNotHoldAnOwnerLockAndNewerContentWins(): void
    {
        $draft  = $this->store->open(7, $this->descriptor(), $this->now);
        $peer   = new DraftStore($this->peer());
        $called = false;
        $this->failure(
            'revision_conflict',
            fn () => $this->store->preserve(
                7,
                $draft['draft_id'],
                1,
                ['title' => 'stale'],
                $this->now,
                function ($row, $payload) use ($peer, $draft, &$called) {
                    if (!$called) {
                        $called = true;
                        $peer->preserve(7, $draft['draft_id'], 2, ['title' => 'newer'], $this->now, static fn ($binding, $values) => $values);
                    }
                    return $payload;
                }
            )
        );
        $this->assertSame(['title' => 'newer'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    public function testDiscardDuringValidationCannotBeRecreated(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $peer  = new DraftStore($this->peer());
        $this->failure(
            'draft_not_found',
            fn () => $this->store->preserve(
                7,
                $draft['draft_id'],
                1,
                ['title' => 'late'],
                $this->now,
                function ($row, $payload) use ($peer, $draft) {
                    $peer->discard(7, $draft['draft_id']);
                    return $payload;
                }
            )
        );
        $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
    }

    public function testQuotaDiscardRemovesNewerValuesButCannotDeleteAReplacementIdentity(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'listed work');
        $id   = $this->store->allocations(7)[0]['draft_id'];
        $peer = new DraftStore($this->peer());
        $peer->preserve(7, $id, 2, ['title' => 'new work since selection'], $this->now, static fn ($binding, $values) => $values);
        $this->store->discard(7, $id);
        $replacement = $peer->open(7, $this->descriptor(), $this->now);
        $this->assertNotSame($id, $replacement['draft_id']);
        $this->failure('draft_not_found', fn () => $this->store->discard(7, $id));
        $this->failure('draft_not_found', fn () => $this->preserve($draft, 3, 'late work'));
        $this->preserve($replacement, 1, 'replacement work');
        $this->assertSame(['title' => 'replacement work'], $peer->read(7, $replacement['draft_id'], $this->now)['payload']);
    }

    public function testQuotaDiscardRetriesAConcurrentPreserveWithoutRecreatingTheEntry(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'selected work');
        $peer = new DraftStore($this->peer());
        $this->beforeStatement(
            'DELETE',
            function () use ($peer, $draft): void {
                $peer->preserve(7, $draft['draft_id'], 2, ['title' => 'concurrent work'], $this->now, static fn ($binding, $values) => $values);
            }
        );
        $this->store->discard(7, $draft['draft_id']);
        $this->failure('draft_not_found', fn () => $this->store->read(7, $draft['draft_id'], $this->now));
    }

    public function testCompetingAllocationCannotClaimCapacityUntilQuotaDiscardCompletes(): void
    {
        for ($index = 0; $index < DraftStore::MAX_OWNER_ROWS; $index++) {
            $draft = $this->store->open(7, $this->descriptor(), $this->now);
        }
        $peer = new DraftStore($this->peer());
        $this->beforeStatement(
            'DELETE',
            function () use ($peer): void {
                $this->failure('draft_limit_reached', fn () => $peer->open(7, $this->descriptor(), $this->now));
            }
        );
        $this->store->discard(7, $draft['draft_id']);
        $winner = $peer->open(7, $this->descriptor(), $this->now);
        $this->assertNotSame($draft['draft_id'], $winner['draft_id']);
        $this->failure('draft_limit_reached', fn () => $this->store->open(7, $this->descriptor(), $this->now));
        $this->assertCount(DraftStore::MAX_OWNER_ROWS, $this->store->allocations(7));
    }

    public function testBindingChangeWithEqualClientAndFloorCannotBeAcknowledged(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'same payload');
        $peer   = $this->peer();
        $called = false;
        $this->failure(
            'revision_conflict',
            fn () => $this->store->preserve(
                7,
                $draft['draft_id'],
                1,
                ['title' => 'same payload'],
                $this->now,
                function ($row, $payload) use ($peer, $draft, &$called) {
                    if (!$called) {
                        $called = true;
                        $peer->setQuery(
                            'UPDATE #__autosave_drafts SET baseline_revision = ' . $peer->quote('changed')
                            . ', row_version = row_version + 1 WHERE draft_id = ' . $peer->quote($draft['draft_id'])
                        )->execute();
                    }
                    return $payload;
                }
            )
        );
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame('changed', $row['baseline_revision']);
        $this->assertSame(1, $row['client_revision']);
        $this->assertSame(0, $row['retired_revision']);
    }

    public function testRetirementDuringPreserveKeepsTheSubmittedFloor(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $peer  = new DraftStore($this->peer());
        $this->failure(
            'draft_retired',
            fn () => $this->store->preserve(
                7,
                $draft['draft_id'],
                1,
                ['title' => 'submitted'],
                $this->now,
                function ($row, $payload) use ($peer, $draft) {
                    $peer->reconcile(7, $draft['draft_id'], 1, 'com_content.article', '42', [], 'save', '42', null, $this->now);
                    return $payload;
                }
            )
        );
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame(1, $row['retired_revision']);
        $this->assertNull($row['payload']);
        $this->preserve($draft, 2, 'later');
        $this->assertSame(['title' => 'later'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    public function testCompatibleExpiredEmptyRowGetsAFreshIdentity(): void
    {
        $first = $this->store->open(7, $this->descriptor(null), $this->now);
        $live  = $this->store->open(7, $this->descriptor(null), $this->now);
        $this->assertNotSame($first['draft_id'], $live['draft_id']);
        $this->preserve($live, 1, 'keep live work');
        $later = new Date('2026-10-12 00:00:00', 'UTC');
        $new   = $this->store->open(7, $this->descriptor(null), $later);
        $this->assertSame($first['owner_slot'], $new['owner_slot']);
        $this->assertSame($first['id'], $new['id'], 'Compatible expiry reuses the physical row.');
        $this->assertNotSame($first['draft_id'], $new['draft_id']);
        $this->assertSame(1, $new['row_version']);
        $this->assertSame('2026-10-13 00:00:00', $new['expires_at']);
        $this->failure('draft_not_found', fn () => $this->preserve($first, 1, 'old request', $later));
        $this->assertSame(['title' => 'keep live work'], $this->store->read(7, $live['draft_id'], $later)['payload']);
    }

    /**
     * @dataProvider absentRecoverySources
     */
    public function testMissingOrExpiredSourceCannotBlockCurrentRetirement(bool $purged): void
    {
        $source = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($source, 1, 'restored');
        $later = new Date('2026-10-19 00:00:00', 'UTC');
        if ($purged) {
            $this->store->purge($later);
        }
        $current = $this->store->open(7, $this->descriptor(), $later);
        $this->preserve($current, 1, 'submitted', $later);
        $this->store->reconcile(
            7,
            $current['draft_id'],
            1,
            'com_content.article',
            '42',
            [],
            'save',
            '42',
            null,
            $later,
            ['draft_id' => $source['draft_id'], 'revision' => 1]
        );
        $this->assertNull($this->store->read(7, $current['draft_id'], $later)['payload']);
        $this->assertSame(1, $this->store->read(7, $current['draft_id'], $later)['retired_revision']);
    }

    public static function absentRecoverySources(): array
    {
        return [[false], [true]];
    }

    public function testCleanupDiscoveryCannotDeleteARecycledRow(): void
    {
        $old         = $this->store->open(7, $this->descriptor(), $this->now);
        $later       = new Date('2026-10-12 00:00:00', 'UTC');
        $peer        = new DraftStore($this->peer());
        $replacement = null;
        $this->beforeStatement(
            'DELETE',
            function () use ($peer, $later, &$replacement) {
                $replacement = $peer->open(7, $this->descriptor(), $later);
            }
        );
        $this->assertSame(0, $this->store->purge($later));
        $this->assertNotNull($replacement);
        $this->assertNotSame($old['draft_id'], $replacement['draft_id']);
        $this->assertSame($replacement['draft_id'], $this->store->read(7, $replacement['draft_id'], $later)['draft_id']);
    }

    public function testCompetingAllocationCannotExceedTheOwnerLimit(): void
    {
        for ($index = 0; $index < DraftStore::MAX_OWNER_ROWS - 1; $index++) {
            $this->store->open(7, $this->descriptor(), $this->now);
        }
        $peer   = new DraftStore($this->peer());
        $winner = null;
        $this->beforeStatement(
            'INSERT',
            function () use ($peer, &$winner) {
                $winner = $peer->open(7, $this->descriptor(), $this->now);
            }
        );
        $this->failure('draft_limit_reached', fn () => $this->store->open(7, $this->descriptor(), $this->now));
        $this->assertNotNull($winner);
        $this->assertSame(DraftStore::MAX_OWNER_ROWS, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts WHERE owner_id = 7')->loadResult());
        $this->assertSame(DraftStore::MAX_OWNER_ROWS, (int) $this->db->setQuery('SELECT COUNT(DISTINCT owner_slot) FROM #__autosave_drafts WHERE owner_id = 7')->loadResult());
        $this->assertNull($this->store->read(7, $winner['draft_id'], $this->now)['payload']);
    }

    public function testPostSaveFloorsConsumeCapacityUntilExpiry(): void
    {
        for ($index = 0; $index < DraftStore::MAX_OWNER_ROWS; $index++) {
            $draft = $this->store->open(7, $this->descriptor(), $this->now);
            $this->store->reconcile(7, $draft['draft_id'], 1, 'com_content.article', '42', [], 'apply', '42', null, $this->now);
        }
        $this->failure('draft_limit_reached', fn () => $this->store->open(7, $this->descriptor(), $this->now));
        $this->assertNotEmpty($this->store->open(7, $this->descriptor(), new Date('2026-10-12 00:00:00', 'UTC'))['draft_id']);
    }

    public function testCompetingSlotClaimRetriesWithoutReturningTheOtherFormsId(): void
    {
        $peer   = new DraftStore($this->peer());
        $winner = null;
        $this->beforeStatement(
            'INSERT',
            function () use ($peer, &$winner) {
                $winner = $peer->open(7, $this->descriptor(), $this->now);
            }
        );
        $own = $this->store->open(7, $this->descriptor(), $this->now);
        $this->assertNotNull($winner);
        $this->assertNotSame($winner['draft_id'], $own['draft_id']);
        $this->assertNotSame($winner['owner_slot'], $own['owner_slot']);
        $this->assertSame($own['draft_id'], $this->store->read(7, $own['draft_id'], $this->now)['draft_id']);
    }

    public function testNonDuplicateIntegrityFailurePropagatesDespiteACompetingSlotClaim(): void
    {
        $peer    = new DraftStore($this->peer());
        $failure = new ExecutionFailureException('INSERT', '23000, 1048, Required value is missing', 23000);
        $winner  = null;
        $this->beforeStatement(
            'INSERT',
            function () use ($peer, $failure, &$winner) {
                $winner = $peer->open(7, $this->descriptor(), $this->now);
                throw $failure;
            }
        );
        try {
            $this->store->open(7, $this->descriptor(), $this->now);
            $this->fail('An unrelated integrity failure must not be retried as slot contention.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertNotNull($winner);
        $this->assertSame(1, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
        $this->assertSame($winner['draft_id'], $this->store->read(7, $winner['draft_id'], $this->now)['draft_id']);
    }

    public function testDuplicateFailureWithoutACompetingSlotClaimPropagates(): void
    {
        $failure = new ExecutionFailureException('INSERT', '23000, 1062, Duplicate entry', 23000);
        $this->beforeStatement(
            'INSERT',
            static function () use ($failure) {
                throw $failure;
            }
        );
        try {
            $this->store->open(7, $this->descriptor(), $this->now);
            $this->fail('A duplicate without an owner-slot winner must not be retried.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
    }

    public function testSourceWriteFailureDoesNotUndoCurrentRetirement(): void
    {
        $source = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($source, 1, 'restored');
        $current = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($current, 1, 'submitted');
        $locker = $this->peer();
        $this->db->setQuery($this->db->getServerType() === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1000ms'")->execute();
        $locker->transactionStart();
        try {
            $locker->setQuery('SELECT id FROM #__autosave_drafts WHERE draft_id = ' . $locker->quote($source['draft_id']) . ' FOR UPDATE')->loadResult();
            try {
                $this->store->reconcile(
                    7,
                    $current['draft_id'],
                    1,
                    'com_content.article',
                    '42',
                    [],
                    'save',
                    '42',
                    null,
                    $this->now,
                    ['draft_id' => $source['draft_id'], 'revision' => 1]
                );
                $this->fail('The optional source write must fail under contention.');
            } catch (ExecutionFailureException) {
                $this->assertNull($this->store->read(7, $current['draft_id'], $this->now)['payload']);
                $this->assertSame(['title' => 'restored'], $this->store->read(7, $source['draft_id'], $this->now)['payload']);
            }
        } finally {
            $locker->transactionRollback();
        }
        $this->store->reconcile(
            7,
            $current['draft_id'],
            1,
            'com_content.article',
            '42',
            [],
            'save',
            '42',
            null,
            $this->now,
            ['draft_id' => $source['draft_id'], 'revision' => 1]
        );
        $this->assertNull($this->store->read(7, $source['draft_id'], $this->now)['payload']);
    }

    public function testPurgeCannotDeleteAPayloadRenewedAfterExpiredSelection(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $peer  = new DraftStore($this->peer());
        $this->beforeStatement(
            'DELETE',
            function () use ($peer, $draft) {
                // A preserve started before expiry finishes after cleanup selected its old version.
                $peer->preserve(7, $draft['draft_id'], 1, ['title' => 'renewed before expiry'], new Date('2026-10-11 23:59:00', 'UTC'), static fn ($row, $values) => $values);
            }
        );
        $later = new Date('2026-10-12 00:01:00', 'UTC');
        $this->assertSame(0, $this->store->purge($later));
        $row = $this->store->read(7, $draft['draft_id'], $later);
        $this->assertSame(['title' => 'renewed before expiry'], $row['payload']);
        $this->assertSame('2026-10-18 23:59:00', $row['expires_at']);
        $this->assertSame(1, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
    }

    public function testUnrelatedInsertFailurePropagatesAndLeavesTheConnectionUsable(): void
    {
        $descriptor            = $this->descriptor();
        $descriptor['context'] = str_repeat('x', 256);
        $connection            = $this->db->getConnection();
        try {
            $this->store->open(7, $descriptor, $this->now);
            $this->fail('An invalid column value must not be treated as slot contention.');
        } catch (ExecutionFailureException) {
            $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
            $this->assertSame($connection, $this->db->getConnection());
        }
        $this->assertNotEmpty($this->store->open(7, $this->descriptor(), $this->now)['draft_id']);
    }

    public function testProviderCannotLeavePreservationInsideACallerTransaction(): void
    {
        $connection = $this->db->getConnection();
        if (!$connection instanceof \PDO) {
            $this->markTestSkipped('The active caller transaction guard is specified for PDO connections.');
        }
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        try {
            $this->failure(
                'service_unavailable',
                fn () => $this->store->preserve(
                    7,
                    $draft['draft_id'],
                    1,
                    ['title' => 'local'],
                    $this->now,
                    function ($row, $values) {
                        $this->db->transactionStart();
                        return $values;
                    }
                )
            );
            $this->assertTrue($connection->inTransaction());
            $this->assertNull($this->store->read(7, $draft['draft_id'], $this->now)['payload']);
        } finally {
            if ($connection->inTransaction()) {
                $this->db->transactionRollback();
            }
        }
    }

    public function testCurrentRetirementRetriesWithoutRemovingAConcurrentLaterRevision(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'submitted');
        $peer = new DraftStore($this->peer());
        $this->beforeStatement(
            'UPDATE',
            function () use ($peer, $draft) {
                $peer->preserve(7, $draft['draft_id'], 2, ['title' => 'later'], $this->now, static fn ($row, $values) => $values);
            }
        );
        $this->store->reconcile(7, $draft['draft_id'], 1, 'com_content.article', '42', [], 'apply', '42', null, $this->now);
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame(['title' => 'later'], $row['payload']);
        $this->assertSame(1, $row['retired_revision']);
    }

    /**
     * @dataProvider changedRecoverySources
     */
    public function testRecoverySourceIsRevalidatedAfterCurrentRetirement(bool $bindingChanged): void
    {
        $source = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($source, 1, 'restored');
        $current = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($current, 1, 'submitted');
        $peer = $this->peer();
        $this->beforeStatement(
            'UPDATE',
            function () use ($peer, $source, $bindingChanged) {
                if ($bindingChanged) {
                    $peer->setQuery(
                        'UPDATE #__autosave_drafts SET target_id = ' . $peer->quote('84')
                        . ', row_version = row_version + 1 WHERE draft_id = ' . $peer->quote($source['draft_id'])
                    )->execute();
                } else {
                    (new DraftStore($peer))->preserve(7, $source['draft_id'], 2, ['title' => 'later source'], $this->now, static fn ($row, $values) => $values);
                }
            }
        );
        $operation = fn () => $this->store->reconcile(
            7,
            $current['draft_id'],
            1,
            'com_content.article',
            '42',
            [],
            'save',
            '42',
            null,
            $this->now,
            ['draft_id' => $source['draft_id'], 'revision' => 1]
        );
        if ($bindingChanged) {
            $this->failure('scope_conflict', $operation);
        } else {
            $operation();
        }
        $this->assertNull($this->store->read(7, $current['draft_id'], $this->now)['payload']);
        $row = $this->store->read(7, $source['draft_id'], $this->now);
        $this->assertSame($bindingChanged ? ['title' => 'restored'] : ['title' => 'later source'], $row['payload']);
        $this->assertSame($bindingChanged ? 0 : 1, $row['retired_revision']);
    }

    public static function changedRecoverySources(): array
    {
        return [[false], [true]];
    }

    public function testPreserveRetriesAreBoundedUnderRepeatedContention(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $peer  = new DraftStore($this->peer());
        $this->failure(
            'revision_conflict',
            fn () => $this->store->preserve(
                7,
                $draft['draft_id'],
                100,
                ['title' => 'not acknowledged'],
                $this->now,
                function ($row, $values) use ($peer, $draft) {
                    $peer->preserve(7, $draft['draft_id'], $row['client_revision'] + 1, ['title' => 'peer work'], $this->now, static fn ($binding, $payload) => $payload);
                    return $values;
                }
            )
        );
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertGreaterThan(0, $row['client_revision']);
        $this->assertLessThan(100, $row['client_revision']);
        $this->assertSame(['title' => 'peer work'], $row['payload']);
    }

    public function testDefaultMaximumPayloadRoundTripsAndOversizeKeepsTheAcknowledgedDraft(): void
    {
        $maximum = DraftStore::POLICY['max_payload_bytes'];
        $value   = str_repeat('x', $maximum - \strlen(json_encode(['title' => ''], JSON_THROW_ON_ERROR)));
        $draft   = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, $value);
        $this->assertSame($maximum, (int) $this->db->setQuery('SELECT OCTET_LENGTH(payload) FROM #__autosave_drafts WHERE owner_id = 7')->loadResult());
        $this->assertSame(['title' => $value], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
        $this->failure('payload_too_large', fn () => $this->preserve($draft, 2, $value . 'x'));
        $retained = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame(1, $retained['client_revision']);
        $this->assertSame(['title' => $value], $retained['payload']);
    }

    public function testAllPopulatedOwnerSlotsRemainBounded(): void
    {
        for ($index = 0; $index < DraftStore::MAX_OWNER_ROWS; $index++) {
            $draft = $this->store->open(7, $this->descriptor(), $this->now);
            $this->preserve($draft, 1, 'Draft ' . $index);
        }
        $this->failure('draft_limit_reached', fn () => $this->store->open(7, $this->descriptor(), $this->now));
        $this->assertSame(DraftStore::MAX_OWNER_ROWS, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts WHERE owner_id = 7 AND payload IS NOT NULL')->loadResult());
        $this->assertSame(['title' => 'Draft ' . (DraftStore::MAX_OWNER_ROWS - 1)], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    public function testCallerTransactionIsNeitherUsedNorRolledBack(): void
    {
        $connection = $this->db->getConnection();
        if (!$connection instanceof \PDO) {
            $this->markTestSkipped('The active caller transaction guard is specified for PDO connections.');
        }
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->db->transactionStart();
        try {
            $this->failure('service_unavailable', fn () => $this->preserve($draft, 1, 'must not write'));
            $this->failure('service_unavailable', fn () => $this->store->discard(7, $draft['draft_id']));
            $this->failure('service_unavailable', fn () => $this->store->open(7, $this->descriptor(), $this->now));
            $this->assertTrue($connection->inTransaction());
            $this->assertSame(1, (int) $this->db->setQuery('SELECT 1')->loadResult());
            $this->assertNull($this->store->read(7, $draft['draft_id'], $this->now)['payload']);
        } finally {
            $this->db->transactionRollback();
        }
        $this->preserve($draft, 1, 'after caller rollback');
    }

    public static function contendedOperations(): array
    {
        return [['preserve'], ['discard'], ['purge'], ['reconcile'], ['open']];
    }

    /**
     * @dataProvider contendedOperations
     */
    public function testStatementFailurePreservesIntegrityAndConnectionRecovery(string $operation): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $peer  = $this->peer();
        $peer->setQuery($peer->getServerType() === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1000ms'")->execute();
        $connection = $peer->getConnection();
        $competing  = new DraftStore($peer);
        $later      = new Date('2026-10-12 00:00:00', 'UTC');
        $act        = function () use ($competing, $draft, $operation, $later) {
            return match ($operation) {
                'preserve'  => $competing->preserve(7, $draft['draft_id'], 1, ['title' => 'peer'], $this->now, static fn ($row, $values) => $values),
                'discard'   => $competing->discard(7, $draft['draft_id']),
                'purge'     => $competing->purge($later),
                'open'      => $competing->open(7, $this->descriptor(), $later),
                'reconcile' => $competing->reconcile(7, $draft['draft_id'], 1, 'com_content.article', '42', [], 'save', '42', null, $this->now),
            };
        };
        $before = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->db->transactionStart();
        try {
            $this->db->setQuery('SELECT id FROM #__autosave_drafts WHERE draft_id = ' . $this->db->quote($draft['draft_id']) . ' FOR UPDATE')->loadResult();
            try {
                $act();
                $this->fail('A contending statement must not acknowledge a successful write.');
            } catch (ExecutionFailureException) {
                $this->assertSame($before, $this->store->read(7, $draft['draft_id'], $this->now));
                $this->assertSame($connection, $peer->getConnection(), 'Statement failure must not replace the connection.');
                $peer->transactionStart();
                try {
                    $this->assertSame(1, (int) $peer->setQuery('SELECT 1')->loadResult());
                    if ($connection instanceof \PDO) {
                        $this->assertTrue($connection->inTransaction());
                    }
                } finally {
                    $peer->transactionRollback();
                }
            }
        } finally {
            $this->db->transactionRollback();
        }
        $act();
        if ($operation === 'preserve') {
            $this->assertSame(['title' => 'peer'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
        } elseif (\in_array($operation, ['discard', 'purge', 'open'], true)) {
            $this->failure('draft_not_found', fn () => $this->store->read(7, $draft['draft_id'], $this->now));
        } else {
            $this->assertSame(1, $this->store->read(7, $draft['draft_id'], $this->now)['retired_revision']);
        }
    }
}
