<?php

namespace Joomla\Tests\Unit\Administrator\Components\Users\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\Component\Users\Administrator\Autosave\GroupAutosaveProvider;
use Joomla\Component\Users\Administrator\Autosave\LevelAutosaveProvider;
use Joomla\Component\Users\Administrator\Autosave\NoteAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class UserMetadataAutosaveProvidersTest extends UnitTestCase
{
    public function testExactContractsAndIdentityBoundaries(): void
    {
        $providers = [new GroupAutosaveProvider($this->database()), new LevelAutosaveProvider($this->database()), new NoteAutosaveProvider($this->database())];
        $this->assertSame(['com_users.group', 'com_users.level', 'com_users.note'], array_map(fn ($p) => $p->getContext(), $providers));
        $this->assertSame(['title' => ''], $providers[0]->normalizePayload(['title' => ''], 1));
        $this->assertSame(['title' => '', 'rules' => []], $providers[1]->normalizePayload(['title' => '', 'rules' => []], 2));
        $this->assertSame(['subject' => '', 'body' => ''], $providers[2]->normalizePayload(['body' => '', 'subject' => ''], 1));
        foreach ($providers as $provider) {
            foreach (['0', '-1', '01', ' 1', '4294967296'] as $id) {
                $this->assertFailure('invalid_target', fn () => $provider->canonicalizeTargetId($id));
            }
        }
        $this->assertFailure('invalid_payload', fn () => $providers[0]->normalizePayload(['title' => '', 'rules' => []], 1));
        $this->assertFailure('invalid_payload', fn () => $providers[1]->normalizePayload(['title' => str_repeat('x', 101), 'rules' => []], 2));
        $this->assertFailure('invalid_payload', fn () => $providers[1]->normalizePayload(['title' => '', 'rules' => ['1']], 2));
        $this->assertFailure('unsupported_schema_version', fn () => $providers[1]->normalizePayload(['title' => '', 'rules' => []], 1));
        $this->assertFailure('invalid_payload', fn () => $providers[2]->normalizePayload(['subject' => '', 'body' => '', 'user_id' => 1], 1));
        $this->assertFailure('invalid_payload', fn () => $providers[2]->normalizePayload(['subject' => "\xC3\x28", 'body' => ''], 1));
    }
    private function database(): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();
        return $db;
    }
    private function assertFailure(string $code, callable $callback): void
    {
        try {
            $callback();
        } catch (AutosaveException $e) {
            $this->assertSame($code, $e->getErrorCode());
            return;
        } $this->fail('Expected exception.');
    }
}
