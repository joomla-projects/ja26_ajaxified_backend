<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Date\Date;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Server-side bridge used by canonical component controllers.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveCanonicalActionServiceInterface
{
    public function verifyCanonicalAction(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        Date $now
    ): array;

    public function finalizeCanonicalActionSuccess(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $finalTargetId,
        Date $now
    ): array;

    public function finalizeCanonicalActionFailure(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $failureCode,
        Date $now
    ): array;
}
