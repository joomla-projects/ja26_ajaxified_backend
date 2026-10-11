<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Event\Controller;

use Joomla\CMS\Event\AbstractImmutableEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Event dispatched after a form controller task has completed successfully.
 *
 * The event describes an already completed canonical operation. Listener
 * failures cannot change the controller's prepared result.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FormTaskSuccessEvent extends AbstractImmutableEvent
{
    /**
     * Constructor.
     *
     * @param   string  $name       The event name.
     * @param   array   $arguments  The event arguments.
     *
     * @since   __DEPLOY_VERSION__
     * @throws  \BadMethodCallException
     */
    public function __construct(string $name, array $arguments = [])
    {
        foreach (['context', 'task', 'originalId', 'resultingId'] as $argument) {
            if (!\array_key_exists($argument, $arguments)) {
                throw new \BadMethodCallException("Argument '{$argument}' of event {$name} is required but has not been provided");
            }
        }

        parent::__construct($name, $arguments);
    }

    /**
     * Validate the controller context.
     *
     * @param   string  $value  The controller context.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function onSetContext(string $value): string
    {
        if ($value === '') {
            throw new \UnexpectedValueException("Argument 'context' of event {$this->name} must be a non-empty string");
        }

        return $value;
    }

    /**
     * Validate the completed task.
     *
     * @param   string  $value  The completed task.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function onSetTask(string $value): string
    {
        if ($value === '') {
            throw new \UnexpectedValueException("Argument 'task' of event {$this->name} must be a non-empty string");
        }

        return $value;
    }

    /**
     * Validate the original record identity.
     *
     * @param   ?integer  $value  The original record identity.
     *
     * @return  ?integer
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function onSetOriginalId(?int $value): ?int
    {
        return $value;
    }

    /**
     * Validate the resulting record identity.
     *
     * @param   ?integer  $value  The resulting record identity.
     *
     * @return  ?integer
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function onSetResultingId(?int $value): ?int
    {
        return $value;
    }

    /**
     * Get the controller context.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getContext(): string
    {
        return $this->arguments['context'];
    }

    /**
     * Get the completed Joomla task.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getTask(): string
    {
        return $this->arguments['task'];
    }

    /**
     * Get the submitted record identity.
     *
     * @return  ?integer
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getOriginalId(): ?int
    {
        return $this->arguments['originalId'];
    }

    /**
     * Get the resulting canonical record identity.
     *
     * @return  ?integer
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getResultingId(): ?int
    {
        return $this->arguments['resultingId'];
    }
}
