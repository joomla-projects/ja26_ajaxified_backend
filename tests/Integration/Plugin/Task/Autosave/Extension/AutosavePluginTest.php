<?php

/**
 * @package    Joomla.IntegrationTest
 * @subpackage Task.autosave
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Plugin\Task\Autosave\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;
use Joomla\Database\DatabaseInterface;
use Joomla\Plugin\Task\Autosave\Extension\Autosave;
use Joomla\Tests\Integration\IntegrationTestCase;
use Joomla\Tests\Integration\Libraries\Cms\Autosave\DraftDatabaseFixture;

/**
 * Execute the native task against the actual draft store in an isolated SQL database.
 */
class AutosavePluginTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use DraftDatabaseFixture;

    private DatabaseInterface $db;
    private mixed $previousLog;
    private array $entries = [];
    private bool $loggerFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $this->now = new Date('now', 'UTC');
        $this->previousLog = (new \ReflectionProperty(Log::class, 'instance'))->getValue();
        $logger = $this->getMockBuilder(Log::class)->disableOriginalConstructor()->onlyMethods(['addLogEntry'])->getMock();
        $logger->method('addLogEntry')->willReturnCallback(
            function (LogEntry $entry): void {
                $this->entries[] = $entry;
                if ($this->loggerFails) {
                    throw new \RuntimeException('Private logger detail');
                }
            }
        );
        Log::setInstance($logger);
    }

    protected function tearDown(): void
    {
        Log::setInstance($this->previousLog);
        parent::tearDown();
    }

    private function open(int $owner = 7): array
    {
        return $this->store->open(
            $owner,
            [
            'context'  => 'com_content.article', 'target' => null, 'scope' => [], 'version' => 1,
            'revision' => null, 'fields' => ['title' => ['control' => 'text']],
            ],
            $this->now
        );
    }

    private function expire(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $owner = 7 + intdiv($index, DraftStore::MAX_OWNER_ROWS);
            if ($index % DraftStore::MAX_OWNER_ROWS === 0 && !$this->db->setQuery('SELECT id FROM #__users WHERE id = ' . $owner)->loadResult()) {
                $user = (object) ['id' => $owner, 'username' => 'cleanup-test-' . $owner, 'registerDate' => '2026-10-11 00:00:00', 'params' => '{}'];
                $this->db->insertObject('#__users', $user);
            }
            $row = (object) [
                'draft_id' => bin2hex(random_bytes(16)), 'owner_id' => $owner, 'owner_slot' => $index % DraftStore::MAX_OWNER_ROWS,
                'context' => 'com_content.article', 'target_id' => null, 'scope' => '[]', 'schema_version' => 1,
                'baseline_revision' => null, 'fields' => '{"title":{"control":"text"}}',
                'created_at' => $this->now->toSql(), 'updated_at' => $this->now->toSql(), 'expires_at' => '2000-01-01 00:00:00',
            ];
            $this->db->insertObject('#__autosave_drafts', $row);
        }
    }

    private function runTask(?DraftStore $store = null): int
    {
        $language = $this->createStub(Language::class);
        $app      = $this->createStub(CMSApplicationInterface::class);
        $app->method('getLanguage')->willReturn($language);
        $plugin = new Autosave([], $store ?? $this->store);
        $plugin->setApplication($app);
        $task = $this->createStub(Task::class);
        $task->logCategory = 'autosave-cleanup-test';
        $task->method('get')->willReturnMap(
            [
            ['id', null, 1],
            ['type', null, 'autosave.cleanup'],
            ]
        );
        $event = new ExecuteTaskEvent('test', ['subject' => $task, 'params' => (object) []]);
        $plugin->standardRoutineHandler($event);

        return $event->getResultSnapshot()['status'];
    }

    public function testMultipleBatchesRemoveOnlyExpiredRowsWithoutLegacyTables(): void
    {
        $this->expire(205);
        $live = $this->open();
        $this->store->preserve(7, $live['draft_id'], 1, ['title' => 'live content'], $this->now, static fn ($binding, $payload) => $payload);
        $this->assertSame(Status::OK, $this->runTask());
        $this->assertSame(1, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
        $this->assertSame(['title' => 'live content'], $this->store->read(7, $live['draft_id'], $this->now)['payload']);
    }

    public function testBacklogResumesThroughNativeSchedulerAndFinishes(): void
    {
        $this->expire(1101);
        $this->assertSame(Status::WILL_RESUME, $this->runTask());
        $this->assertTrue($this->store->hasExpired($this->now));
        $remaining = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult();
        $this->assertGreaterThanOrEqual(101, $remaining);
        $this->assertLessThan(1101, $remaining);

        $status = Status::WILL_RESUME;

        for ($attempt = 0; $attempt < 20 && $status === Status::WILL_RESUME; $attempt++) {
            $status       = $this->runTask();
            $newRemaining = (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult();
            $this->assertLessThan($remaining, $newRemaining);
            $remaining = $newRemaining;
            $this->assertContains($status, [Status::WILL_RESUME, Status::OK]);
        }

        $this->assertSame(Status::OK, $status);
        $this->assertSame(0, $remaining);
        $this->assertFalse($this->store->hasExpired($this->now));
    }

    public function testEmptyCleanupSucceeds(): void
    {
        $this->assertSame(Status::OK, $this->runTask());
    }

    /**
     * @dataProvider diagnosticLoggerFailures
     */
    public function testReportsStorageFailureThroughNativeScheduler(bool $loggerFails): void
    {
        $this->loggerFails = $loggerFails;
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willThrowException(new \RuntimeException('Private SQL and credentials'));
        $this->assertSame(Status::KNOCKOUT, $this->runTask(new DraftStore($db)));
        $this->assertCount(1, $this->entries);
        $this->assertSame(Log::ERROR, $this->entries[0]->priority);
        $this->assertSame('autosave-cleanup-test', $this->entries[0]->category);
        $this->assertStringContainsString('Autosave cleanup failed. RuntimeException', $this->entries[0]->message);
        $this->assertStringNotContainsString('Private', $this->entries[0]->message);
        $this->assertSame([], $this->entries[0]->context);
    }

    public function testLoggerFailureDoesNotTurnCompletedCleanupIntoFailure(): void
    {
        $this->expire(1);
        $this->loggerFails = true;
        $this->assertSame(Status::OK, $this->runTask());
        $this->assertFalse($this->store->hasExpired($this->now));
        $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
        $this->assertCount(1, $this->entries);
        $this->assertSame(Log::INFO, $this->entries[0]->priority);
    }

    public static function diagnosticLoggerFailures(): array
    {
        return [[false], [true]];
    }
}
