<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Optional provider contract for payloads whose validity depends on canonical target data.
 *
 * @since  __DEPLOY_VERSION__
 */
interface TargetAwareAutosaveProviderInterface extends AutosaveProviderInterface
{
    /**
     * Validate and normalize a draft payload against its canonical target.
     *
     * @param   string   $targetId      The canonical target identity.
     * @param   mixed    $payload       The decoded client payload.
     * @param   integer  $schemaVersion The declared schema version.
     *
     * @return  array
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array;
}
