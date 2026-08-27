<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveTargetIdentity;
use Joomla\Tests\Unit\UnitTestCase;

class AutosaveTargetIdentityTest extends UnitTestCase
{
    public function testNumericIdentityRetainsItsCanonicalRepresentation(): void
    {
        $this->assertSame('42', AutosaveTargetIdentity::numeric('42'));
    }

    public function testCompositeIdentityIsDeterministicAndReversible(): void
    {
        $target = AutosaveTargetIdentity::composite('languages.override', ['site', 'en-GB', 'COM_EXAMPLE_VALUE']);
        $this->assertSame(['type' => 'languages.override', 'members' => ['site', 'en-GB', 'COM_EXAMPLE_VALUE']], AutosaveTargetIdentity::parseComposite($target));
        $this->assertNotSame($target, AutosaveTargetIdentity::composite('languages.override', ['administrator', 'en-GB', 'COM_EXAMPLE_VALUE']));
    }

    public function testLengthPrefixesPreventDelimiterCollisions(): void
    {
        $this->assertNotSame(AutosaveTargetIdentity::composite('test', ['a|1:b', 'c']), AutosaveTargetIdentity::composite('test', ['a', '1:b|1:c']));
    }

    /** @dataProvider invalidIdentityProvider */
    public function testInvalidIdentitiesAreRejected(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }

    public static function invalidIdentityProvider(): iterable
    {
        yield 'numeric zero' => [static fn () => AutosaveTargetIdentity::numeric('0')];
        yield 'numeric leading zero' => [static fn () => AutosaveTargetIdentity::numeric('01')];
        yield 'numeric bounded overflow' => [static fn () => AutosaveTargetIdentity::numeric('2147483648', 2147483647)];
        yield 'control character' => [static fn () => AutosaveTargetIdentity::composite('test', ["a\0b"])];
        yield 'oversized' => [static fn () => AutosaveTargetIdentity::composite('test', [str_repeat('x', 191)])];
        yield 'noncanonical length' => [static fn () => AutosaveTargetIdentity::parseComposite('c1|04:test|1:a')];
    }
}
