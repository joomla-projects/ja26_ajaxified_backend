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
        private readonly AutosaveStorageInterface $storage,
        private readonly string $siteSecret = ''
    ) {
    }

    /**
     * Initialize one owner-bound provisional draft for a new record.
     *
     * Providers that implement AutosaveStaticScopeProviderInterface require a bounded
     * candidate creation scope, canonicalize it, authorize it and anchor the canonical
     * result onto the continuation before the first generation can be preserved. Retrying
     * with the same canonical scope is idempotent; retrying the same initialization key
     * with a different canonical scope fails closed instead of mutating the lineage.
     *
     * @param   string|null  $candidateScope  Optional bounded candidate creation scope.
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
    public function initializeCreate(
        User $user,
        string $context,
        string $initializationKey,
        Date $now,
        ?string $candidateScope = null
    ): array {
        $userId         = $this->validateInvocation($user, $now);
        $provider       = $this->resolveCreateProvider($context);
        $static         = $this->staticScopeProvider($provider);
        $canonicalScope = null;

        if ($static === null && $candidateScope !== null) {
            throw new AutosaveException('scope_unsupported', 'Static creation scope is not supported for this context.');
        }

        if ($static !== null) {
            $staticStorage = $this->staticScopeStorage();

            if ($candidateScope === null) {
                throw new AutosaveException('scope_required', 'A static creation scope is required for this context.');
            }

            $canonicalScope = $static->canonicalizeStaticCreateScope($candidateScope);
            $this->validateCreateScope($canonicalScope);
            $static->authorizeStaticCreateScope($user, $canonicalScope, AutosaveOperation::InitializeCreate, null);
        }

        try {
            AutosaveTargetIdentity::validateInitializationKey($initializationKey);
        } catch (\InvalidArgumentException) {
            throw new AutosaveException('invalid_initialization_key', 'The Autosave initialization key is invalid.');
        }
        $this->validateCreateContractVersion($provider->getCreateContractVersion());

        if ($static !== null) {
            $this->validateCreateContractVersion($static->getStaticScopeContractVersion());
        }

        if ($static === null) {
            $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null);
        }

        if ($this->siteSecret === '') {
            throw new \RuntimeException('The Autosave provisional target secret is unavailable.');
        }

        $targetId      = AutosaveTargetIdentity::provisional($userId, $context, $initializationKey, $this->siteSecret);
        $baseRevision  = $this->getCreateBaseRevision($context, $provider, $this->scopeContractVersion($static));
        $schemaVersion = $provider->getPayloadSchemaVersion();
        $identities    = $this->storage->initialize(
            $userId,
            $context,
            $targetId,
            $baseRevision,
            $initializationKey,
            $now
        );

        if ($static !== null && $canonicalScope !== null) {
            $previous = $staticStorage->bindContinuationStaticScope(
                $userId,
                $identities['continuation_id'],
                $canonicalScope,
                $now
            );

            if ($previous !== null && !hash_equals($previous, $canonicalScope)) {
                throw new AutosaveException(
                    'scope_conflict',
                    'The initialization key is already bound to a different creation scope.'
                );
            }
        }

        return [
            'continuation_id'        => $identities['continuation_id'],
            'generation_id'          => $identities['generation_id'],
            'context'                => $context,
            'target_id'              => $targetId,
            'base_revision'          => $baseRevision,
            'payload_schema_version' => $schemaVersion,
        ];
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

        if (AutosaveTargetIdentity::isProvisionalNamespace($targetId)) {
            throw new AutosaveException('invalid_target', 'The Autosave target is invalid.');
        }

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

        if (AutosaveTargetIdentity::isProvisional($generation['target_id'])) {
            $this->requireCreateGeneration($provider, $generation);

            if ($provider->getPayloadSchemaVersion() !== $schemaVersion) {
                throw new AutosaveException('unsupported_schema_version', 'The Autosave payload schema version is not supported.');
            }

            $normalizedPayload = $this->normalizeProvisionalPayload($provider, $userId, $generation['continuation_id'], $payload, $schemaVersion);
            $this->authorizeProvisional($user, $provider, $generation['continuation_id'], AutosaveOperation::Preserve, $normalizedPayload);
        } else {
            $provider->authorize($user, $generation['target_id'], AutosaveOperation::Preserve);

            if ($provider->getPayloadSchemaVersion() !== $schemaVersion) {
                throw new AutosaveException(
                    'unsupported_schema_version',
                    'The Autosave payload schema version is not supported.'
                );
            }

            $normalizedPayload = $this->normalizePayload($provider, $generation['target_id'], $payload, $schemaVersion);
        }
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
        $canonicalTarget = $targetId;

        if (AutosaveTargetIdentity::isProvisional($targetId)) {
            $createProvider = $this->requireCreateProvider($provider);
            $static         = $this->staticScopeProvider($createProvider);

            if ($static === null) {
                $createProvider->authorizeCreate($user, AutosaveOperation::Detect, null);
            }
        } elseif (AutosaveTargetIdentity::isProvisionalNamespace($targetId)) {
            throw new AutosaveException('invalid_target', 'The Autosave target is invalid.');
        } else {
            $canonicalTarget = $provider->canonicalizeTargetId($targetId);

            if (!$provider->targetExists($canonicalTarget)) {
                throw new AutosaveException('target_not_found', 'The Autosave target was not found.');
            }

            $provider->authorize($user, $canonicalTarget, AutosaveOperation::Detect);
        }

        $generation = $this->storage->detect($userId, $context, $canonicalTarget, $now);

        if ($generation === null) {
            return null;
        }

        if (isset($createProvider, $static)) {
            $this->authorizeProvisional($user, $provider, $generation['continuation_id'], AutosaveOperation::Detect, null);
        }

        $currentRevision = isset($createProvider)
            ? $this->getCreateBaseRevision($context, $createProvider, $this->scopeContractVersion($this->staticScopeProvider($createProvider)))
            : $provider->getBaseRevision($canonicalTarget);

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

        if (AutosaveTargetIdentity::isProvisional($generation['target_id'])) {
            $createProvider  = $this->requireCreateGeneration($provider, $generation);
            $this->authorizeProvisional($user, $provider, $generation['continuation_id'], AutosaveOperation::Read, $generation['payload']);
            $currentRevision = $this->getCreateBaseRevision(
                $generation['context'],
                $createProvider,
                $this->scopeContractVersion($this->staticScopeProvider($createProvider))
            );
        } else {
            $provider->authorize($user, $generation['target_id'], AutosaveOperation::Read);
            $currentRevision = $provider->getBaseRevision($generation['target_id']);
        }

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
     * Atomically preserve the submitted snapshot, close its generation and
     * create an idempotent canonical action operation.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function prepareCanonicalAction(
        User $user,
        string $context,
        string $targetId,
        string $continuationId,
        string $generationId,
        int $clientRevision,
        mixed $payload,
        int $schemaVersion,
        string $intent,
        string $expectedBaseRevision,
        Date $now
    ): array {
        $userId          = $this->validateInvocation($user, $now);
        $provider        = $this->resolver->resolve($context);
        $canonicalTarget = $targetId;

        if (AutosaveTargetIdentity::isProvisional($targetId)) {
            $createProvider      = $this->requireCreateProvider($provider);
            $currentBaseRevision = $this->getCreateBaseRevision(
                $context,
                $createProvider,
                $this->scopeContractVersion($this->staticScopeProvider($createProvider))
            );

            if (!hash_equals($currentBaseRevision, $expectedBaseRevision)) {
                throw new AutosaveException('base_revision_conflict', 'The create contract changed before preparation.');
            }

            if ($provider->getPayloadSchemaVersion() !== $schemaVersion) {
                throw new AutosaveException('unsupported_schema_version', 'The Autosave payload schema version is not supported.');
            }

            $normalizedPayload = $this->normalizeProvisionalPayload($provider, $userId, $continuationId, $payload, $schemaVersion);
            $this->authorizeProvisional($user, $provider, $continuationId, AutosaveOperation::PrepareCanonicalAction, $normalizedPayload);

            return $this->storage->prepareCanonicalAction(
                $userId,
                $continuationId,
                $generationId,
                $context,
                $canonicalTarget,
                $currentBaseRevision,
                $clientRevision,
                $normalizedPayload,
                $schemaVersion,
                $intent,
                $now
            );
        } elseif (AutosaveTargetIdentity::isProvisionalNamespace($targetId)) {
            throw new AutosaveException('invalid_target', 'The Autosave target is invalid.');
        }

        $canonicalTarget = $provider->canonicalizeTargetId($targetId);

        if (!$provider->targetExists($canonicalTarget)) {
            throw new AutosaveException('target_not_found', 'The Autosave target was not found.');
        }

        $provider->authorize($user, $canonicalTarget, AutosaveOperation::PrepareCanonicalAction);
        $currentBaseRevision = $provider->getBaseRevision($canonicalTarget);

        if (!hash_equals($currentBaseRevision, $expectedBaseRevision)) {
            throw new AutosaveException(
                'base_revision_conflict',
                'The canonical base revision changed before preparation.'
            );
        }

        if ($provider->getPayloadSchemaVersion() !== $schemaVersion) {
            throw new AutosaveException(
                'unsupported_schema_version',
                'The Autosave payload schema version is not supported.'
            );
        }

        return $this->storage->prepareCanonicalAction(
            $userId,
            $continuationId,
            $generationId,
            $context,
            $canonicalTarget,
            $currentBaseRevision,
            $clientRevision,
            $this->normalizePayload($provider, $canonicalTarget, $payload, $schemaVersion),
            $schemaVersion,
            $intent,
            $now
        );
    }

    /**
     * Normalize a payload with canonical target data when the provider requires it.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function normalizePayload(
        AutosaveProviderInterface $provider,
        string $targetId,
        mixed $payload,
        int $schemaVersion
    ): array {
        if ($provider instanceof TargetAwareAutosaveProviderInterface) {
            return $provider->normalizePayloadForTarget($targetId, $payload, $schemaVersion);
        }

        return $provider->normalizePayload($payload, $schemaVersion);
    }

    /**
     * Normalize one provisional payload, recovering the anchored descriptor first.
     *
     * Providers implementing AutosaveDynamicCreateDescriptorProviderInterface anchor
     * a canonical creation descriptor instead of a plain static scope. Its dynamic
     * schema is reconstructed server-side from the recovered descriptor, so the
     * exact payload can only be validated after the descriptor is available. All
     * other create providers keep the ordinary scope-free normalization.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function normalizeProvisionalPayload(
        AutosaveProviderInterface $provider,
        int $userId,
        string $continuationId,
        mixed $payload,
        int $schemaVersion
    ): array {
        $createProvider = $this->requireCreateProvider($provider);

        if (!$createProvider instanceof AutosaveDynamicCreateDescriptorProviderInterface) {
            return $provider->normalizePayload($payload, $schemaVersion);
        }

        $descriptor = $this->staticScopeStorage()->getContinuationStaticScope($userId, $continuationId);

        if ($descriptor === null) {
            throw new AutosaveException('scope_required', 'The dynamic creation descriptor is not bound.');
        }

        return $createProvider->normalizeCreatePayload($descriptor, $payload, $schemaVersion);
    }

    /**
     * Query one metadata-only authoritative canonical outcome.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getCanonicalActionOutcome(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        Date $now
    ): array {
        $userId          = $this->validateInvocation($user, $now);
        $provider        = $this->resolver->resolve($context);
        $canonicalTarget = $targetId;

        if (AutosaveTargetIdentity::isProvisional($targetId)) {
            $createProvider = $this->requireCreateProvider($provider);
            $static         = $this->staticScopeProvider($createProvider);

            if ($static === null) {
                $createProvider->authorizeCreate($user, AutosaveOperation::QueryCanonicalAction, null);
            }
        } elseif (AutosaveTargetIdentity::isProvisionalNamespace($targetId)) {
            throw new AutosaveException('invalid_target', 'The Autosave target is invalid.');
        } else {
            $canonicalTarget = $provider->canonicalizeTargetId($targetId);
            $provider->authorize($user, $canonicalTarget, AutosaveOperation::QueryCanonicalAction);
        }

        $outcome = $this->storage->inspectCanonicalAction(
            $userId,
            $operationId,
            $context,
            $canonicalTarget,
            $now
        );

        if (isset($createProvider, $static)) {
            $this->authorizeProvisional($user, $provider, $outcome['continuation_id'], AutosaveOperation::QueryCanonicalAction, null);
        }

        return $outcome;
    }

    /**
     * Verify prepared metadata before the canonical controller save begins.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function verifyCanonicalAction(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        Date $now
    ): array {
        $userId          = $this->validateInvocation($user, $now);
        $provider        = $this->resolver->resolve($context);

        if (AutosaveTargetIdentity::isProvisionalNamespace($targetId)) {
            throw new AutosaveException('invalid_target', 'The Autosave target is invalid.');
        }

        $canonicalTarget = $provider->canonicalizeTargetId($targetId);

        $provider->authorize($user, $canonicalTarget, AutosaveOperation::PrepareCanonicalAction);

        return $this->storage->verifyCanonicalAction(
            $userId,
            $operationId,
            $context,
            $canonicalTarget,
            $intent,
            $provider->getBaseRevision($canonicalTarget),
            $now
        );
    }

    public function verifyCreateCanonicalAction(
        User $user,
        string $operationId,
        string $context,
        string $intent,
        Date $now
    ): array {
        $userId   = $this->validateInvocation($user, $now);
        $provider = $this->resolveCreateProvider($context);

        if (!$this->storage instanceof AutosaveCreateStorageInterface) {
            throw new AutosaveException('create_not_supported', 'New-record Autosave is unavailable.');
        }

        $verified = $this->storage->verifyCreateCanonicalAction(
            $userId,
            $operationId,
            $context,
            $intent,
            $this->getCreateBaseRevision($context, $provider, $this->scopeContractVersion($this->staticScopeProvider($provider))),
            $now
        );

        if ($this->staticScopeProvider($provider) !== null) {
            $staticStorage = $this->staticScopeStorage();
            $action        = $this->storage->inspectCanonicalAction(
                $userId,
                $operationId,
                $context,
                AutosaveTargetIdentity::requireProvisional((string) $verified['target_id']),
                $now
            );
            $this->authorizeProvisional($user, $provider, $action['continuation_id'], AutosaveOperation::PrepareCanonicalAction, $verified['payload']);
        } else {
            $provider->authorizeCreate($user, AutosaveOperation::PrepareCanonicalAction, $verified['payload']);
        }

        unset($verified['payload']);

        return $verified;
    }

    /**
     * Retire the prepared generation after authoritative controller success.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function finalizeCanonicalActionSuccess(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $finalTargetId,
        Date $now
    ): array {
        $userId          = $this->validateInvocation($user, $now);
        $provider        = $this->resolver->resolve($context);
        $canonicalTarget = AutosaveTargetIdentity::isProvisional($targetId)
            ? AutosaveTargetIdentity::requireProvisional($targetId)
            : $provider->canonicalizeTargetId($targetId);
        $canonicalFinal  = $provider->canonicalizeTargetId($finalTargetId);

        if (AutosaveTargetIdentity::isProvisional($canonicalTarget)) {
            $static = $this->staticScopeProvider($this->requireCreateProvider($provider));

            if ($static !== null) {
                $scopeStorage = $this->staticScopeStorage();
                $action       = $this->storage->inspectCanonicalAction(
                    $userId,
                    $operationId,
                    $context,
                    $canonicalTarget,
                    $now
                );
                $scope = $scopeStorage->getContinuationStaticScope($userId, $action['continuation_id']);

                if ($scope === null) {
                    throw new AutosaveException('scope_required', 'The static creation scope is not bound.');
                }

                $static->verifyFinalTargetStaticScope($canonicalFinal, $scope);
            }
        }

        return $this->storage->finalizeCanonicalActionSuccess(
            $userId,
            $operationId,
            $context,
            $canonicalTarget,
            $intent,
            $canonicalFinal,
            $provider->getBaseRevision($canonicalFinal),
            $now
        );
    }

    /**
     * Record a definitive canonical controller failure.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function finalizeCanonicalActionFailure(
        User $user,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $failureCode,
        Date $now
    ): array {
        $this->validateInvocation($user, $now);
        $provider        = $this->resolver->resolve($context);
        $canonicalTarget = AutosaveTargetIdentity::isProvisional($targetId)
            ? AutosaveTargetIdentity::requireProvisional($targetId)
            : $provider->canonicalizeTargetId($targetId);

        return $this->storage->finalizeCanonicalActionFailure(
            (int) $user->id,
            $operationId,
            $context,
            $canonicalTarget,
            $intent,
            $failureCode,
            $now
        );
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

    private function resolveCreateProvider(string $context): AutosaveCreateProviderInterface&AutosaveProviderInterface
    {
        return $this->requireCreateProvider($this->resolver->resolve($context));
    }

    private function requireCreateProvider(
        AutosaveProviderInterface $provider
    ): AutosaveCreateProviderInterface&AutosaveProviderInterface {
        if (!$provider instanceof AutosaveCreateProviderInterface) {
            throw new AutosaveException('create_unsupported', 'New-record Autosave is not supported for this context.');
        }

        $this->validateCreateContractVersion($provider->getCreateContractVersion());

        return $provider;
    }

    private function requireCreateGeneration(
        AutosaveProviderInterface $provider,
        array $generation
    ): AutosaveCreateProviderInterface&AutosaveProviderInterface {
        AutosaveTargetIdentity::requireProvisional($generation['target_id']);
        $createProvider = $this->requireCreateProvider($provider);

        if (
            !hash_equals(
                $generation['base_revision'],
                $this->getCreateBaseRevision(
                    $generation['context'],
                    $createProvider,
                    $this->scopeContractVersion($this->staticScopeProvider($createProvider))
                )
            )
        ) {
            throw new AutosaveException('create_contract_conflict', 'The new-record Autosave contract changed.');
        }

        return $createProvider;
    }

    private function validateCreateContractVersion(string $version): void
    {
        if ($version === '' || \strlen($version) > 64 || preg_match('/^[A-Za-z0-9._-]+$/D', $version) !== 1) {
            throw new AutosaveException('invalid_create_contract', 'The new-record Autosave contract is invalid.');
        }
    }

    /**
     * Resolve the optional immutable static scope capability of a create provider.
     */
    private function staticScopeProvider(
        AutosaveCreateProviderInterface&AutosaveProviderInterface $provider
    ): ?AutosaveStaticScopeProviderInterface {
        return $provider instanceof AutosaveStaticScopeProviderInterface ? $provider : null;
    }

    /**
     * Require the optional persistence capability for immutable static scope.
     */
    private function staticScopeStorage(): AutosaveStaticScopeStorageInterface
    {
        if (!$this->storage instanceof AutosaveStaticScopeStorageInterface) {
            throw new AutosaveException('static_scope_unsupported', 'Static creation scope is unavailable.');
        }

        return $this->storage;
    }

    /**
     * Authorize one provisional operation against the anchored static scope when the
     * provider requires one, otherwise preserve the plain create authorization path.
     */
    private function authorizeProvisional(
        User $user,
        AutosaveProviderInterface $provider,
        string $continuationId,
        AutosaveOperation $operation,
        ?array $normalizedPayload
    ): void {
        $createProvider = $this->requireCreateProvider($provider);
        $static         = $this->staticScopeProvider($createProvider);

        if ($static === null) {
            $createProvider->authorizeCreate($user, $operation, $normalizedPayload);

            return;
        }

        $staticStorage = $this->staticScopeStorage();
        $scope         = $staticStorage->getContinuationStaticScope((int) $user->id, $continuationId);

        if ($scope === null) {
            throw new AutosaveException('scope_required', 'The static creation scope is not bound.');
        }

        $static->authorizeStaticCreateScope($user, $scope, $operation, $normalizedPayload);
    }

    /**
     * Structural guard for one canonical scope before it can be anchored.
     */
    private function validateCreateScope(string $canonicalScope): void
    {
        if (
            $canonicalScope === ''
            || \strlen($canonicalScope) > 255
            || preg_match('//u', $canonicalScope) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $canonicalScope) === 1
        ) {
            throw new AutosaveException('invalid_scope', 'The Autosave static creation scope is invalid.');
        }
    }

    private function scopeContractVersion(?AutosaveStaticScopeProviderInterface $static): ?string
    {
        if ($static === null) {
            return null;
        }

        $version = $static->getStaticScopeContractVersion();
        $this->validateCreateContractVersion($version);

        return $version;
    }

    private function getCreateBaseRevision(
        string $context,
        AutosaveCreateProviderInterface&AutosaveProviderInterface $provider,
        ?string $staticScopeVersion = null
    ): string {
        $contract = [
            'context' => $context,
            'schema'  => $provider->getPayloadSchemaVersion(),
            'create'  => $provider->getCreateContractVersion(),
        ];

        if ($staticScopeVersion !== null) {
            $contract['scope'] = $staticScopeVersion;
        }

        $encoded = json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'autosave:create:v1:' . hash('sha256', "joomla.autosave.create-revision.v1\0" . $encoded);
    }
}
