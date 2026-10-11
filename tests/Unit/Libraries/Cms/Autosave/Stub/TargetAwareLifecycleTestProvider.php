<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\TargetAwareAutosaveProviderInterface;
use Joomla\CMS\User\User;

final class TargetAwareLifecycleTestProvider implements TargetAwareAutosaveProviderInterface
{
    public array $arguments = [];

    public function getContext(): string
    {
        return 'com_example.record';
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        return 'target-42';
    }

    public function targetExists(string $targetId): bool
    {
        return true;
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
    }

    public function getBaseRevision(string $targetId): string
    {
        return 'base-1';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        throw new \LogicException('Target-free normalization must not be used.');
    }
    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $this->arguments = [$targetId, $payload, $schemaVersion];

        return ['target_aware' => true];
    }
}
