<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveStorageInterface;
use Joomla\CMS\Date\Date;

/**
 * Configurable database-free persistence fake used by lifecycle tests.
 */
final class LifecycleTestStorage implements AutosaveStorageInterface
{
    public array $calls            = [];
    public array $initializeResult = [
        'continuation_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'generation_id'   => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ];
    public array $inspectResult           = [];
    public ?array $detectResult           = null;
    public string $preserveStatus         = 'accepted';
    public string $discardStatus          = 'discarded';
    public ?\Throwable $initializeFailure = null;
    public ?\Throwable $inspectFailure    = null;
    public ?\Throwable $detectFailure     = null;
    public ?\Throwable $preserveFailure   = null;
    public ?\Throwable $discardFailure    = null;
    private array $events;

    public function __construct(array &$events)
    {
        $this->events = &$events;
    }

    public function initialize(
        int $userId,
        string $context,
        string $targetId,
        string $baseRevision,
        string $initializationKey,
        Date $now
    ): array {
        $this->events[]              = 'storage.initialize';
        $this->calls['initialize'][] = \func_get_args();

        if ($this->initializeFailure !== null) {
            throw $this->initializeFailure;
        }

        return $this->initializeResult;
    }

    public function inspect(int $userId, string $continuationId, string $generationId, Date $now): array
    {
        $this->events[]           = 'storage.inspect';
        $this->calls['inspect'][] = \func_get_args();

        if ($this->inspectFailure !== null) {
            throw $this->inspectFailure;
        }

        return $this->inspectResult;
    }

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
        $this->events[]            = 'storage.preserve';
        $this->calls['preserve'][] = \func_get_args();

        if ($this->preserveFailure !== null) {
            throw $this->preserveFailure;
        }

        return $this->preserveStatus;
    }

    public function detect(int $userId, string $context, string $targetId, Date $now): ?array
    {
        $this->events[]          = 'storage.detect';
        $this->calls['detect'][] = \func_get_args();

        if ($this->detectFailure !== null) {
            throw $this->detectFailure;
        }

        return $this->detectResult;
    }

    public function discard(int $userId, string $continuationId, string $generationId, Date $now): string
    {
        $this->events[]           = 'storage.discard';
        $this->calls['discard'][] = \func_get_args();

        if ($this->discardFailure !== null) {
            throw $this->discardFailure;
        }

        return $this->discardStatus;
    }
}
