<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Task.autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Task\Autosave\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Language;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Plugin\Task\Autosave\Extension\Autosave;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\DraftDatabaseFixture;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Execute the native task against the actual draft store in an isolated SQL database.
 */
class AutosavePluginTest extends UnitTestCase
{
    use DraftDatabaseFixture;

    private DatabaseInterface $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'cleanup_test_']);
        $this->installSchema('postgresql');
        $this->now = new Date('now', 'UTC');
    }

    private function open(): array
    {
        return $this->store->open(7, [
            'context'  => 'com_content.article', 'target' => null, 'scope' => [], 'version' => 1,
            'revision' => null, 'fields' => ['title' => ['control' => 'text']],
        ], $this->now);
    }

    private function expire(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->open();
        }
        $this->db->setQuery("UPDATE #__autosave_drafts SET expires_at = '2000-01-01 00:00:00'")->execute();
    }

    private function runTask(?DraftStore $store = null): int
    {
        $language = $this->createStub(Language::class);
        $app      = $this->createStub(CMSApplicationInterface::class);
        $app->method('getLanguage')->willReturn($language);
        $plugin = new Autosave([], $store ?? $this->store);
        $plugin->setApplication($app);
        $task = $this->createStub(Task::class);
        $task->method('get')->willReturnMap([
            ['id', null, 1],
            ['type', null, 'autosave.cleanup'],
        ]);
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
        $this->assertSame(Status::OK, $this->runTask());
        $this->assertFalse($this->store->hasExpired($this->now));
    }

    public function testEmptyCleanupSucceeds(): void
    {
        $this->assertSame(Status::OK, $this->runTask());
    }

    public function testReportsStorageFailureThroughNativeScheduler(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willThrowException(new \RuntimeException('Test database unavailable'));
        $this->assertSame(Status::KNOCKOUT, $this->runTask(new DraftStore($db)));
    }
}
