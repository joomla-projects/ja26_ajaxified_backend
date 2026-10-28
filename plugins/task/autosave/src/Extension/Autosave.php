<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\Autosave\Extension;

use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Scheduled cleanup for retained Autosave data.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Autosave extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    private const MAX_PURGE_ROWS = 1000;

    private const MAX_PURGE_SECONDS = 5;

    /**
     * Supported task routines.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const TASKS_MAP = [
        'autosave.cleanup' => [
            'langConstPrefix' => 'PLG_TASK_AUTOSAVE_CLEANUP',
            'method'          => 'cleanup',
        ],
    ];

    /**
     * Load the plugin language automatically.
     *
     * @var boolean
     *
     * @since  __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(
        array $config,
        private readonly DraftStore $drafts
    ) {
        parent::__construct($config);
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask'     => 'standardRoutineHandler',
        ];
    }

    /**
     * Purge one instance at a time within a soft run budget, using native Scheduler continuation.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function cleanup(ExecuteTaskEvent $event): int
    {
        $now      = new Date('now', 'UTC');
        $deadline = hrtime(true) + self::MAX_PURGE_SECONDS * 1000000000;
        $deleted  = 0;

        try {
            do {
                $count = $this->drafts->purge($now, min(DraftStore::PURGE_BATCH_SIZE, self::MAX_PURGE_ROWS - $deleted), $deadline);
                $deleted += $count;
            } while ($count > 0 && $deleted < self::MAX_PURGE_ROWS && hrtime(true) < $deadline);

            $remaining = $this->drafts->hasExpired($now);
        } catch (\Throwable $exception) {
            try {
                $this->logTask('Autosave cleanup failed. ' . $exception::class, 'error');
            } catch (\Throwable) {
                // A failed logger cannot replace the cleanup failure status.
            }

            return Status::KNOCKOUT;
        }

        try {
            $this->logTask(Text::sprintf('PLG_TASK_AUTOSAVE_DRAFT_CLEANUP_RESULT', $deleted));

            if ($remaining) {
                $this->logTask(Text::_('PLG_TASK_AUTOSAVE_DRAFT_CLEANUP_REMAINING'));
            }
        } catch (\Throwable) {
            // Reporting must not change the completed cleanup result or continuation decision.
        }

        return $remaining ? Status::WILL_RESUME : Status::OK;
    }
}
