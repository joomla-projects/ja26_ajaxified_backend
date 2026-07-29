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
 * Storage for Autosave continuations and their generations.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveStorage
{
    /**
     * Maximum number of complete insert attempts.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const MAX_INSERT_ATTEMPTS = 3;

    /**
     * Maximum number of generation public identity candidates.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const MAX_GENERATION_ID_ATTEMPTS = 3;

    /**
     * Validated Autosave policy.
     *
     * @var    array{idle_ttl: integer, max_lifetime: integer, tombstone_retention: integer, max_active_generations: integer}
     * @since  __DEPLOY_VERSION__
     */
    private array $policy;

    /**
     * Constructor.
     *
     * @param   DatabaseInterface  $db      The database connection.
     * @param   array              $policy  The Autosave expiry and quota policy.
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
            ]
        );
        $positiveInteger = static fn (int $value): bool => $value > 0;

        $resolver
            ->setAllowedTypes('idle_ttl', 'int')
            ->setAllowedTypes('max_lifetime', 'int')
            ->setAllowedTypes('tombstone_retention', 'int')
            ->setAllowedTypes('max_active_generations', 'int')
            ->setAllowedValues('idle_ttl', $positiveInteger)
            ->setAllowedValues('max_lifetime', $positiveInteger)
            ->setAllowedValues('tombstone_retention', $positiveInteger)
            ->setAllowedValues('max_active_generations', $positiveInteger);

        try {
            $this->policy = $resolver->resolve($policy);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        if ($this->policy['idle_ttl'] > $this->policy['max_lifetime']) {
            throw new \InvalidArgumentException('The idle TTL cannot exceed the maximum lifetime.');
        }
    }

    /**
     * Create or idempotently return an Autosave continuation and its initial generation.
     *
     * @param   integer  $userId             The authenticated owner identity.
     * @param   string   $context            The exact component record context.
     * @param   string   $targetId           The opaque canonical target identity.
     * @param   string   $baseRevision       The opaque provider revision.
     * @param   string   $initializationKey  The client initialization idempotency key.
     * @param   Date     $now                The operation time in UTC.
     *
     * @return  array{continuation_id: string, generation_id: string}
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

        $firstInsertFailure = null;

        for ($attempt = 1; $attempt <= self::MAX_INSERT_ATTEMPTS; $attempt++) {
            $transactionStarted  = false;
            $inInsertPhase       = false;

            try {
                $this->db->transactionStart();
                $transactionStarted = true;

                $existing = $this->getExistingInitialization($userId, $initializationKey);

                if ($existing !== null) {
                    $result = $this->resolveExistingInitialization($existing, $context, $targetId, $baseRevision);
                    $this->db->transactionCommit();

                    return $result;
                }

                $this->expireEligibleGenerations($userId, $now);
                $quotaSlot = $this->getAvailableQuotaSlot($userId);

                if ($quotaSlot === null) {
                    throw new \OverflowException('The active Autosave generation quota has been reached.');
                }

                $continuationPublicId = bin2hex(Crypt::genRandomBytes(32));
                $generationPublicId   = null;

                for ($identityAttempt = 1; $identityAttempt <= self::MAX_GENERATION_ID_ATTEMPTS; $identityAttempt++) {
                    $candidate = bin2hex(Crypt::genRandomBytes(32));

                    if ($candidate !== $continuationPublicId) {
                        $generationPublicId = $candidate;
                        break;
                    }
                }

                if ($generationPublicId === null) {
                    throw new \RuntimeException('Unable to generate distinct Autosave public identities.');
                }

                $nowSql    = $now->toSql();
                $expiresAt = $this->getInitialExpiry($now)->toSql();

                $inInsertPhase = true;
                $this->insertContinuation(
                    $continuationPublicId,
                    $userId,
                    $context,
                    $targetId,
                    $initializationKey,
                    $nowSql
                );
                $continuationId = (int) $this->db->insertid();

                $this->insertGeneration(
                    $generationPublicId,
                    $continuationId,
                    $userId,
                    $baseRevision,
                    $quotaSlot,
                    $nowSql,
                    $expiresAt
                );
                $inInsertPhase = false;

                $this->db->transactionCommit();

                return [
                    'continuation_id' => $continuationPublicId,
                    'generation_id'   => $generationPublicId,
                ];
            } catch (ExecutionFailureException $exception) {
                if ($transactionStarted) {
                    $this->db->transactionRollback();
                }

                if (!$inInsertPhase) {
                    throw $exception;
                }

                $firstInsertFailure ??= $exception;

                if ($attempt === self::MAX_INSERT_ATTEMPTS) {
                    throw $firstInsertFailure;
                }
            } catch (\Throwable $exception) {
                if ($transactionStarted) {
                    $this->db->transactionRollback();
                }

                throw $exception;
            }
        }

        throw $firstInsertFailure;
    }

    /**
     * Validate initialization inputs before opening a transaction.
     *
     * @param   integer  $userId             The authenticated owner identity.
     * @param   string   $context            The exact component record context.
     * @param   string   $targetId           The opaque canonical target identity.
     * @param   string   $baseRevision       The opaque provider revision.
     * @param   string   $initializationKey  The client initialization idempotency key.
     * @param   Date     $now                The operation time in UTC.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
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

        if (
            StringHelper::strlen($context) > 255
            || preg_match('/^com_[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $context) !== 1
        ) {
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
     * Validate an opaque string without normalizing it.
     *
     * @param   string   $value  The value.
     * @param   integer  $limit  The maximum character count.
     * @param   string   $name   The value name for error reporting.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function validateOpaqueString(string $value, int $limit, string $name): void
    {
        if (
            $value === ''
            || trim($value) === ''
            || StringHelper::strlen($value) > $limit
            || preg_match('//u', $value) !== 1
            || preg_match('/\p{Cc}/u', $value) === 1
        ) {
            throw new \InvalidArgumentException(\sprintf('The Autosave %s is invalid.', $name));
        }
    }

    /**
     * Load an initialization by its owner-scoped idempotency key.
     *
     * @param   integer  $userId             The authenticated owner identity.
     * @param   string   $initializationKey  The initialization key.
     *
     * @return  ?array
     *
     * @since   __DEPLOY_VERSION__
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
            ->order($this->db->quoteName('g.id') . ' ASC')
            ->setLimit(1)
            ->bind(':user_id', $userId, ParameterType::INTEGER)
            ->bind(':initialization_key', $initializationKey);

        $result = $this->db->setQuery($query)->loadAssoc();

        return $result ?: null;
    }

    /**
     * Resolve an existing initialization as an idempotent result or conflict.
     *
     * @param   array   $existing      The stored initialization.
     * @param   string  $context       The requested context.
     * @param   string  $targetId      The requested target identity.
     * @param   string  $baseRevision  The requested base revision.
     *
     * @return  array{continuation_id: string, generation_id: string}
     *
     * @since   __DEPLOY_VERSION__
     */
    private function resolveExistingInitialization(
        array $existing,
        string $context,
        string $targetId,
        string $baseRevision
    ): array {
        if (
            $existing['context'] !== $context
            || $existing['target_id'] !== $targetId
            || $existing['base_revision'] !== $baseRevision
        ) {
            throw new \DomainException('The Autosave initialization key is already bound to different inputs.');
        }

        return [
            'continuation_id' => $existing['continuation_id'],
            'generation_id'   => $existing['generation_id'],
        ];
    }

    /**
     * Expire eligible active generations and release their quota slots.
     *
     * @param   integer  $userId  The authenticated owner identity.
     * @param   Date     $now     The operation time.
     *
     * @return  integer  The number of expired generations.
     *
     * @since   __DEPLOY_VERSION__
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
     * Find the first unoccupied quota slot for an owner.
     *
     * @param   integer  $userId  The authenticated owner identity.
     *
     * @return  ?integer
     *
     * @since   __DEPLOY_VERSION__
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
     * Calculate the initial generation expiry.
     *
     * @param   Date  $now  The operation time.
     *
     * @return  Date
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getInitialExpiry(Date $now): Date
    {
        $idleExpiry = (clone $now)->add(new \DateInterval('PT' . $this->policy['idle_ttl'] . 'S'));
        $hardExpiry = (clone $now)->add(new \DateInterval('PT' . $this->policy['max_lifetime'] . 'S'));

        return $idleExpiry <= $hardExpiry ? $idleExpiry : $hardExpiry;
    }

    /**
     * Insert a continuation.
     *
     * @param   string   $publicId          The public continuation identity.
     * @param   integer  $userId            The authenticated owner identity.
     * @param   string   $context           The exact component record context.
     * @param   string   $targetId          The opaque target identity.
     * @param   string   $initializationKey The initialization idempotency key.
     * @param   string   $now               The SQL operation time.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
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
     * Insert an initial active generation.
     *
     * @param   string   $publicId       The public generation identity.
     * @param   integer  $continuationId The internal continuation identity.
     * @param   integer  $userId         The authenticated owner identity.
     * @param   string   $baseRevision   The opaque provider revision.
     * @param   integer  $quotaSlot      The allocated owner quota slot.
     * @param   string   $now            The SQL operation time.
     * @param   string   $expiresAt      The SQL expiry time.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
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

        $query = $this->db->createQuery()
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
                . 'NULL, NULL, :active_marker, :quota_slot, :created_at, :updated_at, :expires_at, NULL, NULL'
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
}
