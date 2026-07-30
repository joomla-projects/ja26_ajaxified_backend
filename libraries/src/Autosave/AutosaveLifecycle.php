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
 * Component-neutral orchestration of provider and persistence operations.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveLifecycle
{
    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(
        private readonly AutosaveContextResolver $resolver,
        private readonly AutosaveStorageInterface $storage
    ) {
    }

    /**
     * Initialize an owner-bound draft for an exact component context.
     *
     * @return  array{
     *     continuation_id: string,
     *     generation_id: string,
     *     context: string,
     *     target_id: string,
     *     base_revision: string,
     *     payload_schema_version: int
     * }
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function initialize(
        User $user,
        string $context,
        string $targetId,
        string $initializationKey,
        Date $now
    ): array {
        $userId          = $this->validateInvocation($user, $now);
        $provider        = $this->resolver->resolve($context);
        $canonicalTarget = $provider->canonicalizeTargetId($targetId);

        if (!$provider->targetExists($canonicalTarget)) {
            throw new AutosaveException('target_not_found', 'The Autosave target was not found.');
        }

        $provider->authorize($user, $canonicalTarget, AutosaveOperation::Initialize);

        $baseRevision  = $provider->getBaseRevision($canonicalTarget);
        $schemaVersion = $provider->getPayloadSchemaVersion();
        $identities    = $this->storage->initialize(
            $userId,
            $context,
            $canonicalTarget,
            $baseRevision,
            $initializationKey,
            $now
        );

        return [
            'continuation_id'        => $identities['continuation_id'],
            'generation_id'          => $identities['generation_id'],
            'context'                => $context,
            'target_id'              => $canonicalTarget,
            'base_revision'          => $baseRevision,
            'payload_schema_version' => $schemaVersion,
        ];
    }

    /**
     * Preserve one provider-normalized snapshot.
     *
     * @return  array{status: 'accepted'|'idempotent'}
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function preserve(
        User $user,
        string $continuationId,
        string $generationId,
        int $clientRevision,
        mixed $payload,
        int $schemaVersion,
        Date $now
    ): array {
        $userId     = $this->validateInvocation($user, $now);
        $generation = $this->storage->inspect($userId, $continuationId, $generationId, $now);
        $provider   = $this->resolver->resolve($generation['context']);

        $provider->authorize($user, $generation['target_id'], AutosaveOperation::Preserve);

        if ($provider->getPayloadSchemaVersion() !== $schemaVersion) {
            throw new AutosaveException(
                'unsupported_schema_version',
                'The Autosave payload schema version is not supported.'
            );
        }

        $normalizedPayload = $provider->normalizePayload($payload, $schemaVersion);
        $status            = $this->storage->preserve(
            $userId,
            $continuationId,
            $generationId,
            $generation['context'],
            $generation['target_id'],
            $generation['base_revision'],
            $clientRevision,
            $normalizedPayload,
            $schemaVersion,
            $now
        );

        return ['status' => $status];
    }

    /**
     * Detect the latest recoverable draft for an exact component target.
     *
     * @return  null|array{
     *     continuation_id: string,
     *     generation_id: string,
     *     context: string,
     *     target_id: string,
     *     base_revision: string,
     *     current_base_revision: string,
     *     classification: 'current'|'stale',
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
    public function detect(User $user, string $context, string $targetId, Date $now): ?array
    {
        $userId          = $this->validateInvocation($user, $now);
        $provider        = $this->resolver->resolve($context);
        $canonicalTarget = $provider->canonicalizeTargetId($targetId);

        if (!$provider->targetExists($canonicalTarget)) {
            throw new AutosaveException('target_not_found', 'The Autosave target was not found.');
        }

        $provider->authorize($user, $canonicalTarget, AutosaveOperation::Detect);

        $generation = $this->storage->detect($userId, $context, $canonicalTarget, $now);

        if ($generation === null) {
            return null;
        }

        $currentRevision = $provider->getBaseRevision($canonicalTarget);

        return [
            'continuation_id'        => $generation['continuation_id'],
            'generation_id'          => $generation['generation_id'],
            'context'                => $context,
            'target_id'              => $canonicalTarget,
            'base_revision'          => $generation['base_revision'],
            'current_base_revision'  => $currentRevision,
            'classification'         => $generation['base_revision'] === $currentRevision ? 'current' : 'stale',
            'client_revision'        => $generation['client_revision'],
            'payload_schema_version' => $generation['payload_schema_version'],
            'updated_at'             => $generation['updated_at'],
            'expires_at'             => $generation['expires_at'],
        ];
    }

    /**
     * Read one authorized owner-bound draft and derive canonical staleness.
     *
     * @return  array{
     *     continuation_id: string,
     *     generation_id: string,
     *     context: string,
     *     target_id: string,
     *     base_revision: string,
     *     current_base_revision: string,
     *     classification: 'current'|'stale',
     *     client_revision: int,
     *     payload_schema_version: ?int,
     *     created_at: string,
     *     updated_at: string,
     *     expires_at: string,
     *     payload: ?array
     * }
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function read(User $user, string $continuationId, string $generationId, Date $now): array
    {
        $userId     = $this->validateInvocation($user, $now);
        $generation = $this->storage->inspect($userId, $continuationId, $generationId, $now);
        $provider   = $this->resolver->resolve($generation['context']);

        $provider->authorize($user, $generation['target_id'], AutosaveOperation::Read);

        $currentRevision = $provider->getBaseRevision($generation['target_id']);

        return [
            'continuation_id'        => $generation['continuation_id'],
            'generation_id'          => $generation['generation_id'],
            'context'                => $generation['context'],
            'target_id'              => $generation['target_id'],
            'base_revision'          => $generation['base_revision'],
            'current_base_revision'  => $currentRevision,
            'classification'         => $generation['base_revision'] === $currentRevision ? 'current' : 'stale',
            'client_revision'        => $generation['client_revision'],
            'payload_schema_version' => $generation['payload_schema_version'],
            'created_at'             => $generation['created_at'],
            'updated_at'             => $generation['updated_at'],
            'expires_at'             => $generation['expires_at'],
            'payload'                => $generation['payload'],
        ];
    }

    /**
     * Discard an owner-bound draft without booting its component.
     *
     * @return  array{status: 'discarded'|'idempotent'}
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function discard(User $user, string $continuationId, string $generationId, Date $now): array
    {
        $userId = $this->validateInvocation($user, $now);

        return [
            'status' => $this->storage->discard($userId, $continuationId, $generationId, $now),
        ];
    }

    /**
     * Validate common authenticated invocation values and return the owner identity.
     */
    private function validateInvocation(User $user, Date $now): int
    {
        $userId = (int) $user->id;

        if ($userId <= 0 || $now->getOffset() !== 0) {
            throw new \InvalidArgumentException('The Autosave lifecycle invocation is invalid.');
        }

        return $userId;
    }
}
