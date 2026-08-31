<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Optional static new-record capability for an Autosave provider.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveCreateProviderInterface
{
    public function getCreateContractVersion(): string;

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void;
}
