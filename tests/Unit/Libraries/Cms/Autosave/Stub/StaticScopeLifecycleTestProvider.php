<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\Autosave\AutosaveStaticScopeProviderInterface;
use Joomla\CMS\User\User;

/**
 * Configurable provider implementing the immutable static scope capability.
 */
final class StaticScopeLifecycleTestProvider implements
    AutosaveProviderInterface,
    AutosaveCreateProviderInterface,
    AutosaveStaticScopeProviderInterface
{
    public string $contractVersion  = 'static-scope-v1';
    public string $scopeVersion     = 'scope-v1';
    public string $canonicalScope   = 'com_content';
    public array $allowedScopes     = ['com_content', 'com_banners'];
    public array $normalized        = ['normalized' => true];
    public bool $denyScope          = false;
    public bool $verifyFinalFails   = false;
    public array $scopeArguments    = [];
    public array $canonicalArguments = [];
    public array $verifyArguments   = [];
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
        return $this->normalized;
    }

    public function getCreateContractVersion(): string
    {
        return $this->contractVersion;
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $this->events[] = 'authorizeCreate:' . $operation->value;
        throw new AutosaveException('scope_required', 'A scope is required.');
    }

    public function getStaticScopeContractVersion(): string
    {
        return $this->scopeVersion;
    }

    public function canonicalizeStaticCreateScope(mixed $candidateScope): string
    {
        $this->events[]            = 'scope.canonicalize';
        $this->canonicalArguments[] = [$candidateScope];

        if (!\is_string($candidateScope) || !\in_array($candidateScope, $this->allowedScopes, true)) {
            throw new AutosaveException('invalid_scope', 'The scope is invalid.');
        }

        return $candidateScope;
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
        $this->events[]         = 'scope.verifyFinal';
        $this->verifyArguments[] = [$finalTargetId, $canonicalScope];

        if ($this->verifyFinalFails) {
            throw new AutosaveException('scope_mismatch', 'The final target escaped the scope.');
        }
    }
}
