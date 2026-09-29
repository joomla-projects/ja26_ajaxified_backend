<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\User\User;

final class ServiceTraitTestProvider implements AutosaveProviderInterface
{
    public function __construct(private readonly string $context)
    {
    }

    public function getContext(): string
    {
        return $this->context;
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
    }

    public function getBaseRevision(string $targetId): string
    {
        return 'revision';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        return (array) $payload;
    }
}
