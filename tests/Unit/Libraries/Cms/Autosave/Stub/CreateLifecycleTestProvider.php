<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\User\User;

final class CreateLifecycleTestProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    public string $contractVersion       = 'static-v1';
    public int $schemaVersion            = 1;
    public array $normalized             = ['normalized' => true];
    public array $createArguments        = [];
    public array $normalizationArguments = [];
    public ?\Throwable $createFailure    = null;
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
        throw new \LogicException('A provisional target reached canonical target normalization.');
    }

    public function targetExists(string $targetId): bool
    {
        $this->events[] = 'targetExists';
        throw new \LogicException('A provisional target reached record existence checking.');
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $this->events[] = 'authorize:' . $operation->value;
        throw new \LogicException('A provisional target reached existing-record authorization.');
    }

    public function getBaseRevision(string $targetId): string
    {
        $this->events[] = 'getBaseRevision';
        throw new \LogicException('A provisional target reached canonical revision lookup.');
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
        return $this->normalized;
    }

    public function getCreateContractVersion(): string
    {
        $this->events[] = 'getCreateContractVersion';
        return $this->contractVersion;
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $this->events[]          = 'authorizeCreate:' . $operation->value;
        $this->createArguments[] = [$user, $operation, $normalizedPayload];

        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }
    }
}
