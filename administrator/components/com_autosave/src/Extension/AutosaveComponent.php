<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Autosave\Administrator\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCreateCanonicalActionServiceInterface;
use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Autosave\Administrator\Dispatcher\Dispatcher;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Hidden administrator Autosave component.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveComponent implements ComponentInterface, AutosaveCreateCanonicalActionServiceInterface
{
    /**
     * Deployment policy for component-neutral draft persistence.
     *
     * @var array{
     *     idle_ttl: int,
     *     max_lifetime: int,
     *     tombstone_retention: int,
     *     max_active_generations: int,
     *     max_payload_bytes: int
     * }
     */
    public const STORAGE_POLICY = [
        'idle_ttl'               => 604800,
        'max_lifetime'           => 2592000,
        'tombstone_retention'    => 86400,
        'max_active_generations' => 20,
        'max_payload_bytes'      => 4194304,
    ];

    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly AutosaveLifecycle $lifecycle)
    {
    }

    /**
     * Return the component's fixed protected dispatcher.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getDispatcher(CMSApplicationInterface $application): DispatcherInterface
    {
        return new Dispatcher($application, $application->getInput(), $this->lifecycle);
    }

    /**
     * {@inheritDoc}
     */
    public function verifyCanonicalAction(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        Date $now
    ): array {
        return $this->lifecycle->verifyCanonicalAction(
            $user,
            $operationId,
            $context,
            $targetId,
            $intent,
            $now
        );
    }

    public function verifyCreateCanonicalAction(
        User $user,
        string $operationId,
        string $context,
        string $intent,
        Date $now
    ): array {
        return $this->lifecycle->verifyCreateCanonicalAction($user, $operationId, $context, $intent, $now);
    }

    /**
     * {@inheritDoc}
     */
    public function finalizeCanonicalActionSuccess(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $finalTargetId,
        Date $now
    ): array {
        return $this->lifecycle->finalizeCanonicalActionSuccess(
            $user,
            $operationId,
            $context,
            $targetId,
            $intent,
            $finalTargetId,
            $now
        );
    }

    /**
     * {@inheritDoc}
     */
    public function finalizeCanonicalActionFailure(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $failureCode,
        Date $now
    ): array {
        return $this->lifecycle->finalizeCanonicalActionFailure(
            $user,
            $operationId,
            $context,
            $targetId,
            $intent,
            $failureCode,
            $now
        );
    }
}
