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
 * Component-neutral Autosave persistence operations.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveStorageInterface
{
    /**
     * Create or idempotently return a continuation and its initial generation.
     *
     * @return  array{continuation_id: string, generation_id: string}
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function initialize(
        int $userId,
        string $context,
        string $targetId,
        string $baseRevision,
        string $initializationKey,
        Date $now
    ): array;

    /**
     * Inspect one recoverable owner-bound generation.
     *
     * @return  array{
     *     continuation_id: string,
     *     context: string,
     *     target_id: string,
     *     generation_id: string,
     *     base_revision: string,
     *     state: string,
     *     client_revision: int,
     *     payload: ?array,
     *     payload_schema_version: ?int,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string,
     *     terminal_at: ?string,
     *     retain_until: ?string
     * }
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function inspect(int $userId, string $continuationId, string $generationId, Date $now): array;

    /**
     * Atomically preserve a provider-normalized payload.
     *
     * @return  string  accepted or idempotent
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function preserve(
        int $userId,
        string $continuationId,
        string $generationId,
        string $context,
        string $targetId,
        string $baseRevision,
        int $clientRevision,
        array $payload,
        int $schemaVersion,
        Date $now
    ): string;

    /**
     * Detect the latest recoverable generation for an owner and exact binding.
     *
     * @return  null|array{
     *     continuation_id: string,
     *     generation_id: string,
     *     base_revision: string,
     *     client_revision: int,
     *     payload_schema_version: int,
     *     updated_at: string,
     *     expires_at: string
     * }
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function detect(int $userId, string $context, string $targetId, Date $now): ?array;

    /**
     * Discard an owner-bound generation without consulting its provider.
     *
     * @return  string  discarded or idempotent
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function discard(int $userId, string $continuationId, string $generationId, Date $now): string;
}
