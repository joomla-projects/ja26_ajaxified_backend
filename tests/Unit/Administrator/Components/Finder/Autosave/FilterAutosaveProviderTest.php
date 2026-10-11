<?php

namespace Joomla\Tests\Unit\Administrator\Components\Finder\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Finder\Administrator\Autosave\FilterAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class FilterAutosaveProviderTest extends UnitTestCase
{
    public function testIdentityExistenceAuthorizationAndCheckout(): void
    {
        $provider = new FilterAutosaveProvider($this->databaseReturning($this->record()));
        $this->assertSame('com_finder.filter', $provider->getContext());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
        $this->assertTrue($provider->targetExists('42'));
        foreach (['', '0', '-1', '01', ' 1', '1.0', '2147483648'] as $invalid) {
            $this->assertSame('invalid_target', $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode());
        }
        $user     = $this->createMock(User::class);
        $user->id = 7;
        $user->method('authorise')->with('core.edit', 'com_finder')->willReturn(true);
        $provider->authorize($user, '42', AutosaveOperation::Preserve);
        $this->assertSame('checked_out', $this->failure(fn () => (new FilterAutosaveProvider($this->databaseReturning($this->record(['checked_out' => 8]))))->authorize($user, '42', AutosaveOperation::Read))->getErrorCode());
    }

    public function testExactBoundedStructuredPayloadRoundTrips(): void
    {
        $provider = new FilterAutosaveProvider($this->databaseReturning());
        $this->assertSame($this->payload(), $provider->normalizePayload($this->payload(), 1));
    }

    public function testRejectsUnknownShapeDepthCardinalityTypesDuplicatesAndMalformedValues(): void
    {
        $provider = new FilterAutosaveProvider($this->databaseReturning());
        $payload  = $this->payload();
        $invalid  = [array_diff_key($payload, ['title' => true]), [...$payload, 'task' => 'filter.save'],
            [...$payload, 'params' => [...$payload['params'], 'unknown' => 'x']],
            [...$payload, 'params' => [...$payload['params'], 'd1' => ['nested' => ['deeper']]]],
            [...$payload, 'taxonomy_ids' => '1,2'], [...$payload, 'taxonomy_ids' => ['1', '1']],
            [...$payload, 'taxonomy_ids' => ['0']], [...$payload, 'taxonomy_ids' => ['01']],
            [...$payload, 'taxonomy_ids' => array_fill(0, 1001, '1')], [...$payload, 'state' => '1'],
            [...$payload, 'title' => "\xB1\x31"]];
        foreach ($invalid as $candidate) {
            $this->assertSame('invalid_payload', $this->failure(fn () => $provider->normalizePayload($candidate, 1))->getErrorCode());
        }
    }

    public function testBaseRevisionIgnoresCheckoutAndTracksCanonicalData(): void
    {
        $base = new FilterAutosaveProvider($this->databaseReturning($this->record()));
        $this->assertSame($base->getBaseRevision('42'), (new FilterAutosaveProvider($this->databaseReturning($this->record(['checked_out' => 9]))))->getBaseRevision('42'));
        $this->assertNotSame($base->getBaseRevision('42'), (new FilterAutosaveProvider($this->databaseReturning($this->record(['data' => '1,3']))))->getBaseRevision('42'));
    }

    private function payload(): array
    {
        return ['title' => 'Filter', 'alias' => 'filter', 'created' => '2026-08-26', 'created_alt' => '2026-08-26 00:00:00', 'created_by' => '7', 'created_by_alias' => '', 'state' => 1,
            'params'    => ['w1' => '-1', 'd1' => 'Yesterday', 'd1_alt' => '2026-08-25 00:00:00', 'w2' => '', 'd2' => '', 'd2_alt' => ''], 'taxonomy_ids' => ['2', '9']];
    }

    private function record(array $replace = []): object
    {
        return (object) array_replace(['filter_id' => 42, 'title' => 'Filter', 'alias' => 'filter', 'state' => 1, 'created' => '2026-01-01', 'created_by' => 7, 'created_by_alias' => '', 'modified' => '2026-01-01', 'modified_by' => 7, 'checked_out' => 0, 'map_count' => 2, 'data' => '1,2', 'params' => '{}'], $replace);
    }

    private function databaseReturning(?object $record = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($record);
        return $db;
    }

    private function failure(callable $callback): AutosaveException
    {
        try {
            $callback();
        } catch (AutosaveException $exception) {
            return $exception;
        } $this->fail('Expected AutosaveException was not thrown.');
    }
}
