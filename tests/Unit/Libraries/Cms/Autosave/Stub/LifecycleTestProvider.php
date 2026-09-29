<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\User\User;

/**
 * Configurable component-owned provider used by lifecycle tests.
 */
final class LifecycleTestProvider implements AutosaveProviderInterface
{
    public bool $exists                     = true;
    public string $baseRevision             = 'base-1';
    public int $schemaVersion               = 1;
    public array $normalized                = ['normalized' => true];
    public ?\Throwable $authorizeFailure    = null;
    public ?\Throwable $baseRevisionFailure = null;
    public ?\Throwable $normalizeFailure    = null;
    public array $normalizationArguments    = [];
    private array $events;

    public function __construct(array &$events)
    {
        $this->events = &$events;
    }

    public function getContext(): string
    {
        return 'com_example.record';
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        $this->events[] = 'canonicalizeTargetId';

        return 'target-42';
    }

    public function targetExists(string $targetId): bool
    {
        $this->events[] = 'targetExists';

        return $this->exists;
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $this->events[] = 'authorize:' . $operation->value;

        if ($this->authorizeFailure !== null) {
            throw $this->authorizeFailure;
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $this->events[] = 'getBaseRevision';

        if ($this->baseRevisionFailure !== null) {
            throw $this->baseRevisionFailure;
        }

        return $this->baseRevision;
    }

    public function getPayloadSchemaVersion(): int
    {
        $this->events[] = 'getPayloadSchemaVersion';

        return $this->schemaVersion;
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $this->events[]               = 'normalizePayload';
        $this->normalizationArguments = [$payload, $schemaVersion];

        if ($this->normalizeFailure !== null) {
            throw $this->normalizeFailure;
        }

        return $this->normalized;
    }
}
