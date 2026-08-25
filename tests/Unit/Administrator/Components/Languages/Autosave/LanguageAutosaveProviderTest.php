<?php

namespace Joomla\Tests\Unit\Administrator\Components\Languages\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\Component\Languages\Administrator\Autosave\LanguageAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class LanguageAutosaveProviderTest extends UnitTestCase
{
    public function testExactMetadataContract(): void
    {
        $provider = new LanguageAutosaveProvider($this->database());
        $payload  = ['title' => '', 'title_native' => '', 'description' => '', 'metadesc' => '', 'sitename' => ''];
        $this->assertSame('com_languages.language', $provider->getContext());
        $this->assertSame($payload, $provider->normalizePayload($payload, 1));
        foreach (['0', '-1', '01', ' 1', '4294967296'] as $id) {
            $this->assertFailure('invalid_target', fn () => $provider->canonicalizeTargetId($id));
        }
        foreach ([array_diff_key($payload, ['title' => true]), [...$payload, 'lang_code' => 'en-GB'], [...$payload, 'title' => []], [...$payload, 'title' => str_repeat('x', 51)], [...$payload, 'metadesc' => str_repeat('x', 301)], [...$payload, 'title' => "\xC3\x28"]] as $invalid) {
            $this->assertFailure('invalid_payload', fn () => $provider->normalizePayload($invalid, 1));
        }
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
