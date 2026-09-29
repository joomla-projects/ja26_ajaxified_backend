<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\User\User;

/**
 * Configurable provider implementing the dynamic creation descriptor capability.
 */
final class DynamicDescriptorLifecycleTestProvider implements
    AutosaveProviderInterface,
    AutosaveCreateProviderInterface,
    AutosaveDynamicCreateDescriptorProviderInterface
{
    public string $contractVersion   = 'descriptor-create-v1';
    public string $scopeVersion      = 'field-descriptor-v1';
    public string $canonicalScope    = 'fd1:com_content.article:text:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public array $allowedScopes      = [];
    public array $normalized         = ['normalized' => true];
    public bool $denyScope           = false;
    public bool $denyNormalize       = false;
    public bool $verifyFinalFails    = false;
    public array $scopeArguments     = [];
    public array $canonicalArguments = [];
    public array $verifyArguments    = [];
    public array $normalizeArguments = [];
    private array $events;

    public function __construct(array &$events)
    {
        $this->events = &$events;
    }

    public function getContext(): string
    {
        return 'com_example.record';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return true;
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        throw new \LogicException('A provisional target reached existing-record authorization.');
    }

    public function getBaseRevision(string $targetId): string
    {
        return 'base:' . $targetId;
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $this->events[] = 'provider.normalizePayload';

        return $this->normalized;
    }

    public function normalizeCreatePayload(string $descriptor, mixed $payload, int $schemaVersion): array
    {
        $this->events[]              = 'provider.normalizeDescriptor';
        $this->normalizeArguments[]  = [$descriptor, $payload, $schemaVersion];

        if ($this->denyNormalize) {
            throw new AutosaveException('descriptor_stale', 'The creation descriptor schema is stale.');
        }

        return $this->normalized;
    }

    public function getCreateContractVersion(): string
    {
        return $this->contractVersion;
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $this->events[] = 'authorizeCreate:' . $operation->value;
        throw new AutosaveException('scope_required', 'A descriptor is required.');
    }

    public function getStaticScopeContractVersion(): string
    {
        return $this->scopeVersion;
    }

    public function canonicalizeStaticCreateScope(mixed $candidateScope): string
    {
        $this->events[]             = 'scope.canonicalize';
        $this->canonicalArguments[] = [$candidateScope];

        if (!\is_string($candidateScope) || !\in_array($candidateScope, $this->allowedScopes, true)) {
            throw new AutosaveException('invalid_scope', 'The descriptor candidate is invalid.');
        }

        return $this->canonicalScope;
    }

    public function authorizeStaticCreateScope(User $user, string $canonicalScope, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $this->events[]         = 'scope.authorize:' . $operation->value;
        $this->scopeArguments[] = [$canonicalScope, $operation, $normalizedPayload];

        if ($this->denyScope) {
            throw new AutosaveException('forbidden', 'The scope is denied.');
        }
    }

    public function verifyFinalTargetStaticScope(string $finalTargetId, string $canonicalScope): void
    {
        $this->events[]          = 'scope.verifyFinal';
        $this->verifyArguments[] = [$finalTargetId, $canonicalScope];

        if ($this->verifyFinalFails) {
            throw new AutosaveException('scope_mismatch', 'The final target escaped the descriptor.');
        }
    }
}
