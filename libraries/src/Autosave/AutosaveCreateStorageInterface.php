<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Date\Date;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Optional persistence capability for provisional canonical verification.
 *
 * Existing-record storage implementations remain valid without this contract.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveCreateStorageInterface extends AutosaveStorageInterface
{
    /**
     * Verify a provisional-origin operation without accepting its target from the request.
     *
     * @return array{operation_id: string, intent: string, outcome: string, expected_base_revision: string, target_id: string, payload: array}
     *
     * @since  __DEPLOY_VERSION__
     */
    public function verifyCreateCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $intent,
        string $currentBaseRevision,
        Date $now
    ): array;
}
