<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\Autosave\Extension;

use Joomla\CMS\Autosave\AutosaveStorageInterface;
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
    public function __construct(array $config, private readonly AutosaveStorageInterface $storage)
    {
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
     * Remove one bounded batch of retained Autosave data.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function cleanup(ExecuteTaskEvent $event): int
    {
        try {
            $result = $this->storage->purgeRetainedData(
                new Date('now', 'UTC'),
                AutosaveStorageInterface::DEFAULT_PURGE_LIMIT
            );
        } catch (\Throwable) {
            return Status::KNOCKOUT;
        }

        $this->logTask(
            Text::sprintf(
                'PLG_TASK_AUTOSAVE_CLEANUP_RESULT',
                $result['generations_expired'],
                $result['closed_generations_released'],
                $result['canonical_actions_deleted'],
                $result['generations_deleted'],
                $result['continuations_deleted']
            )
        );

        return Status::OK;
    }
}
