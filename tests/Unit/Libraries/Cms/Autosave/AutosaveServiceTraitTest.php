<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveServiceTrait;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestProvider;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests component-owned provider registration assistance.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveServiceTraitTest extends UnitTestCase
{
    use ContextTestCases;

    public function testExactProviderRegistration(): void
    {
        $service  = new class () {
            use AutosaveServiceTrait;
        };
        $provider = new ResolverTestProvider('com_example.record');

        $service->setAutosaveProvider('com_example.record', $provider);

        $this->assertSame($provider, $service->getAutosaveProvider('com_example.record'));
    }

    public function testHyphenatedComponentProviderRegistration(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };
        $provider = new ResolverTestProvider('com_example-addon.record');

        $service->setAutosaveProvider('com_example-addon.record', $provider);

        $this->assertSame($provider, $service->getAutosaveProvider('com_example-addon.record'));
    }

    public function testDuplicateProviderRegistrationIsRejected(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };
        $provider = new ResolverTestProvider('com_example.record');
        $service->setAutosaveProvider('com_example.record', $provider);

        $this->expectException(\LogicException::class);
        $service->setAutosaveProvider('com_example.record', $provider);
    }

    /**
     * @dataProvider malformedContextProvider
     */
    public function testMalformedProviderRegistrationIsRejected(string $context): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };

        $this->expectException(\InvalidArgumentException::class);
        $service->setAutosaveProvider($context, new ResolverTestProvider($context));
    }

    public function testMismatchedProviderRegistrationIsRejected(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };

        $this->expectException(\InvalidArgumentException::class);
        $service->setAutosaveProvider(
            'com_example.record',
            new ResolverTestProvider('com_example.other')
        );
    }

    public function testMissingExactProviderIsRejected(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };

        $this->expectException(\OutOfBoundsException::class);
        $service->getAutosaveProvider('com_example.other');
    }
}
