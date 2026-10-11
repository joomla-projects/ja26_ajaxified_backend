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
 * Optional canonical-controller bridge for provisional new-record origins.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveCreateCanonicalActionServiceInterface extends AutosaveCanonicalActionServiceInterface
{
    /**
     * @return array{operation_id: string, intent: string, outcome: string, expected_base_revision: string, target_id: string}
     */
    public function verifyCreateCanonicalAction(
        User $user,
        string $operationId,
        string $context,
        string $intent,
        Date $now
    ): array;
}
