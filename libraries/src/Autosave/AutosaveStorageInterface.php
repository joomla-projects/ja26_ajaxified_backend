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
     * Minimum cleanup limit that permits progress in every dependency layer.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const MIN_PURGE_LIMIT = 3;

    /**
     * Default maximum number of physical rows removed by one cleanup invocation.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const DEFAULT_PURGE_LIMIT = 100;

    /**
     * Hard maximum number of physical rows removed by one cleanup invocation.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const MAX_PURGE_LIMIT = 1000;

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

    /**
     * Expire dormant drafts and physically remove retained historical data.
     *
     * The physical deletion count across canonical actions, generations and
     * continuations never exceeds the supplied limit.
     *
     * @return  array{
     *     generations_expired: int,
     *     closed_generations_released: int,
     *     canonical_actions_deleted: int,
     *     generations_deleted: int,
     *     continuations_deleted: int
     * }
     *
     * @since   __DEPLOY_VERSION__
     */
    public function purgeRetainedData(Date $now, int $limit = self::DEFAULT_PURGE_LIMIT): array;

    /**
     * Preserve the exact submitted snapshot, close its generation, and create
     * or return one idempotent canonical action operation.
     *
     * @return  array{operation_id: string, intent: string, outcome: string, expires_at: string}
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function prepareCanonicalAction(
        int $userId,
        string $continuationId,
        string $generationId,
        string $context,
        string $targetId,
        string $baseRevision,
        int $clientRevision,
        array $payload,
        int $schemaVersion,
        string $intent,
        Date $now
    ): array;

    /**
     * Inspect one owner-bound canonical action without exposing draft data.
     *
     * @return  array{
     *     operation_id: string,
     *     context: string,
     *     target_id: string,
     *     continuation_id: string,
     *     generation_id: string,
     *     intent: string,
     *     outcome: string,
     *     expected_base_revision: string,
     *     final_target_id: ?string,
     *     final_base_revision: ?string,
     *     failure_code: ?string,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string,
     *     completed_at: ?string
     * }
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function inspectCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        Date $now
    ): array;

    /**
     * Verify that one operation can authorize the submitted canonical task.
     *
     * @return  array{operation_id: string, intent: string, outcome: string, expected_base_revision: string}
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function verifyCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $currentBaseRevision,
        Date $now
    ): array;

    /**
     * Record authoritative success and retire only the submitted generation.
     *
     * @return  array{operation_id: string, outcome: string, final_target_id: string, final_base_revision: string}
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function finalizeCanonicalActionSuccess(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $finalTargetId,
        string $finalBaseRevision,
        Date $now
    ): array;

    /**
     * Record a definitive canonical failure while retaining the closed draft.
     *
     * @return  array{operation_id: string, outcome: string, failure_code: string}
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function finalizeCanonicalActionFailure(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $failureCode,
        Date $now
    ): array;
}
