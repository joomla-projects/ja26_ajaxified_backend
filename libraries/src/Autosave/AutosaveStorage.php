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
final class AutosaveStorage
{
    private const MAX_INSERT_ATTEMPTS       = 3;
    private const MAX_ID_ATTEMPTS           = 3;
    private const MAX_PAYLOAD_BYTES         = 16777215;
    private const MAX_QUOTA_SLOTS           = 2147483647;
    private const PAYLOAD_DIGEST_DOMAIN     = 'autosave:payload-digest:v1';
    private const INSERT_PHASE_NONE         = 'none';
    private const INSERT_PHASE_CONTINUATION = 'continuation_insert';
    private const INSERT_PHASE_GENERATION   = 'generation_insert';

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
                    $this->db->quoteName('c.public_id', 'continuation_id'),
                    $this->db->quoteName('c.context'),
                    $this->db->quoteName('c.target_id'),
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
                    $this->db->quoteName('terminal_at'),
                    $this->db->quoteName('retain_until'),
                ]
            )
            ->values(
                ':public_id, :continuation_id, :user_id, :base_revision, :state, :client_revision, '
                . 'NULL, NULL, NULL, :active_marker, :quota_slot, :created_at, :updated_at, :expires_at, NULL, NULL'
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
