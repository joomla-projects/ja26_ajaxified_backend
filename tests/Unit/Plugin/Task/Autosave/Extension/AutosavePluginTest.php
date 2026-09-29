<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Task.autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Task\Autosave\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveStorageInterface;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Language;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Task\Task;
use Joomla\Plugin\Task\Autosave\Extension\Autosave;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests for the Autosave cleanup task plugin.
 *
 * @testdox  The Autosave cleanup task plugin
 *
 * @since    __DEPLOY_VERSION__
 */
class AutosavePluginTest extends UnitTestCase
{
    /**
     * @testdox executes one fixed bounded cleanup batch
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testExecutesBoundedCleanup(): void
    {
        $storage = $this->createMock(AutosaveStorageInterface::class);
        $storage->expects($this->once())
            ->method('purgeRetainedData')
            ->with(
                $this->callback(static fn ($now): bool => $now instanceof Date),
                AutosaveStorageInterface::DEFAULT_PURGE_LIMIT
            )
            ->willReturn(
                [
                    'generations_expired'         => 1,
                    'closed_generations_released' => 2,
                    'canonical_actions_deleted'   => 3,
                    'generations_deleted'         => 4,
                    'continuations_deleted'       => 5,
                ]
            );

        $plugin = $this->createPlugin($storage);
        $task   = $this->createStub(Task::class);
        $task->method('get')->willReturnMap(
            [
                ['id', null, 1],
                ['type', null, 'autosave.cleanup'],
            ]
        );
        $event = new ExecuteTaskEvent('test', ['subject' => $task, 'params' => (object) []]);

        $plugin->standardRoutineHandler($event);

        $this->assertSame(Status::OK, $event->getResultSnapshot()['status']);
    }

    /**
     * @testdox reports storage failures through standard scheduler failure semantics
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testReportsCleanupFailure(): void
    {
        $storage = $this->createMock(AutosaveStorageInterface::class);
        $storage->expects($this->once())
            ->method('purgeRetainedData')
            ->willThrowException(new \RuntimeException('Database unavailable.'));

        $plugin = $this->createPlugin($storage);
        $task   = $this->createStub(Task::class);
        $task->method('get')->willReturnMap(
            [
                ['id', null, 1],
                ['type', null, 'autosave.cleanup'],
            ]
        );
        $event = new ExecuteTaskEvent('test', ['subject' => $task, 'params' => (object) []]);

        $plugin->standardRoutineHandler($event);

        $this->assertSame(Status::KNOCKOUT, $event->getResultSnapshot()['status']);
    }

    /**
     * @testdox succeeds when no retained Autosave data is eligible for cleanup
     *
     * @since  __DEPLOY_VERSION__
     */
    public function testEmptyCleanupBatchSucceeds(): void
    {
        $storage = $this->createMock(AutosaveStorageInterface::class);
        $storage->expects($this->once())
            ->method('purgeRetainedData')
            ->willReturn(
                [
                    'generations_expired'         => 0,
                    'closed_generations_released' => 0,
                    'canonical_actions_deleted'   => 0,
                    'generations_deleted'         => 0,
                    'continuations_deleted'       => 0,
                ]
            );

        $plugin = $this->createPlugin($storage);
        $task   = $this->createStub(Task::class);
        $task->method('get')->willReturnMap(
            [
                ['id', null, 1],
                ['type', null, 'autosave.cleanup'],
            ]
        );
        $event = new ExecuteTaskEvent('test', ['subject' => $task, 'params' => (object) []]);

        $plugin->standardRoutineHandler($event);

        $this->assertSame(Status::OK, $event->getResultSnapshot()['status']);
    }

    /**
     * Create the plugin with the application services used by the Scheduler task trait.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function createPlugin(AutosaveStorageInterface $storage): Autosave
    {
        $language = $this->createStub(Language::class);
        $app      = $this->createStub(CMSApplicationInterface::class);
        $app->method('getLanguage')->willReturn($language);

        $plugin = new Autosave([], $storage);
        $plugin->setApplication($app);

        return $plugin;
    }
}
