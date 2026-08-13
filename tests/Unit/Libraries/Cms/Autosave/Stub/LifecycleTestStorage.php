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
    public array $canonicalResult         = [];
    public ?\Throwable $canonicalFailure  = null;
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

    public function purgeRetainedData(Date $now, int $limit = self::DEFAULT_PURGE_LIMIT): array
    {
        $this->events[]                     = 'storage.purgeRetainedData';
        $this->calls['purgeRetainedData'][] = \func_get_args();

        return [
            'generations_expired'         => 0,
            'closed_generations_released' => 0,
            'canonical_actions_deleted'   => 0,
            'generations_deleted'         => 0,
            'continuations_deleted'       => 0,
        ];
    }

    public function prepareCanonicalAction(
        int $userId,
        string $continuationId,
        string $generationId,
        string $context,
        string $targetId,
        string $baseRevision,
        int $clientRevision,
        array $payload,
        int $schemaVersion,
        string $intent,
        Date $now
    ): array {
        $this->events[]                          = 'storage.prepareCanonicalAction';
        $this->calls['prepareCanonicalAction'][] = \func_get_args();
        if ($this->canonicalFailure !== null) {
            throw $this->canonicalFailure;
        }

        return $this->canonicalResult;
    }

    public function inspectCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        Date $now
    ): array {
        $this->events[]                          = 'storage.inspectCanonicalAction';
        $this->calls['inspectCanonicalAction'][] = \func_get_args();
        if ($this->canonicalFailure !== null) {
            throw $this->canonicalFailure;
        }

        return $this->canonicalResult;
    }

    public function verifyCanonicalAction(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $currentBaseRevision,
        Date $now
    ): array {
        $this->events[]                         = 'storage.verifyCanonicalAction';
        $this->calls['verifyCanonicalAction'][] = \func_get_args();
        if ($this->canonicalFailure !== null) {
            throw $this->canonicalFailure;
        }

        return $this->canonicalResult;
    }

    public function finalizeCanonicalActionSuccess(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $finalTargetId,
        string $finalBaseRevision,
        Date $now
    ): array {
        $this->events[]                                  = 'storage.finalizeCanonicalActionSuccess';
        $this->calls['finalizeCanonicalActionSuccess'][] = \func_get_args();
        if ($this->canonicalFailure !== null) {
            throw $this->canonicalFailure;
        }

        return $this->canonicalResult;
    }

    public function finalizeCanonicalActionFailure(
        int $userId,
        string $operationId,
        string $context,
        string $targetId,
        string $intent,
        string $failureCode,
        Date $now
    ): array {
        $this->events[]                                  = 'storage.finalizeCanonicalActionFailure';
        $this->calls['finalizeCanonicalActionFailure'][] = \func_get_args();
        if ($this->canonicalFailure !== null) {
            throw $this->canonicalFailure;
        }

        return $this->canonicalResult;
    }
}
