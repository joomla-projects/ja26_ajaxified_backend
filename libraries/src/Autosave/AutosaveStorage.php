<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Date\Date;
use Joomla\Crypt\Crypt;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;
use Symfony\Component\OptionsResolver\OptionsResolver;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Component-neutral persistence for Autosave continuations and generations.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveStorage implements AutosaveCreateStorageInterface, AutosaveStaticScopeStorageInterface
{
    private const MAX_INSERT_ATTEMPTS         = 3;
    private const MAX_ID_ATTEMPTS             = 3;
    private const MAX_PAYLOAD_BYTES           = 16777215;
    private const MAX_QUOTA_SLOTS             = 2147483647;
    private const MAX_CANONICAL_OPERATION_TTL = 86400;
    private const PAYLOAD_DIGEST_DOMAIN       = 'autosave:payload-digest:v1';
    private const INSERT_PHASE_NONE           = 'none';
    private const INSERT_PHASE_CONTINUATION   = 'continuation_insert';
    private const INSERT_PHASE_GENERATION     = 'generation_insert';

    /**
     * Validated storage policy.
     *
     * @var array{
     *     idle_ttl: int,
     *     max_lifetime: int,
     *     tombstone_retention: int,
     *     max_active_generations: int,
     *     max_payload_bytes: int
     * }
     */
    private array $policy;

    /**
     * Constructor.
     *
     * @param   DatabaseInterface  $db      The database connection.
     * @param   array              $policy  The expiry, quota and payload policy.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private DatabaseInterface $db, array $policy)
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(
            [
                'idle_ttl',
                'max_lifetime',
                'tombstone_retention',
                'max_active_generations',
                'max_payload_bytes',
            ]
        );
        $positiveInteger = static fn (int $value): bool => $value > 0;

        foreach (
            [
                'idle_ttl',
                'max_lifetime',
                'tombstone_retention',
                'max_active_generations',
                'max_payload_bytes',
            ] as $option
        ) {
            $resolver
                ->setAllowedTypes($option, 'int')
                ->setAllowedValues($option, $positiveInteger);
        }

        try {
            $this->policy = $resolver->resolve($policy);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        if ($this->policy['idle_ttl'] > $this->policy['max_lifetime']) {
            throw new \InvalidArgumentException('The idle TTL cannot exceed the maximum lifetime.');
        }

        if ($this->policy['max_payload_bytes'] > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('The Autosave payload limit exceeds portable storage.');
        }

        if ($this->policy['max_active_generations'] > self::MAX_QUOTA_SLOTS) {
            throw new \InvalidArgumentException('The Autosave generation quota exceeds portable storage.');
        }
    }

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
    ): array {
        $this->validateInitialization($userId, $context, $targetId, $baseRevision, $initializationKey, $now);
        $lastCollisionFailure = null;

        for ($attempt = 1; $attempt <= self::MAX_INSERT_ATTEMPTS; $attempt++) {
            $transactionStarted   = false;
            $phase                = self::INSERT_PHASE_NONE;
            $continuationPublicId = null;
            $generationPublicId   = null;
            $quotaSlot            = null;

            try {
                $this->db->transactionStart();
                $transactionStarted = true;

                $existing = $this->getExistingInitialization($userId, $initializationKey);

                if ($existing !== null) {
                    $result = $this->resolveExistingInitialization(
                        $existing,
                        $userId,
                        $context,
                        $targetId,
                        $baseRevision,
                        $now
                    );
                    $this->db->transactionCommit();
                    $transactionStarted = false;

                    if ($result instanceof AutosaveException) {
                        throw $result;
                    }

                    return $result;
                }

                $this->expireEligibleGenerations($userId, $now);
                $quotaSlot = $this->getAvailableQuotaSlot($userId);

                if ($quotaSlot === null) {
                    throw $this->failure(
                        'draft_limit_reached',
                        'The active Autosave draft limit has been reached.'
                    );
                }

                [$continuationPublicId, $generationPublicId] = $this->generatePublicIdentities();
                $nowSql                                      = $now->toSql();
                $expiresAt                                   = $this->getInitialExpiry($now)->toSql();

                $phase = self::INSERT_PHASE_CONTINUATION;
                $this->insertContinuation(
                    $continuationPublicId,
                    $userId,
                    $context,
                    $targetId,
                    $initializationKey,
                    $nowSql
                );
                $phase          = self::INSERT_PHASE_NONE;
                $continuationId = (int) $this->db->insertid();

                $phase = self::INSERT_PHASE_GENERATION;
                $this->insertGeneration(
                    $generationPublicId,
                    $continuationId,
                    $userId,
                    $baseRevision,
                    $quotaSlot,
                    $nowSql,
                    $expiresAt
                );
                $phase = self::INSERT_PHASE_NONE;

                $this->db->transactionCommit();
                $transactionStarted = false;

                return [
                    'continuation_id' => $continuationPublicId,
                    'generation_id'   => $generationPublicId,
                ];
            } catch (ExecutionFailureException $exception) {
                if ($transactionStarted) {
                    $this->db->transactionRollback();
                    $transactionStarted = false;
                }

                if (
                    !\in_array($phase, [self::INSERT_PHASE_CONTINUATION, self::INSERT_PHASE_GENERATION], true)
                    || !$this->isUniqueViolation($exception)
                ) {
                    throw $exception;
                }

                $existing = $this->getExistingInitialization($userId, $initializationKey);

                if ($existing !== null) {
                    return $this->resolveExistingInitializationAfterRace(
                        $userId,
                        $context,
                        $targetId,
                        $baseRevision,
                        $initializationKey,
                        $now
                    );
                }

                $recognizedCollision = false;

                if ($phase === self::INSERT_PHASE_CONTINUATION) {
                    $recognizedCollision = $continuationPublicId !== null
                        && $this->publicIdExists('#__autosave_continuations', $continuationPublicId);
                } elseif ($phase === self::INSERT_PHASE_GENERATION) {
                    $recognizedCollision = (
                        $generationPublicId !== null
                        && $this->publicIdExists('#__autosave_generations', $generationPublicId)
                    ) || (
                        $quotaSlot !== null
                        && $this->quotaSlotIsOccupied($userId, $quotaSlot)
                    );
                }

                if (!$recognizedCollision) {
                    throw $exception;
                }

                $lastCollisionFailure = $exception;

                if ($attempt === self::MAX_INSERT_ATTEMPTS) {
                    $this->throwAfterCollisionExhaustion($userId, $now, $lastCollisionFailure);
                }
            } catch (\Throwable $exception) {
                if ($transactionStarted) {
                    $this->db->transactionRollback();
                }

                throw $exception;
            }
        }

        throw $lastCollisionFailure ?? new \RuntimeException('Autosave initialization did not complete.');
    }

    /**
     * Inspect one recoverable owner-bound generation.
     *
     * @return  array<string, mixed>
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function inspect(int $userId, string $continuationId, string $generationId, Date $now): array
    {
        $this->validateOwnedOperation($userId, $continuationId, $generationId, $now);
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $generation         = $this->loadOwnedGeneration($userId, $continuationId, $generationId);

            if ($generation === null) {
                throw $this->privateNotFound();
            }

            if (
                $generation['state'] === GenerationState::Active->value
                && $this->isExpired($generation, $now)
            ) {
                if ($this->terminalize($generationId, $userId, GenerationState::Expired, $now) !== 1) {
                    throw new \RuntimeException('The Autosave generation changed during expiry.');
                }

                $this->db->transactionCommit();
                $transactionStarted = false;

                throw $this->failure('draft_expired', 'The Autosave draft has expired.');
            }

            if ($generation['state'] !== GenerationState::Active->value) {
                throw $this->failure('draft_terminal', 'The Autosave draft is no longer active.');
            }

            $generation['client_revision']        = (int) $generation['client_revision'];
            $generation['payload_schema_version'] = $generation['payload_schema_version'] === null
                ? null
                : (int) $generation['payload_schema_version'];
            $generation['payload'] = $generation['payload'] === null
                ? null
                : $this->decodePayload($generation['payload']);
            unset($generation['payload_digest']);

            $this->db->transactionCommit();
            $transactionStarted = false;

            return $generation;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Anchor one canonical static creation scope onto an owner-bound continuation.
     *
     * The scope is immutable for the lifetime of the continuation: when a scope is already
     * anchored the previously anchored value is returned unchanged, so the caller can
     * detect a retry that attempts to initialize the same lineage with a different scope.
     *
     * @return  string|null
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function bindContinuationStaticScope(int $userId, string $continuationId, string $canonicalScope, Date $now): ?string
    {
        $this->validateStaticScope($canonicalScope);
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $current            = $this->loadContinuationScope($userId, $continuationId);

            if ($current !== null) {
                $this->db->transactionCommit();
                $transactionStarted = false;

                return $current;
            }

            // Compare-and-set: only an unbound continuation may be anchored, so two
            // concurrent initializations of the same lineage can never overwrite each
            // other's scope. The losing caller observes the committed scope below and the
            // lifecycle rejects the mismatch.
            $query = $this->db->createQuery()
                ->update($this->db->quoteName('#__autosave_continuations'))
                ->set($this->db->quoteName('create_scope') . ' = :scope')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('public_id') . ' = :continuation_id')
                ->where($this->db->quoteName('create_scope') . ' IS NULL')
                ->bind(':scope', $canonicalScope)
                ->bind(':user_id', $userId, ParameterType::INTEGER)
                ->bind(':continuation_id', $continuationId);

            $this->db->setQuery($query)->execute();

            if ((int) $this->db->getAffectedRows() !== 1) {
                $existing = $this->loadContinuationScope($userId, $continuationId);

                if ($existing === null) {
                    throw $this->failure('draft_not_found', 'The Autosave continuation was not found.');
                }

                $this->db->transactionCommit();
                $transactionStarted = false;

                return $existing;
            }

            $this->db->transactionCommit();
            $transactionStarted = false;

            return null;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Recover the anchored canonical static creation scope of a continuation.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getContinuationStaticScope(int $userId, string $continuationId): ?string
    {
        return $this->loadContinuationScope($userId, $continuationId);
    }

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
    ): string {
        $this->validateOwnedOperation($userId, $continuationId, $generationId, $now);
        $this->validateBinding($context, $targetId, $baseRevision);

        if ($clientRevision < 1 || $schemaVersion < 1) {
            throw new \InvalidArgumentException('The Autosave revision values are invalid.');
        }

        $encodedPayload     = $this->encodePayload($payload, $schemaVersion);
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $generation         = $this->loadOwnedGeneration($userId, $continuationId, $generationId);

            if ($generation === null) {
                throw $this->privateNotFound();
            }

            if (
                $generation['state'] === GenerationState::Active->value
                && $this->isExpired($generation, $now)
            ) {
                if ($this->terminalize($generationId, $userId, GenerationState::Expired, $now) !== 1) {
                    throw new \RuntimeException('The Autosave generation changed during expiry.');
                }

                $this->db->transactionCommit();
                $transactionStarted = false;

                throw $this->failure('draft_expired', 'The Autosave draft has expired.');
            }

            if ($generation['state'] !== GenerationState::Active->value) {
                throw $this->failure('draft_terminal', 'The Autosave draft is no longer active.');
            }

            if ($generation['context'] !== $context || $generation['target_id'] !== $targetId) {
                throw $this->privateNotFound();
            }

            if (!hash_equals($generation['base_revision'], $baseRevision)) {
                throw $this->failure(
                    'base_revision_conflict',
                    'The Autosave base revision does not match.'
                );
            }

            $storedRevision = (int) $generation['client_revision'];
            $storedSchema   = $generation['payload_schema_version'] === null
                ? null
                : (int) $generation['payload_schema_version'];

            if ($clientRevision < $storedRevision) {
                throw $this->failure(
                    'stale_client_revision',
                    'The Autosave client revision is stale.'
                );
            }

            if ($clientRevision === $storedRevision) {
                if ($storedSchema !== $schemaVersion) {
                    throw $this->failure(
                        'schema_version_conflict',
                        'The Autosave payload schema version does not match.'
                    );
                }

                if (
                    $generation['payload_digest'] !== null
                    && hash_equals($generation['payload_digest'], $encodedPayload['digest'])
                    && $generation['payload'] === $encodedPayload['encoded']
                ) {
                    $this->db->transactionCommit();
                    $transactionStarted = false;

                    return 'idempotent';
                }

                throw $this->failure(
                    'revision_conflict',
                    'The Autosave client revision contains different draft content.'
                );
            }

            if ($storedSchema !== null && $storedSchema !== $schemaVersion) {
                throw $this->failure(
                    'schema_version_conflict',
                    'The Autosave payload schema version does not match.'
                );
            }

            $nowSql      = $now->toSql();
            $expiresAt   = $this->getRenewedExpiry($generation['created_at'], $now)->toSql();
            $activeState = GenerationState::Active->value;
            $query       = $this->db->createQuery()
                ->update($this->db->quoteName('#__autosave_generations'))
                ->set(
                    [
                        $this->db->quoteName('client_revision') . ' = :client_revision',
                        $this->db->quoteName('payload') . ' = :payload',
                        $this->db->quoteName('payload_digest') . ' = :payload_digest',
                        $this->db->quoteName('payload_schema_version') . ' = :schema_version',
                        $this->db->quoteName('updated_at') . ' = :updated_at',
                        $this->db->quoteName('expires_at') . ' = :expires_at',
                    ]
                )
                ->where($this->db->quoteName('public_id') . ' = :generation_id')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('state') . ' = :active_state')
                ->where($this->db->quoteName('client_revision') . ' = :stored_revision')
                ->where($this->db->quoteName('base_revision') . ' = :stored_base_revision')
                ->where($this->db->quoteName('expires_at') . ' > :operation_time')
                ->bind(':client_revision', $clientRevision, ParameterType::INTEGER)
                ->bind(':payload', $encodedPayload['encoded'])
                ->bind(':payload_digest', $encodedPayload['digest'])
                ->bind(':schema_version', $schemaVersion, ParameterType::INTEGER)
                ->bind(':updated_at', $nowSql)
                ->bind(':expires_at', $expiresAt)
                ->bind(':generation_id', $generationId)
                ->bind(':user_id', $userId, ParameterType::INTEGER)
                ->bind(':active_state', $activeState)
                ->bind(':stored_revision', $storedRevision, ParameterType::INTEGER)
                ->bind(':stored_base_revision', $generation['base_revision'])
                ->bind(':operation_time', $nowSql);

            $this->db->setQuery($query)->execute();

            if ((int) $this->db->getAffectedRows() !== 1) {
                $current = $this->loadOwnedGeneration($userId, $continuationId, $generationId);

                if ($current !== null && $current['state'] === GenerationState::Closed->value) {
                    throw $this->failure('draft_closed', 'The Autosave draft was closed for a canonical action.');
                }

                throw new \RuntimeException('The Autosave generation changed during preservation.');
            }

            $activityQuery = $this->db->createQuery()
                ->update($this->db->quoteName('#__autosave_continuations'))
                ->set($this->db->quoteName('last_activity_at') . ' = :last_activity_at')
                ->where($this->db->quoteName('public_id') . ' = :continuation_id')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->bind(':last_activity_at', $nowSql)
                ->bind(':continuation_id', $continuationId)
                ->bind(':user_id', $userId, ParameterType::INTEGER);

            $this->db->setQuery($activityQuery)->execute();
            $this->db->transactionCommit();
            $transactionStarted = false;

            return 'accepted';
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Detect the latest recoverable generation for an owner and exact binding.
     *
     * @return  ?array<string, mixed>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function detect(int $userId, string $context, string $targetId, Date $now): ?array
    {
        if ($userId <= 0 || $now->getOffset() !== 0) {
            throw new \InvalidArgumentException('The Autosave detection values are invalid.');
        }

        if (AutosaveContext::getComponentName($context) === null) {
            throw new \InvalidArgumentException('The Autosave context is invalid.');
        }

        $this->validateOpaqueString($targetId, 191, 'target identity');
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $this->expireEligibleGenerations($userId, $now);

            $activeState = GenerationState::Active->value;
            $nowSql      = $now->toSql();
            $query       = $this->db->createQuery()
                ->select(
                    [
                        $this->db->quoteName('c.public_id', 'continuation_id'),
                        $this->db->quoteName('g.public_id', 'generation_id'),
                        $this->db->quoteName('g.base_revision'),
                        $this->db->quoteName('g.client_revision'),
                        $this->db->quoteName('g.payload_schema_version'),
                        $this->db->quoteName('g.updated_at'),
                        $this->db->quoteName('g.expires_at'),
                    ]
                )
                ->from($this->db->quoteName('#__autosave_continuations', 'c'))
                ->join(
                    'INNER',
                    $this->db->quoteName('#__autosave_generations', 'g')
                    . ' ON ' . $this->db->quoteName('g.continuation_id') . ' = ' . $this->db->quoteName('c.id')
                )
                ->where($this->db->quoteName('c.user_id') . ' = :continuation_user_id')
                ->where($this->db->quoteName('g.user_id') . ' = :generation_user_id')
                ->where($this->db->quoteName('c.context') . ' = :context')
                ->where($this->db->quoteName('c.target_id') . ' = :target_id')
                ->where($this->db->quoteName('g.state') . ' = :active_state')
                ->where($this->db->quoteName('g.client_revision') . ' > 0')
                ->where($this->db->quoteName('g.payload') . ' IS NOT NULL')
                ->where($this->db->quoteName('g.expires_at') . ' > :operation_time')
                ->order(
                    [
                        $this->db->quoteName('g.updated_at') . ' DESC',
                        $this->db->quoteName('g.id') . ' DESC',
                    ]
                )
                ->setLimit(1)
                ->bind(':continuation_user_id', $userId, ParameterType::INTEGER)
                ->bind(':generation_user_id', $userId, ParameterType::INTEGER)
                ->bind(':context', $context)
                ->bind(':target_id', $targetId)
                ->bind(':active_state', $activeState)
                ->bind(':operation_time', $nowSql);

            $result = $this->db->setQuery($query)->loadAssoc() ?: null;

            if ($result !== null) {
                $result['client_revision']        = (int) $result['client_revision'];
                $result['payload_schema_version'] = (int) $result['payload_schema_version'];
            }

            $this->db->transactionCommit();
            $transactionStarted = false;

            return $result;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Discard an owner-bound generation without consulting its provider.
     *
     * @return  string  discarded or idempotent
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function discard(int $userId, string $continuationId, string $generationId, Date $now): string
    {
        $this->validateOwnedOperation($userId, $continuationId, $generationId, $now);
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $generation         = $this->loadOwnedGeneration($userId, $continuationId, $generationId);

            if ($generation === null) {
                throw $this->privateNotFound();
            }

            if (
                $generation['state'] === GenerationState::Active->value
                && $this->isExpired($generation, $now)
            ) {
                if ($this->terminalize($generationId, $userId, GenerationState::Expired, $now) !== 1) {
                    throw new \RuntimeException('The Autosave generation changed during expiry.');
                }

                $this->db->transactionCommit();
                $transactionStarted = false;

                return 'idempotent';
            }

            if ($generation['state'] !== GenerationState::Active->value) {
                $this->db->transactionCommit();
                $transactionStarted = false;

                return 'idempotent';
            }

            if ($this->terminalize($generationId, $userId, GenerationState::Discarded, $now) !== 1) {
                throw new \RuntimeException('The Autosave generation changed during discard.');
            }

            $this->db->transactionCommit();
            $transactionStarted = false;

            return 'discarded';
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Expire dormant drafts and physically remove retained historical data.
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
    public function purgeRetainedData(Date $now, int $limit = self::DEFAULT_PURGE_LIMIT): array
    {
        if ($limit < self::MIN_PURGE_LIMIT || $limit > self::MAX_PURGE_LIMIT) {
            throw new \InvalidArgumentException('The Autosave cleanup limit is invalid.');
        }

        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;

            $generationsExpired = $this->expireDormantGenerations($now, $limit);
            $canonicalLimit     = max(1, intdiv($limit, 3));
            $canonicalResult    = $this->purgeExpiredCanonicalActions($now, $canonicalLimit);
            $released           = $canonicalResult['generations_released'];
            $actionsDeleted     = $canonicalResult['actions_deleted'];
            $remaining          = $limit - $actionsDeleted;
            $generationLimit    = max(1, intdiv($remaining, 2));
            $generationsDeleted = $remaining > 0
                ? $this->deleteRetainedGenerations($now, $generationLimit)
                : 0;
            $remaining -= $generationsDeleted;
            $continuationsDeleted = $remaining > 0
                ? $this->deleteRetainedContinuations($now, $remaining)
                : 0;
            $remaining -= $continuationsDeleted;

            if ($remaining > 0) {
                $canonicalResult = $this->purgeExpiredCanonicalActions($now, $remaining);
                $released += $canonicalResult['generations_released'];
                $actionsDeleted += $canonicalResult['actions_deleted'];
                $remaining -= $canonicalResult['actions_deleted'];
            }

            if ($remaining > 0) {
                $deleted = $this->deleteRetainedGenerations($now, $remaining);
                $generationsDeleted += $deleted;
                $remaining -= $deleted;
            }

            if ($remaining > 0) {
                $deleted = $this->deleteRetainedContinuations($now, $remaining);
                $continuationsDeleted += $deleted;
            }

            $this->db->transactionCommit();
            $transactionStarted = false;

            return [
                'generations_expired'         => $generationsExpired,
                'closed_generations_released' => $released,
                'canonical_actions_deleted'   => $actionsDeleted,
                'generations_deleted'         => $generationsDeleted,
                'continuations_deleted'       => $continuationsDeleted,
            ];
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Preserve the exact submitted snapshot and durably close its generation.
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
    ): array {
        $this->validateOwnedOperation($userId, $continuationId, $generationId, $now);
        $this->validateBinding($context, $targetId, $baseRevision);
        $this->validateCanonicalIntent($intent);

        if ($clientRevision < 1 || $schemaVersion < 1) {
            throw new \InvalidArgumentException('The Autosave canonical revision values are invalid.');
        }

        $encodedPayload     = $this->encodePayload($payload, $schemaVersion);
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $generation         = $this->loadOwnedGeneration($userId, $continuationId, $generationId);

            if ($generation === null) {
                throw $this->privateNotFound();
            }

            if ($generation['context'] !== $context || $generation['target_id'] !== $targetId) {
                throw $this->privateNotFound();
            }

            if (!hash_equals($generation['base_revision'], $baseRevision)) {
                throw $this->failure('base_revision_conflict', 'The Autosave base revision does not match.');
            }

            if ($generation['state'] === GenerationState::Closed->value) {
                $operation = $this->loadCanonicalActionByGeneration((int) $generation['generation_pk'], $userId);

                if (
                    $operation === null
                    || $operation['intent'] !== $intent
                    || (int) $generation['client_revision'] !== $clientRevision
                    || (int) $generation['payload_schema_version'] !== $schemaVersion
                    || !hash_equals((string) $generation['payload_digest'], $encodedPayload['digest'])
                    || $generation['payload'] !== $encodedPayload['encoded']
                ) {
                    throw $this->failure(
                        'canonical_action_conflict',
                        'The Autosave canonical action conflicts with an existing preparation.'
                    );
                }

                $this->db->transactionCommit();
                $transactionStarted = false;

                return $this->formatPreparedCanonicalAction($operation);
            }

            if ($generation['state'] !== GenerationState::Active->value) {
                throw $this->failure('draft_terminal', 'The Autosave draft is no longer mutable.');
            }

            if ($this->isExpired($generation, $now)) {
                if ($this->terminalize($generationId, $userId, GenerationState::Expired, $now) !== 1) {
                    throw new \RuntimeException('The Autosave generation changed during expiry.');
                }

                $this->db->transactionCommit();
                $transactionStarted = false;

                throw $this->failure('draft_expired', 'The Autosave draft has expired.');
            }

            $storedRevision = (int) $generation['client_revision'];
            $storedSchema   = $generation['payload_schema_version'] === null
                ? null
                : (int) $generation['payload_schema_version'];

            if ($clientRevision < $storedRevision) {
                throw $this->failure('stale_client_revision', 'The Autosave client revision is stale.');
            }

            if (
                $clientRevision === $storedRevision
                && ($storedSchema !== $schemaVersion
                    || $generation['payload_digest'] === null
                    || !hash_equals($generation['payload_digest'], $encodedPayload['digest'])
                    || $generation['payload'] !== $encodedPayload['encoded'])
            ) {
                throw $this->failure(
                    'revision_conflict',
                    'The Autosave client revision contains different draft content.'
                );
            }

            if ($storedSchema !== null && $storedSchema !== $schemaVersion) {
                throw $this->failure(
                    'schema_version_conflict',
                    'The Autosave payload schema version does not match.'
                );
            }

            $nowSql      = $now->toSql();
            $activeState = GenerationState::Active->value;
            $closedState = GenerationState::Closed->value;
            $close       = $this->db->createQuery()
                ->update($this->db->quoteName('#__autosave_generations'))
                ->set(
                    [
                        $this->db->quoteName('state') . ' = :closed_state',
                        $this->db->quoteName('client_revision') . ' = :client_revision',
                        $this->db->quoteName('payload') . ' = :payload',
                        $this->db->quoteName('payload_digest') . ' = :payload_digest',
                        $this->db->quoteName('payload_schema_version') . ' = :schema_version',
                        $this->db->quoteName('updated_at') . ' = :updated_at',
                        $this->db->quoteName('closed_at') . ' = :closed_at',
                        $this->db->quoteName('active_marker') . ' = NULL',
                        $this->db->quoteName('quota_slot') . ' = NULL',
                    ]
                )
                ->where($this->db->quoteName('public_id') . ' = :generation_id')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('state') . ' = :active_state')
                ->where($this->db->quoteName('client_revision') . ' = :stored_revision')
                ->where($this->db->quoteName('base_revision') . ' = :base_revision')
                ->bind(':closed_state', $closedState)
                ->bind(':client_revision', $clientRevision, ParameterType::INTEGER)
                ->bind(':payload', $encodedPayload['encoded'])
                ->bind(':payload_digest', $encodedPayload['digest'])
                ->bind(':schema_version', $schemaVersion, ParameterType::INTEGER)
                ->bind(':updated_at', $nowSql)
                ->bind(':closed_at', $nowSql)
                ->bind(':generation_id', $generationId)
                ->bind(':user_id', $userId, ParameterType::INTEGER)
                ->bind(':active_state', $activeState)
                ->bind(':stored_revision', $storedRevision, ParameterType::INTEGER)
                ->bind(':base_revision', $baseRevision);

            $this->db->setQuery($close)->execute();

            if ((int) $this->db->getAffectedRows() !== 1) {
                throw $this->failure(
                    'canonical_action_conflict',
                    'The Autosave draft changed while preparing the canonical action.'
                );
            }

            $operation = $this->insertCanonicalAction(
                (int) $generation['continuation_pk'],
                (int) $generation['generation_pk'],
                $userId,
                $context,
                $targetId,
                $intent,
                $baseRevision,
                $now
            );

            $this->db->transactionCommit();
            $transactionStarted = false;

            return $this->formatPreparedCanonicalAction($operation);
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Inspect metadata-only canonical action state.
     */
    public function inspectCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        Date $now
    ): array {
        return $this->formatCanonicalAction(
            $this->inspectCanonicalActionRecord($userId, $operationId, $context, $targetId, $now)
        );
    }

    /**
     * Inspect one canonical action while retaining internal generation state.
     */
    private function inspectCanonicalActionRecord(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        Date $now
    ): array {
        $this->validateCanonicalOperationIdentity($userId, $operationId, $context, $targetId, $now);
        $operation = $this->loadCanonicalAction($operationId, $userId);

        if (
            $operation === null
            || $operation['context'] !== $context
            || $operation['target_id'] !== $targetId
        ) {
            throw $this->canonicalActionNotFound();
        }

        if (
            $operation['outcome'] === CanonicalActionState::Pending->value
            && new Date($operation['expires_at'], 'UTC') <= $now
        ) {
            $unknown = CanonicalActionState::Unknown->value;
            $pending = CanonicalActionState::Pending->value;
            $nowSql  = $now->toSql();
            $query   = $this->db->createQuery()
                ->update($this->db->quoteName('#__autosave_canonical_actions'))
                ->set(
                    [
                        $this->db->quoteName('outcome') . ' = :unknown',
                        $this->db->quoteName('updated_at') . ' = :updated_at',
                        $this->db->quoteName('completed_at') . ' = :completed_at',
                    ]
                )
                ->where($this->db->quoteName('public_id') . ' = :operation_id')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('outcome') . ' = :pending')
                ->bind(':unknown', $unknown)
                ->bind(':updated_at', $nowSql)
                ->bind(':completed_at', $nowSql)
                ->bind(':operation_id', $operationId)
                ->bind(':user_id', $userId, ParameterType::INTEGER)
                ->bind(':pending', $pending);
            $this->db->setQuery($query)->execute();
            $operation = $this->loadCanonicalAction($operationId, $userId);
        }

        return $operation;
    }

    /**
     * Verify an operation before Joomla's canonical controller is invoked.
     */
    public function verifyCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $currentBaseRevision,
        Date $now
    ): array {
        $this->validateCanonicalIntent($intent);
        $operation = $this->inspectCanonicalActionRecord($userId, $operationId, $context, $targetId, $now);

        if ($operation['intent'] !== $intent) {
            throw $this->failure('canonical_intent_conflict', 'The canonical action intent does not match.');
        }

        if ($operation['generation_state'] !== GenerationState::Closed->value) {
            throw $this->failure(
                'canonical_generation_not_closed',
                'The canonical action generation is not durably closed.'
            );
        }

        if ($operation['outcome'] !== CanonicalActionState::Pending->value) {
            throw $this->failure('canonical_action_consumed', 'The canonical action is no longer pending.');
        }

        if (!hash_equals($operation['expected_base_revision'], $currentBaseRevision)) {
            throw $this->failure('base_revision_conflict', 'The canonical base revision changed before submission.');
        }

        return [
            'operation_id'           => $operation['operation_id'],
            'intent'                 => $operation['intent'],
            'outcome'                => $operation['outcome'],
            'expected_base_revision' => $operation['expected_base_revision'],
        ];
    }

    public function verifyCreateCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $intent,
        string $currentBaseRevision,
        Date $now
    ): array {
        $this->validateCanonicalIntent($intent);
        $this->validateOpaqueString($operationId, 64, 'canonical operation identity');

        if ($userId <= 0 || AutosaveContext::getComponentName($context) === null || $now->getOffset() !== 0) {
            throw new \InvalidArgumentException('The Autosave canonical action identity is invalid.');
        }

        $operation = $this->loadCanonicalAction($operationId, $userId);

        if ($operation === null || $operation['context'] !== $context) {
            throw $this->canonicalActionNotFound();
        }

        try {
            AutosaveTargetIdentity::requireProvisional($operation['target_id']);
        } catch (\InvalidArgumentException) {
            throw $this->canonicalActionNotFound();
        }

        $operation = $this->inspectCanonicalActionRecord(
            $userId,
            $operationId,
            $context,
            $operation['target_id'],
            $now
        );

        if ($operation['intent'] !== $intent) {
            throw $this->failure('canonical_intent_conflict', 'The canonical action intent does not match.');
        }

        if ($operation['generation_state'] !== GenerationState::Closed->value) {
            throw $this->failure('canonical_generation_not_closed', 'The canonical action generation is not durably closed.');
        }

        if ($operation['outcome'] !== CanonicalActionState::Pending->value) {
            throw $this->failure('canonical_action_consumed', 'The canonical action is no longer pending.');
        }

        if (!hash_equals($operation['expected_base_revision'], $currentBaseRevision)) {
            throw $this->failure('base_revision_conflict', 'The create contract changed before submission.');
        }

        return [
            'operation_id'           => $operation['operation_id'],
            'intent'                 => $operation['intent'],
            'outcome'                => $operation['outcome'],
            'expected_base_revision' => $operation['expected_base_revision'],
            'target_id'              => $operation['target_id'],
            'payload'                => $this->loadCanonicalActionPayload(
                (int) $operation['generation_pk'],
                $userId
            ),
        ];
    }

    /**
     * Atomically record canonical success and retire the submitted generation.
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
    ): array {
        $this->validateCanonicalIntent($intent);
        $this->validateCanonicalOperationIdentity($userId, $operationId, $context, $targetId, $now);
        $this->validateOpaqueString($finalTargetId, 764, 'final target');
        $this->validateOpaqueString($finalBaseRevision, 1020, 'final base revision');
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $operation          = $this->loadCanonicalAction($operationId, $userId);

            if (
                $operation === null
                || $operation['context'] !== $context
                || $operation['target_id'] !== $targetId
            ) {
                throw $this->canonicalActionNotFound();
            }

            if ($operation['intent'] !== $intent) {
                throw $this->failure('canonical_intent_conflict', 'The canonical action intent does not match.');
            }

            if ($operation['outcome'] === CanonicalActionState::Successful->value) {
                if (
                    $operation['final_target_id'] !== $finalTargetId
                    || !hash_equals((string) $operation['final_base_revision'], $finalBaseRevision)
                ) {
                    throw $this->failure('canonical_action_conflict', 'The canonical completion metadata conflicts.');
                }

                $this->db->transactionCommit();
                $transactionStarted = false;

                return $this->formatCanonicalSuccess($operation);
            }

            if ($operation['outcome'] !== CanonicalActionState::Pending->value) {
                throw $this->failure('canonical_action_consumed', 'The canonical action is no longer pending.');
            }

            $nowSql     = $now->toSql();
            $successful = CanonicalActionState::Successful->value;
            $pending    = CanonicalActionState::Pending->value;
            $complete   = $this->db->createQuery()
                ->update($this->db->quoteName('#__autosave_canonical_actions'))
                ->set(
                    [
                        $this->db->quoteName('outcome') . ' = :successful',
                        $this->db->quoteName('final_target_id') . ' = :final_target_id',
                        $this->db->quoteName('final_base_revision') . ' = :final_base_revision',
                        $this->db->quoteName('updated_at') . ' = :updated_at',
                        $this->db->quoteName('completed_at') . ' = :completed_at',
                    ]
                )
                ->where($this->db->quoteName('public_id') . ' = :operation_id')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('outcome') . ' = :pending')
                ->bind(':successful', $successful)
                ->bind(':final_target_id', $finalTargetId)
                ->bind(':final_base_revision', $finalBaseRevision)
                ->bind(':updated_at', $nowSql)
                ->bind(':completed_at', $nowSql)
                ->bind(':operation_id', $operationId)
                ->bind(':user_id', $userId, ParameterType::INTEGER)
                ->bind(':pending', $pending);
            $this->db->setQuery($complete)->execute();

            if ((int) $this->db->getAffectedRows() !== 1) {
                throw new \RuntimeException('The canonical action changed during successful finalization.');
            }

            $closed       = GenerationState::Closed->value;
            $retired      = GenerationState::Retired->value;
            $generationPk = (int) $operation['generation_pk'];
            $retainUntil  = (clone $now)
                ->add(new \DateInterval('PT' . $this->policy['tombstone_retention'] . 'S'))
                ->toSql();
            $retire = $this->db->createQuery()
                ->update($this->db->quoteName('#__autosave_generations'))
                ->set(
                    [
                        $this->db->quoteName('state') . ' = :retired',
                        $this->db->quoteName('payload') . ' = NULL',
                        $this->db->quoteName('payload_digest') . ' = NULL',
                        $this->db->quoteName('updated_at') . ' = :updated_at',
                        $this->db->quoteName('terminal_at') . ' = :terminal_at',
                        $this->db->quoteName('retain_until') . ' = :retain_until',
                    ]
                )
                ->where($this->db->quoteName('id') . ' = :generation_pk')
                ->where($this->db->quoteName('user_id') . ' = :user_id')
                ->where($this->db->quoteName('state') . ' = :closed')
                ->bind(':retired', $retired)
                ->bind(':updated_at', $nowSql)
                ->bind(':terminal_at', $nowSql)
                ->bind(':retain_until', $retainUntil)
                ->bind(':generation_pk', $generationPk, ParameterType::INTEGER)
                ->bind(':user_id', $userId, ParameterType::INTEGER)
                ->bind(':closed', $closed);
            $this->db->setQuery($retire)->execute();

            if ((int) $this->db->getAffectedRows() !== 1) {
                throw new \RuntimeException('The submitted Autosave generation was not retired.');
            }

            $this->db->transactionCommit();
            $transactionStarted = false;
            $operation          = $this->loadCanonicalAction($operationId, $userId);

            return $this->formatCanonicalSuccess($operation);
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Record definitive canonical failure without reopening the generation.
     */
    public function finalizeCanonicalActionFailure(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $failureCode,
        Date $now
    ): array {
        $this->validateCanonicalIntent($intent);
        $this->validateCanonicalOperationIdentity($userId, $operationId, $context, $targetId, $now);
        $this->validateOpaqueString($failureCode, 64, 'canonical failure code');
        $operation = $this->loadCanonicalAction($operationId, $userId);

        if (
            $operation === null
            || $operation['context'] !== $context
            || $operation['target_id'] !== $targetId
        ) {
            throw $this->canonicalActionNotFound();
        }

        if ($operation['intent'] !== $intent) {
            throw $this->failure('canonical_intent_conflict', 'The canonical action intent does not match.');
        }

        if ($operation['outcome'] === CanonicalActionState::Failed->value) {
            return [
                'operation_id' => $operationId,
                'outcome'      => CanonicalActionState::Failed->value,
                'failure_code' => (string) $operation['failure_code'],
            ];
        }

        if ($operation['outcome'] !== CanonicalActionState::Pending->value) {
            throw $this->failure('canonical_action_consumed', 'The canonical action is no longer pending.');
        }

        $failed  = CanonicalActionState::Failed->value;
        $pending = CanonicalActionState::Pending->value;
        $nowSql  = $now->toSql();
        $query   = $this->db->createQuery()
            ->update($this->db->quoteName('#__autosave_canonical_actions'))
            ->set(
                [
                    $this->db->quoteName('outcome') . ' = :failed',
                    $this->db->quoteName('failure_code') . ' = :failure_code',
                    $this->db->quoteName('updated_at') . ' = :updated_at',
                    $this->db->quoteName('completed_at') . ' = :completed_at',
                ]
            )
            ->where($this->db->quoteName('public_id') . ' = :operation_id')
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('outcome') . ' = :pending')
            ->bind(':failed', $failed)
            ->bind(':failure_code', $failureCode)
            ->bind(':updated_at', $nowSql)
            ->bind(':completed_at', $nowSql)
            ->bind(':operation_id', $operationId)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':pending', $pending);
        $this->db->setQuery($query)->execute();

        if ((int) $this->db->getAffectedRows() !== 1) {
            throw new \RuntimeException('The canonical action changed during failure finalization.');
        }

        return [
            'operation_id' => $operationId,
            'outcome'      => $failed,
            'failure_code' => $failureCode,
        ];
    }

    /**
     * Validate initialization values before opening a transaction.
     */
    private function validateInitialization(
        int $userId,
        string $context,
        string $targetId,
        string $baseRevision,
        string $initializationKey,
        Date $now
    ): void {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Autosave requires an authenticated owner.');
        }

        if (AutosaveContext::getComponentName($context) === null) {
            throw new \InvalidArgumentException('The Autosave context is invalid.');
        }

        $this->validateOpaqueString($targetId, 191, 'target identity');
        $this->validateOpaqueString($baseRevision, 255, 'base revision');
        $this->validateOpaqueString($initializationKey, 191, 'initialization key');

        if ($now->getOffset() !== 0) {
            throw new \InvalidArgumentException('The Autosave operation time must use UTC.');
        }
    }

    /**
     * Validate an exact context/target/revision binding.
     */
    private function validateBinding(string $context, string $targetId, string $baseRevision): void
    {
        if (AutosaveContext::getComponentName($context) === null) {
            throw new \InvalidArgumentException('The Autosave context is invalid.');
        }

        $this->validateOpaqueString($targetId, 191, 'target identity');
        $this->validateOpaqueString($baseRevision, 255, 'base revision');
    }

    /**
     * Validate an opaque value without rewriting it.
     */
    private function validateOpaqueString(string $value, int $limit, string $name): void
    {
        if (
            $value === ''
            || preg_match('//u', $value) !== 1
            || StringHelper::strlen($value) > $limit
            || preg_match('/^[\p{Z}\s]+$/u', $value) === 1
            || preg_match('/\p{Cc}/u', $value) === 1
        ) {
            throw new \InvalidArgumentException(\sprintf('The Autosave %s is invalid.', $name));
        }
    }

    /**
     * Validate common owner-bound operation values.
     */
    private function validateOwnedOperation(
        int $userId,
        string $continuationId,
        string $generationId,
        Date $now
    ): void {
        if (
            $userId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $continuationId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $generationId) !== 1
            || hash_equals($continuationId, $generationId)
            || $now->getOffset() !== 0
        ) {
            throw new \InvalidArgumentException('The Autosave draft identity is invalid.');
        }
    }

    /**
     * Validate one normalized canonical intent.
     */
    private function validateCanonicalIntent(string $intent): void
    {
        if (!\in_array($intent, ['apply', 'save-exit', 'save-new', 'save-copy'], true)) {
            throw new \InvalidArgumentException('The Autosave canonical intent is invalid.');
        }
    }

    /**
     * Validate metadata-only operation identity and binding values.
     */
    private function validateCanonicalOperationIdentity(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        Date $now
    ): void {
        if (
            $userId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $operationId) !== 1
            || $now->getOffset() !== 0
        ) {
            throw new \InvalidArgumentException('The Autosave canonical operation identity is invalid.');
        }

        $this->validateOpaqueString($context, 255, 'context');
        $this->validateOpaqueString($targetId, 764, 'target');
    }

    /**
     * Encode a normalized JSON-compatible payload deterministically.
     *
     * @return  array{encoded: string, digest: string}
     */
    private function encodePayload(array $payload, int $schemaVersion): array
    {
        $payload = $this->canonicalizePayloadValue($payload);

        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The normalized Autosave payload cannot be encoded.', 0, $exception);
        }

        if (\strlen($encoded) > $this->policy['max_payload_bytes']) {
            throw $this->failure('payload_too_large', 'The Autosave draft payload is too large.');
        }

        $digestInput = self::PAYLOAD_DIGEST_DOMAIN
            . "\0" . $schemaVersion
            . "\0" . \strlen($encoded)
            . "\0" . $encoded;

        return [
            'encoded' => $encoded,
            'digest'  => hash('sha256', $digestInput),
        ];
    }

    /**
     * Validate and canonicalize one JSON-compatible payload value.
     */
    private function canonicalizePayloadValue(mixed $value): mixed
    {
        if (\is_array($value)) {
            foreach ($value as $key => $entry) {
                $value[$key] = $this->canonicalizePayloadValue($entry);
            }

            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return $value;
        }

        if (\is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('The normalized Autosave payload contains invalid UTF-8.');
            }

            return $value;
        }

        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('The normalized Autosave payload contains a non-finite number.');
        }

        if ($value === null || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('The normalized Autosave payload contains an unsupported value.');
    }

    /**
     * Decode a stored deterministic payload.
     */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The stored Autosave payload is invalid.', 0, $exception);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException('The stored Autosave payload is invalid.');
        }

        return $decoded;
    }

    /**
     * Generate distinct independent public identities.
     *
     * @return  array{string, string}
     */
    private function generatePublicIdentities(): array
    {
        $continuationId = bin2hex(Crypt::genRandomBytes(32));

        for ($attempt = 1; $attempt <= self::MAX_ID_ATTEMPTS; $attempt++) {
            $generationId = bin2hex(Crypt::genRandomBytes(32));

            if (!hash_equals($continuationId, $generationId)) {
                return [$continuationId, $generationId];
            }
        }

        throw new \RuntimeException('Unable to generate distinct Autosave public identities.');
    }

    /**
     * Load an initialization by its exact owner-scoped key.
     */
    private function getExistingInitialization(int $userId, string $initializationKey): ?array
    {
        $query = $this->db->createQuery()
            ->select(
                [
                    $this->db->quoteName('c.public_id', 'continuation_id'),
                    $this->db->quoteName('c.context'),
                    $this->db->quoteName('c.target_id'),
                    $this->db->quoteName('g.public_id', 'generation_id'),
                    $this->db->quoteName('g.base_revision'),
                    $this->db->quoteName('g.state'),
                    $this->db->quoteName('g.expires_at'),
                ]
            )
            ->from($this->db->quoteName('#__autosave_continuations', 'c'))
            ->join(
                'INNER',
                $this->db->quoteName('#__autosave_generations', 'g')
                . ' ON ' . $this->db->quoteName('g.continuation_id') . ' = ' . $this->db->quoteName('c.id')
            )
            ->where($this->db->quoteName('c.user_id') . ' = :user_id')
            ->where($this->db->quoteName('c.initialization_key') . ' = :initialization_key')
            ->order($this->db->quoteName('g.id') . ' DESC')
            ->setLimit(1)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':initialization_key', $initializationKey);

        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * Resolve an existing initialization inside an active transaction.
     *
     * @return  array{continuation_id: string, generation_id: string}|AutosaveException
     */
    private function resolveExistingInitialization(
        array $existing,
        int $userId,
        string $context,
        string $targetId,
        string $baseRevision,
        Date $now
    ): array|AutosaveException {
        if (
            $existing['context'] !== $context
            || $existing['target_id'] !== $targetId
            || $existing['base_revision'] !== $baseRevision
        ) {
            return $this->failure(
                'initialization_conflict',
                'The Autosave initialization key is already bound to different inputs.'
            );
        }

        if (
            $existing['state'] === GenerationState::Active->value
            && $this->isExpired($existing, $now)
        ) {
            if ($this->terminalize($existing['generation_id'], $userId, GenerationState::Expired, $now) !== 1) {
                throw new \RuntimeException('The Autosave generation changed during expiry.');
            }

            return $this->failure('draft_expired', 'The Autosave draft has expired.');
        }

        if ($existing['state'] === GenerationState::Expired->value) {
            return $this->failure('draft_expired', 'The Autosave draft has expired.');
        }

        if ($existing['state'] !== GenerationState::Active->value) {
            return $this->failure('draft_terminal', 'The Autosave draft is no longer active.');
        }

        return [
            'continuation_id' => $existing['continuation_id'],
            'generation_id'   => $existing['generation_id'],
        ];
    }

    /**
     * Resolve a same-key race in a fresh transaction.
     *
     * @return  array{continuation_id: string, generation_id: string}
     */
    private function resolveExistingInitializationAfterRace(
        int $userId,
        string $context,
        string $targetId,
        string $baseRevision,
        string $initializationKey,
        Date $now
    ): array {
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $existing           = $this->getExistingInitialization($userId, $initializationKey);

            if ($existing === null) {
                throw new \RuntimeException('The competing Autosave initialization is unavailable.');
            }

            $result = $this->resolveExistingInitialization(
                $existing,
                $userId,
                $context,
                $targetId,
                $baseRevision,
                $now
            );
            $this->db->transactionCommit();
            $transactionStarted = false;

            if ($result instanceof AutosaveException) {
                throw $result;
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }
    }

    /**
     * Recheck quota after the final recognized collision and preserve the real failure otherwise.
     */
    private function throwAfterCollisionExhaustion(
        int $userId,
        Date $now,
        ExecutionFailureException $failure
    ): never {
        $transactionStarted = false;

        try {
            $this->db->transactionStart();
            $transactionStarted = true;
            $this->expireEligibleGenerations($userId, $now);
            $quotaAvailable = $this->getAvailableQuotaSlot($userId) !== null;
            $this->db->transactionCommit();
            $transactionStarted = false;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $this->db->transactionRollback();
            }

            throw $exception;
        }

        if (!$quotaAvailable) {
            throw $this->failure('draft_limit_reached', 'The active Autosave draft limit has been reached.');
        }

        throw $failure;
    }

    /**
     * Load one exact owner-bound generation and continuation.
     */
    private function loadOwnedGeneration(int $userId, string $continuationId, string $generationId): ?array
    {
        $query = $this->db->createQuery()
            ->select(
                [
                    $this->db->quoteName('c.id', 'continuation_pk'),
                    $this->db->quoteName('c.public_id', 'continuation_id'),
                    $this->db->quoteName('c.context'),
                    $this->db->quoteName('c.target_id'),
                    $this->db->quoteName('g.id', 'generation_pk'),
                    $this->db->quoteName('g.public_id', 'generation_id'),
                    $this->db->quoteName('g.base_revision'),
                    $this->db->quoteName('g.state'),
                    $this->db->quoteName('g.client_revision'),
                    $this->db->quoteName('g.payload'),
                    $this->db->quoteName('g.payload_digest'),
                    $this->db->quoteName('g.payload_schema_version'),
                    $this->db->quoteName('g.created_at'),
                    $this->db->quoteName('g.updated_at'),
                    $this->db->quoteName('g.expires_at'),
                    $this->db->quoteName('g.closed_at'),
                    $this->db->quoteName('g.terminal_at'),
                    $this->db->quoteName('g.retain_until'),
                ]
            )
            ->from($this->db->quoteName('#__autosave_continuations', 'c'))
            ->join(
                'INNER',
                $this->db->quoteName('#__autosave_generations', 'g')
                . ' ON ' . $this->db->quoteName('g.continuation_id') . ' = ' . $this->db->quoteName('c.id')
            )
            ->where($this->db->quoteName('c.public_id') . ' = :continuation_id')
            ->where($this->db->quoteName('g.public_id') . ' = :generation_id')
            ->where($this->db->quoteName('c.user_id') . ' = :continuation_user_id')
            ->where($this->db->quoteName('g.user_id') . ' = :generation_user_id')
            ->bind(':continuation_id', $continuationId)
            ->bind(':generation_id', $generationId)
            ->bind(':continuation_user_id', $userId, ParameterType::INTEGER)
            ->bind(':generation_user_id', $userId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * Insert one opaque canonical action for a newly closed generation.
     */
    private function insertCanonicalAction(
        int $continuationPk,
        int $generationPk,
        int $userId,
        string $context,
        string $targetId,
        string $intent,
        string $baseRevision,
        Date $now
    ): array {
        $operationId = bin2hex(Crypt::genRandomBytes(32));
        $nowSql      = $now->toSql();
        $expiresAt   = (clone $now)
            ->add(
                new \DateInterval(
                    'PT' . min($this->policy['idle_ttl'], self::MAX_CANONICAL_OPERATION_TTL) . 'S'
                )
            )
            ->toSql();
        $pending = CanonicalActionState::Pending->value;
        $query   = $this->db->createQuery()
            ->insert($this->db->quoteName('#__autosave_canonical_actions'))
            ->columns(
                [
                    $this->db->quoteName('public_id'),
                    $this->db->quoteName('user_id'),
                    $this->db->quoteName('continuation_id'),
                    $this->db->quoteName('generation_id'),
                    $this->db->quoteName('context'),
                    $this->db->quoteName('target_id'),
                    $this->db->quoteName('intent'),
                    $this->db->quoteName('expected_base_revision'),
                    $this->db->quoteName('outcome'),
                    $this->db->quoteName('created_at'),
                    $this->db->quoteName('updated_at'),
                    $this->db->quoteName('expires_at'),
                ]
            )
            ->values(
                ':public_id, :user_id, :continuation_id, :generation_id, :context, :target_id, '
                . ':intent, :expected_base_revision, :outcome, :created_at, :updated_at, :expires_at'
            )
            ->bind(':public_id', $operationId)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':continuation_id', $continuationPk, ParameterType::INTEGER)
            ->bind(':generation_id', $generationPk, ParameterType::INTEGER)
            ->bind(':context', $context)
            ->bind(':target_id', $targetId)
            ->bind(':intent', $intent)
            ->bind(':expected_base_revision', $baseRevision)
            ->bind(':outcome', $pending)
            ->bind(':created_at', $nowSql)
            ->bind(':updated_at', $nowSql)
            ->bind(':expires_at', $expiresAt);
        $this->db->setQuery($query)->execute();

        return $this->loadCanonicalAction($operationId, $userId)
            ?? throw new \RuntimeException('The canonical action could not be loaded after insertion.');
    }

    /**
     * Load one canonical action by its opaque owner-bound identity.
     */
    private function loadCanonicalAction(string $operationId, int $userId): ?array
    {
        $query = $this->canonicalActionQuery()
            ->where($this->db->quoteName('o.public_id') . ' = :operation_id')
            ->where($this->db->quoteName('o.user_id') . ' = :user_id')
            ->bind(':operation_id', $operationId)
            ->bind(':user_id', $userId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    /** Load the immutable submitted payload only for trusted create verification. */
    private function loadCanonicalActionPayload(int $generationId, int $userId): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('payload'))
            ->from($this->db->quoteName('#__autosave_generations'))
            ->where($this->db->quoteName('id') . ' = :generation_id')
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->bind(':generation_id', $generationId, ParameterType::INTEGER)
            ->bind(':user_id', $userId, ParameterType::INTEGER);
        $payload = $this->db->setQuery($query)->loadResult();

        if (!\is_string($payload)) {
            throw $this->canonicalActionNotFound();
        }

        return $this->decodePayload($payload);
    }

    /**
     * Load the idempotent action owned by one generation.
     */
    private function loadCanonicalActionByGeneration(int $generationPk, int $userId): ?array
    {
        $query = $this->canonicalActionQuery()
            ->where($this->db->quoteName('o.generation_id') . ' = :generation_id')
            ->where($this->db->quoteName('o.user_id') . ' = :user_id')
            ->bind(':generation_id', $generationPk, ParameterType::INTEGER)
            ->bind(':user_id', $userId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * Build the metadata-only canonical action query.
     */
    private function canonicalActionQuery()
    {
        return $this->db->createQuery()
            ->select(
                [
                    $this->db->quoteName('o.public_id', 'operation_id'),
                    $this->db->quoteName('o.user_id'),
                    $this->db->quoteName('o.generation_id', 'generation_pk'),
                    $this->db->quoteName('c.public_id', 'continuation_id'),
                    $this->db->quoteName('g.public_id', 'generation_id'),
                    $this->db->quoteName('g.state', 'generation_state'),
                    $this->db->quoteName('o.context'),
                    $this->db->quoteName('o.target_id'),
                    $this->db->quoteName('o.intent'),
                    $this->db->quoteName('o.expected_base_revision'),
                    $this->db->quoteName('o.outcome'),
                    $this->db->quoteName('o.final_target_id'),
                    $this->db->quoteName('o.final_base_revision'),
                    $this->db->quoteName('o.failure_code'),
                    $this->db->quoteName('o.created_at'),
                    $this->db->quoteName('o.updated_at'),
                    $this->db->quoteName('o.expires_at'),
                    $this->db->quoteName('o.completed_at'),
                ]
            )
            ->from($this->db->quoteName('#__autosave_canonical_actions', 'o'))
            ->join(
                'INNER',
                $this->db->quoteName('#__autosave_generations', 'g')
                . ' ON ' . $this->db->quoteName('g.id') . ' = ' . $this->db->quoteName('o.generation_id')
            )
            ->join(
                'INNER',
                $this->db->quoteName('#__autosave_continuations', 'c')
                . ' ON ' . $this->db->quoteName('c.id') . ' = ' . $this->db->quoteName('o.continuation_id')
            );
    }

    /**
     * Format a successful preparation without exposing its payload.
     */
    private function formatPreparedCanonicalAction(array $operation): array
    {
        return [
            'operation_id' => $operation['operation_id'],
            'intent'       => $operation['intent'],
            'outcome'      => $operation['outcome'],
            'expires_at'   => $operation['expires_at'],
        ];
    }

    /**
     * Format metadata-only operation state.
     */
    private function formatCanonicalAction(array $operation): array
    {
        return [
            'operation_id'           => $operation['operation_id'],
            'context'                => $operation['context'],
            'target_id'              => $operation['target_id'],
            'continuation_id'        => $operation['continuation_id'],
            'generation_id'          => $operation['generation_id'],
            'intent'                 => $operation['intent'],
            'outcome'                => $operation['outcome'],
            'expected_base_revision' => $operation['expected_base_revision'],
            'final_target_id'        => $operation['final_target_id'],
            'final_base_revision'    => $operation['final_base_revision'],
            'failure_code'           => $operation['failure_code'],
            'created_at'             => $operation['created_at'],
            'updated_at'             => $operation['updated_at'],
            'expires_at'             => $operation['expires_at'],
            'completed_at'           => $operation['completed_at'],
        ];
    }

    /**
     * Format idempotent successful completion metadata.
     */
    private function formatCanonicalSuccess(array $operation): array
    {
        return [
            'operation_id'        => $operation['operation_id'],
            'outcome'             => CanonicalActionState::Successful->value,
            'final_target_id'     => $operation['final_target_id'],
            'final_base_revision' => $operation['final_base_revision'],
        ];
    }

    /**
     * Return a privacy-preserving missing operation failure.
     */
    private function canonicalActionNotFound(): AutosaveException
    {
        return $this->failure('canonical_action_not_found', 'The canonical action was not found.');
    }

    /**
     * Expire a bounded set of active generations without requiring owner activity.
     */
    private function expireDormantGenerations(Date $now, int $limit): int
    {
        $activeState = GenerationState::Active->value;
        $nowSql      = $now->toSql();
        $query       = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__autosave_generations'))
            ->where($this->db->quoteName('state') . ' = :active_state')
            ->where($this->db->quoteName('expires_at') . ' <= :expires_at')
            ->order(
                [
                    $this->db->quoteName('expires_at') . ' ASC',
                    $this->db->quoteName('id') . ' ASC',
                ]
            )
            ->bind(':active_state', $activeState)
            ->bind(':expires_at', $nowSql);
        $ids = array_map('intval', $this->db->setQuery($query, 0, $limit)->loadColumn());

        if ($ids === []) {
            return 0;
        }

        $expiredState = GenerationState::Expired->value;
        $retainUntil  = (clone $now)
            ->add(new \DateInterval('PT' . $this->policy['tombstone_retention'] . 'S'))
            ->toSql();
        $update = $this->db->createQuery()
            ->update($this->db->quoteName('#__autosave_generations'))
            ->set(
                [
                    $this->db->quoteName('state') . ' = :expired_state',
                    $this->db->quoteName('payload') . ' = NULL',
                    $this->db->quoteName('payload_digest') . ' = NULL',
                    $this->db->quoteName('updated_at') . ' = :updated_at',
                    $this->db->quoteName('terminal_at') . ' = :terminal_at',
                    $this->db->quoteName('retain_until') . ' = :retain_until',
                    $this->db->quoteName('active_marker') . ' = NULL',
                    $this->db->quoteName('quota_slot') . ' = NULL',
                ]
            )
            ->whereIn($this->db->quoteName('id'), $ids, ParameterType::INTEGER)
            ->where($this->db->quoteName('state') . ' = :active_state')
            ->where($this->db->quoteName('expires_at') . ' <= :expires_at')
            ->bind(':expired_state', $expiredState)
            ->bind(':updated_at', $nowSql)
            ->bind(':terminal_at', $nowSql)
            ->bind(':retain_until', $retainUntil)
            ->bind(':active_state', $activeState)
            ->bind(':expires_at', $nowSql);
        $this->db->setQuery($update)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Select a bounded set of canonical operations after their authoritative expiry.
     *
     * @return  array<int, array{id: int|string, generation_id: int|string}>
     */
    private function getExpiredCanonicalActions(Date $now, int $limit): array
    {
        $nowSql   = $now->toSql();
        $outcomes = array_map(
            static fn (CanonicalActionState $state): string => $state->value,
            CanonicalActionState::cases()
        );
        $query = $this->db->createQuery()
            ->select(
                [
                    $this->db->quoteName('id'),
                    $this->db->quoteName('generation_id'),
                ]
            )
            ->from($this->db->quoteName('#__autosave_canonical_actions'))
            ->whereIn($this->db->quoteName('outcome'), $outcomes, ParameterType::STRING)
            ->where($this->db->quoteName('expires_at') . ' <= :expires_at')
            ->order(
                [
                    $this->db->quoteName('expires_at') . ' ASC',
                    $this->db->quoteName('id') . ' ASC',
                ]
            )
            ->bind(':expires_at', $nowSql);

        return $this->db->setQuery($query, 0, $limit)->loadAssocList();
    }

    /**
     * Release and delete one bounded set of expired canonical actions.
     *
     * @return  array{generations_released: int, actions_deleted: int}
     */
    private function purgeExpiredCanonicalActions(Date $now, int $limit): array
    {
        $canonicalActions = $this->getExpiredCanonicalActions($now, $limit);
        $generationIds    = array_values(
            array_unique(array_map('intval', array_column($canonicalActions, 'generation_id')))
        );
        $released         = $this->releaseClosedCanonicalGenerations($generationIds, $now);
        $actionIds        = array_map('intval', array_column($canonicalActions, 'id'));

        return [
            'generations_released' => $released,
            'actions_deleted'      => $this->deleteExpiredCanonicalActions($actionIds, $now),
        ];
    }

    /**
     * Clear closed draft payloads whose canonical protection window elapsed.
     */
    private function releaseClosedCanonicalGenerations(array $generationIds, Date $now): int
    {
        if ($generationIds === []) {
            return 0;
        }

        $closed = GenerationState::Closed->value;
        $nowSql = $now->toSql();
        $query  = $this->db->createQuery()
            ->update($this->db->quoteName('#__autosave_generations'))
            ->set(
                [
                    $this->db->quoteName('payload') . ' = NULL',
                    $this->db->quoteName('payload_digest') . ' = NULL',
                    $this->db->quoteName('updated_at') . ' = :updated_at',
                    $this->db->quoteName('terminal_at') . ' = :terminal_at',
                    $this->db->quoteName('retain_until') . ' = :retain_until',
                ]
            )
            ->whereIn($this->db->quoteName('id'), $generationIds, ParameterType::INTEGER)
            ->where($this->db->quoteName('state') . ' = :closed_state')
            ->where($this->db->quoteName('retain_until') . ' IS NULL')
            ->bind(':updated_at', $nowSql)
            ->bind(':terminal_at', $nowSql)
            ->bind(':retain_until', $nowSql)
            ->bind(':closed_state', $closed);
        $this->db->setQuery($query)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Delete selected canonical actions that remain expired.
     */
    private function deleteExpiredCanonicalActions(array $ids, Date $now): int
    {
        if ($ids === []) {
            return 0;
        }

        $nowSql = $now->toSql();
        $query  = $this->db->createQuery()
            ->delete($this->db->quoteName('#__autosave_canonical_actions'))
            ->whereIn($this->db->quoteName('id'), $ids, ParameterType::INTEGER)
            ->where($this->db->quoteName('expires_at') . ' <= :expires_at')
            ->bind(':expires_at', $nowSql);
        $this->db->setQuery($query)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Delete a bounded set of retained generations with no canonical reference.
     */
    private function deleteRetainedGenerations(Date $now, int $limit): int
    {
        $nowSql = $now->toSql();
        $states = [
            GenerationState::Closed->value,
            GenerationState::Retired->value,
            GenerationState::Discarded->value,
            GenerationState::Expired->value,
        ];
        $reference = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName('#__autosave_canonical_actions', 'o'))
            ->where(
                $this->db->quoteName('o.generation_id')
                . ' = ' . $this->db->quoteName('#__autosave_generations.id')
            );
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__autosave_generations'))
            ->whereIn($this->db->quoteName('state'), $states, ParameterType::STRING)
            ->where($this->db->quoteName('retain_until') . ' IS NOT NULL')
            ->where($this->db->quoteName('retain_until') . ' <= :retain_until')
            ->where('NOT EXISTS (' . $reference . ')')
            ->order(
                [
                    $this->db->quoteName('retain_until') . ' ASC',
                    $this->db->quoteName('id') . ' ASC',
                ]
            )
            ->bind(':retain_until', $nowSql);
        $ids = array_map('intval', $this->db->setQuery($query, 0, $limit)->loadColumn());

        if ($ids === []) {
            return 0;
        }

        $reference = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName('#__autosave_canonical_actions', 'o'))
            ->where(
                $this->db->quoteName('o.generation_id')
                . ' = ' . $this->db->quoteName('#__autosave_generations.id')
            );
        $delete = $this->db->createQuery()
            ->delete($this->db->quoteName('#__autosave_generations'))
            ->whereIn($this->db->quoteName('id'), $ids, ParameterType::INTEGER)
            ->whereIn($this->db->quoteName('state'), $states, ParameterType::STRING)
            ->where($this->db->quoteName('retain_until') . ' IS NOT NULL')
            ->where($this->db->quoteName('retain_until') . ' <= :retain_until')
            ->where('NOT EXISTS (' . $reference . ')')
            ->bind(':retain_until', $nowSql);
        $this->db->setQuery($delete)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Delete a bounded set of old continuations with no surviving references.
     */
    private function deleteRetainedContinuations(Date $now, int $limit): int
    {
        $cutoff = (clone $now)
            ->sub(new \DateInterval('PT' . $this->policy['tombstone_retention'] . 'S'))
            ->toSql();
        $generationReference = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName('#__autosave_generations', 'g'))
            ->where(
                $this->db->quoteName('g.continuation_id')
                . ' = ' . $this->db->quoteName('#__autosave_continuations.id')
            );
        $actionReference = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName('#__autosave_canonical_actions', 'o'))
            ->where(
                $this->db->quoteName('o.continuation_id')
                . ' = ' . $this->db->quoteName('#__autosave_continuations.id')
            );
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__autosave_continuations'))
            ->where($this->db->quoteName('last_activity_at') . ' <= :last_activity_at')
            ->where('NOT EXISTS (' . $generationReference . ')')
            ->where('NOT EXISTS (' . $actionReference . ')')
            ->order(
                [
                    $this->db->quoteName('last_activity_at') . ' ASC',
                    $this->db->quoteName('id') . ' ASC',
                ]
            )
            ->bind(':last_activity_at', $cutoff);
        $ids = array_map('intval', $this->db->setQuery($query, 0, $limit)->loadColumn());

        if ($ids === []) {
            return 0;
        }

        $generationReference = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName('#__autosave_generations', 'g'))
            ->where(
                $this->db->quoteName('g.continuation_id')
                . ' = ' . $this->db->quoteName('#__autosave_continuations.id')
            );
        $actionReference = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName('#__autosave_canonical_actions', 'o'))
            ->where(
                $this->db->quoteName('o.continuation_id')
                . ' = ' . $this->db->quoteName('#__autosave_continuations.id')
            );
        $delete = $this->db->createQuery()
            ->delete($this->db->quoteName('#__autosave_continuations'))
            ->whereIn($this->db->quoteName('id'), $ids, ParameterType::INTEGER)
            ->where($this->db->quoteName('last_activity_at') . ' <= :last_activity_at')
            ->where('NOT EXISTS (' . $generationReference . ')')
            ->where('NOT EXISTS (' . $actionReference . ')')
            ->bind(':last_activity_at', $cutoff);
        $this->db->setQuery($delete)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Expire the bounded active set for one owner.
     */
    private function expireEligibleGenerations(int $userId, Date $now): int
    {
        $expiredState = GenerationState::Expired->value;
        $activeState  = GenerationState::Active->value;
        $nowSql       = $now->toSql();
        $retainUntil  = (clone $now)
            ->add(new \DateInterval('PT' . $this->policy['tombstone_retention'] . 'S'))
            ->toSql();

        $query = $this->db->createQuery()
            ->update($this->db->quoteName('#__autosave_generations'))
            ->set(
                [
                    $this->db->quoteName('state') . ' = :expired_state',
                    $this->db->quoteName('payload') . ' = NULL',
                    $this->db->quoteName('payload_digest') . ' = NULL',
                    $this->db->quoteName('updated_at') . ' = :updated_at',
                    $this->db->quoteName('terminal_at') . ' = :terminal_at',
                    $this->db->quoteName('retain_until') . ' = :retain_until',
                    $this->db->quoteName('active_marker') . ' = NULL',
                    $this->db->quoteName('quota_slot') . ' = NULL',
                ]
            )
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('state') . ' = :active_state')
            ->where($this->db->quoteName('expires_at') . ' <= :expires_at')
            ->bind(':expired_state', $expiredState)
            ->bind(':updated_at', $nowSql)
            ->bind(':terminal_at', $nowSql)
            ->bind(':retain_until', $retainUntil)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':active_state', $activeState)
            ->bind(':expires_at', $nowSql);

        $this->db->setQuery($query)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Find the first free quota slot for one owner.
     */
    private function getAvailableQuotaSlot(int $userId): ?int
    {
        $activeState = GenerationState::Active->value;
        $query       = $this->db->createQuery()
            ->select($this->db->quoteName('quota_slot'))
            ->from($this->db->quoteName('#__autosave_generations'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('state') . ' = :active_state')
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':active_state', $activeState);

        $activeSlots = $this->db->setQuery($query)->loadColumn();

        if (\count($activeSlots) >= $this->policy['max_active_generations']) {
            return null;
        }

        $occupied = [];

        foreach ($activeSlots as $slot) {
            $slot = (int) $slot;

            if ($slot >= 1 && $slot <= $this->policy['max_active_generations']) {
                $occupied[$slot] = true;
            }
        }

        for ($slot = 1; $slot <= $this->policy['max_active_generations']; $slot++) {
            if (!isset($occupied[$slot])) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Terminalize one active generation.
     */
    private function terminalize(
        string $generationId,
        int $userId,
        GenerationState $state,
        Date $now
    ): int {
        if ($state === GenerationState::Active) {
            throw new \LogicException('An Autosave generation cannot be terminalized as active.');
        }

        $terminalState = $state->value;
        $activeState   = GenerationState::Active->value;
        $nowSql        = $now->toSql();
        $retainUntil   = (clone $now)
            ->add(new \DateInterval('PT' . $this->policy['tombstone_retention'] . 'S'))
            ->toSql();
        $query         = $this->db->createQuery()
            ->update($this->db->quoteName('#__autosave_generations'))
            ->set(
                [
                    $this->db->quoteName('state') . ' = :terminal_state',
                    $this->db->quoteName('payload') . ' = NULL',
                    $this->db->quoteName('payload_digest') . ' = NULL',
                    $this->db->quoteName('updated_at') . ' = :updated_at',
                    $this->db->quoteName('active_marker') . ' = NULL',
                    $this->db->quoteName('quota_slot') . ' = NULL',
                    $this->db->quoteName('terminal_at') . ' = :terminal_at',
                    $this->db->quoteName('retain_until') . ' = :retain_until',
                ]
            )
            ->where($this->db->quoteName('public_id') . ' = :generation_id')
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('state') . ' = :active_state')
            ->bind(':terminal_state', $terminalState)
            ->bind(':updated_at', $nowSql)
            ->bind(':terminal_at', $nowSql)
            ->bind(':retain_until', $retainUntil)
            ->bind(':generation_id', $generationId)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':active_state', $activeState);

        $this->db->setQuery($query)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Determine whether a stored deadline has been reached.
     */
    private function isExpired(array $generation, Date $now): bool
    {
        return new Date($generation['expires_at'], 'UTC') <= $now;
    }

    /**
     * Calculate the initial expiry.
     */
    private function getInitialExpiry(Date $now): Date
    {
        return (clone $now)->add(new \DateInterval('PT' . $this->policy['idle_ttl'] . 'S'));
    }

    /**
     * Calculate a renewed expiry bounded by the hard lifetime.
     */
    private function getRenewedExpiry(string $createdAt, Date $now): Date
    {
        $idleExpiry = (clone $now)->add(new \DateInterval('PT' . $this->policy['idle_ttl'] . 'S'));
        $hardExpiry = (new Date($createdAt, 'UTC'))
            ->add(new \DateInterval('PT' . $this->policy['max_lifetime'] . 'S'));

        return $idleExpiry <= $hardExpiry ? $idleExpiry : $hardExpiry;
    }

    /**
     * Identify only supported-driver duplicate-key failures.
     */
    private function isUniqueViolation(ExecutionFailureException $exception): bool
    {
        return match ($this->db->getName()) {
            'mysqli' => (int) $exception->getCode() === 1062,
            'mysql'  => (int) $exception->getCode() === 23000
                && preg_match('/\A23000,\s*1062(?:,|\z)/D', $exception->getMessage()) === 1,
            'pgsql' => (int) $exception->getCode() === 23505,
            default => false,
        };
    }

    /**
     * Test for a committed public-ID collision.
     */
    private function publicIdExists(string $table, string $publicId): bool
    {
        $query = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('public_id') . ' = :public_id')
            ->bind(':public_id', $publicId);

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Test for a committed owner quota-slot collision.
     */
    private function quotaSlotIsOccupied(int $userId, int $quotaSlot): bool
    {
        $query = $this->db->createQuery()
            ->select('1')
            ->from($this->db->quoteName('#__autosave_generations'))
            ->where($this->db->quoteName('user_id') . ' = :user_id')
            ->where($this->db->quoteName('quota_slot') . ' = :quota_slot')
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':quota_slot', $quotaSlot, ParameterType::INTEGER);

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Load the anchored static scope of one owner-bound continuation.
     */
    private function loadContinuationScope(int $userId, string $continuationId): ?string
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('c.create_scope'))
            ->from($this->db->quoteName('#__autosave_continuations', 'c'))
            ->where($this->db->quoteName('c.user_id') . ' = :user_id')
            ->where($this->db->quoteName('c.public_id') . ' = :continuation_id')
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':continuation_id', $continuationId);

        $scope = $this->db->setQuery($query)->loadResult();

        return \is_string($scope) && $scope !== '' ? $scope : null;
    }

    /**
     * Validate one bounded canonical static scope before persistence.
     */
    private function validateStaticScope(string $canonicalScope): void
    {
        if (
            $canonicalScope === ''
            || \strlen($canonicalScope) > 255
            || preg_match('//u', $canonicalScope) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $canonicalScope) === 1
        ) {
            throw $this->failure('invalid_scope', 'The Autosave static creation scope is invalid.');
        }
    }

    /**
     * Insert one continuation.
     */
    private function insertContinuation(
        string $publicId,
        int $userId,
        string $context,
        string $targetId,
        string $initializationKey,
        string $now
    ): void {
        $query = $this->db->createQuery()
            ->insert($this->db->quoteName('#__autosave_continuations'))
            ->columns(
                [
                    $this->db->quoteName('public_id'),
                    $this->db->quoteName('user_id'),
                    $this->db->quoteName('context'),
                    $this->db->quoteName('target_id'),
                    $this->db->quoteName('initialization_key'),
                    $this->db->quoteName('created_at'),
                    $this->db->quoteName('last_activity_at'),
                ]
            )
            ->values(
                ':public_id, :user_id, :context, :target_id, :initialization_key, :created_at, :last_activity_at'
            )
            ->bind(':public_id', $publicId)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':context', $context)
            ->bind(':target_id', $targetId)
            ->bind(':initialization_key', $initializationKey)
            ->bind(':created_at', $now)
            ->bind(':last_activity_at', $now);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Insert one initial active generation.
     */
    private function insertGeneration(
        string $publicId,
        int $continuationId,
        int $userId,
        string $baseRevision,
        int $quotaSlot,
        string $now,
        string $expiresAt
    ): void {
        $activeState    = GenerationState::Active->value;
        $activeMarker   = 1;
        $clientRevision = 0;
        $query          = $this->db->createQuery()
            ->insert($this->db->quoteName('#__autosave_generations'))
            ->columns(
                [
                    $this->db->quoteName('public_id'),
                    $this->db->quoteName('continuation_id'),
                    $this->db->quoteName('user_id'),
                    $this->db->quoteName('base_revision'),
                    $this->db->quoteName('state'),
                    $this->db->quoteName('client_revision'),
                    $this->db->quoteName('payload'),
                    $this->db->quoteName('payload_digest'),
                    $this->db->quoteName('payload_schema_version'),
                    $this->db->quoteName('active_marker'),
                    $this->db->quoteName('quota_slot'),
                    $this->db->quoteName('created_at'),
                    $this->db->quoteName('updated_at'),
                    $this->db->quoteName('expires_at'),
                    $this->db->quoteName('closed_at'),
                    $this->db->quoteName('terminal_at'),
                    $this->db->quoteName('retain_until'),
                ]
            )
            ->values(
                ':public_id, :continuation_id, :user_id, :base_revision, :state, :client_revision, '
                . 'NULL, NULL, NULL, :active_marker, :quota_slot, :created_at, :updated_at, :expires_at, NULL, NULL, NULL'
            )
            ->bind(':public_id', $publicId)
            ->bind(':continuation_id', $continuationId, ParameterType::INTEGER)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':base_revision', $baseRevision)
            ->bind(':state', $activeState)
            ->bind(':client_revision', $clientRevision, ParameterType::INTEGER)
            ->bind(':active_marker', $activeMarker, ParameterType::INTEGER)
            ->bind(':quota_slot', $quotaSlot, ParameterType::INTEGER)
            ->bind(':created_at', $now)
            ->bind(':updated_at', $now)
            ->bind(':expires_at', $expiresAt);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Create a safe transport-neutral failure.
     */
    private function failure(string $code, string $message): AutosaveException
    {
        return new AutosaveException($code, $message);
    }

    /**
     * Return the privacy-preserving missing-draft failure.
     */
    private function privateNotFound(): AutosaveException
    {
        return $this->failure('draft_not_found', 'The Autosave draft was not found.');
    }
}
