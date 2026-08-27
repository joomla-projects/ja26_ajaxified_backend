<?php

namespace Joomla\Tests\Unit\Administrator\Components\Languages\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Languages\Administrator\Autosave\OverrideAutosaveProvider;
use Joomla\Tests\Unit\UnitTestCase;

class OverrideAutosaveProviderTest extends UnitTestCase
{
    private function provider(array $records = ['COM_EXAMPLE' => 'Value'], ?array $opposite = null): OverrideAutosaveProvider
    {
        return new OverrideAutosaveProvider(static fn (string $client, string $language): array => $client === 'site' ? $records : ($opposite ?? $records));
    }

    public function testContextAndCompositeIsolation(): void
    {
        $provider = $this->provider();
        $site     = OverrideAutosaveProvider::target('site', 'en-GB', 'COM_EXAMPLE');
        $admin    = OverrideAutosaveProvider::target('administrator', 'en-GB', 'COM_EXAMPLE');

        $this->assertSame('com_languages.override', $provider->getContext());
        $this->assertSame($site, $provider->canonicalizeTargetId($site));
        $this->assertNotSame($site, $admin);
        $this->assertTrue($provider->targetExists($site));
    }

    public function testNotFoundAndAclArePrivateAndAuthoritative(): void
    {
        $provider = $this->provider();
        $target   = OverrideAutosaveProvider::target('site', 'en-GB', 'COM_EXAMPLE');
        $user     = $this->createMock(User::class);
        $user->method('authorise')->with('core.edit', 'com_languages')->willReturn(false);

        $this->expectException(AutosaveException::class);
        $provider->authorize($user, $target, AutosaveOperation::Read);
    }

    public function testPayloadIsExactBoundedAndPreservesIncompleteText(): void
    {
        $provider = $this->provider();
        $payload  = ['key' => '', 'override' => "Unicode Ω\n%1\$s", 'both' => true];
        $this->assertSame($payload, $provider->normalizePayload($payload, 1));

        $this->expectException(AutosaveException::class);
        $provider->normalizePayload($payload + ['file' => '/tmp/override.ini'], 1);
    }

    public function testEntryRevisionChangesOnlyWithTargetEntry(): void
    {
        $target    = OverrideAutosaveProvider::target('site', 'en-GB', 'COM_EXAMPLE');
        $first     = $this->provider(['COM_EXAMPLE' => 'One', 'OTHER' => 'A'])->getBaseRevision($target);
        $unrelated = $this->provider(['COM_EXAMPLE' => 'One', 'OTHER' => 'B'])->getBaseRevision($target);
        $changed   = $this->provider(['COM_EXAMPLE' => 'Two', 'OTHER' => 'B'])->getBaseRevision($target);
        $opposite  = $this->provider(['COM_EXAMPLE' => 'One'], ['COM_EXAMPLE' => 'Other'])->getBaseRevision($target);
        $this->assertSame($first, $unrelated);
        $this->assertNotSame($first, $changed);
        $this->assertNotSame($first, $opposite);
    }

    /** @dataProvider invalidTargetProvider */
    public function testInvalidTargetsAreRejected(string $target): void
    {
        $this->expectException(AutosaveException::class);
        $this->provider()->canonicalizeTargetId($target);
    }

    public static function invalidTargetProvider(): iterable
    {
        yield 'numeric' => ['42'];
        yield 'path language' => [OverrideAutosaveProvider::target('site', '../en-GB', 'COM_EXAMPLE')];
        yield 'wrong client' => [OverrideAutosaveProvider::target('api', 'en-GB', 'COM_EXAMPLE')];
        yield 'lowercase key' => [OverrideAutosaveProvider::target('site', 'en-GB', 'com_example')];
    }
}
