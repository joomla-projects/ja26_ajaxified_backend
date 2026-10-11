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
 * Component-owned provider for one exact Autosave context.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveProviderInterface
{
    /**
     * Return the exact qualified context implemented by this provider.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getContext(): string;

    /**
     * Validate and canonicalize a client target identity.
     *
     * @param   string  $targetId  The client target identity.
     *
     * @return  string
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function canonicalizeTargetId(string $targetId): string;

    /**
     * Determine whether a canonical target currently exists.
     *
     * @param   string  $targetId  The canonical target identity.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function targetExists(string $targetId): bool;

    /**
     * Authorize an operation and enforce the component checkout contract.
     *
     * @param   User               $user      The authenticated server-side identity.
     * @param   string             $targetId  The canonical target identity.
     * @param   AutosaveOperation  $operation The requested operation.
     *
     * @return  void
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void;

    /**
     * Return a stable, opaque token for the canonical component state.
     *
     * @param   string  $targetId  The canonical target identity.
     *
     * @return  string
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getBaseRevision(string $targetId): string;

    /**
     * Return the only payload schema version currently accepted.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getPayloadSchemaVersion(): int;

    /**
     * Validate and normalize a draft payload.
     *
     * @param   mixed    $payload        The decoded client payload.
     * @param   integer  $schemaVersion  The declared schema version.
     *
     * @return  array
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function normalizePayload(mixed $payload, int $schemaVersion): array;
}
