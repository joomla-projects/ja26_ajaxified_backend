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
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ServiceTraitTestProvider;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests component-owned provider registration assistance.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveServiceTraitTest extends UnitTestCase
{
    /**
     * @testdox  registers and resolves an exact provider
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExactProviderRegistration(): void
    {
        $service  = new class () {
            use AutosaveServiceTrait;
        };
        $provider = new ServiceTraitTestProvider('com_example.record');

        $service->setAutosaveProvider('com_example.record', $provider);

        $this->assertSame($provider, $service->getAutosaveProvider('com_example.record'));
    }

    /**
     * @testdox  accepts an exact context owned by a hyphenated Joomla component
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testHyphenatedComponentProviderRegistration(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };
        $provider = new ServiceTraitTestProvider('com_example-addon.record');

        $service->setAutosaveProvider('com_example-addon.record', $provider);

        $this->assertSame($provider, $service->getAutosaveProvider('com_example-addon.record'));
    }

    /**
     * @testdox  rejects duplicate exact provider registration
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDuplicateProviderRegistrationIsRejected(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };
        $provider = new ServiceTraitTestProvider('com_example.record');
        $service->setAutosaveProvider('com_example.record', $provider);

        $this->expectException(\LogicException::class);
        $service->setAutosaveProvider('com_example.record', $provider);
    }

    /**
     * @testdox  rejects malformed provider registration contexts
     *
     * @param   string  $context  The malformed context.
     *
     * @return  void
     *
     * @dataProvider malformedContextProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testMalformedProviderRegistrationIsRejected(string $context): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };

        $this->expectException(\InvalidArgumentException::class);
        $service->setAutosaveProvider($context, new ServiceTraitTestProvider($context));
    }

    /**
     * Malformed provider registration contexts.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function malformedContextProvider(): array
    {
        return [
            'empty'                => [''],
            'component only'       => ['com_example'],
            'uppercase component'  => ['com_Example.record'],
            'uppercase entity'     => ['com_example.Record'],
            'leading whitespace'   => [' com_example.record'],
            'trailing whitespace'  => ['com_example.record '],
            'internal whitespace'  => ['com_example.re cord'],
            'empty component'      => ['com_.record'],
            'empty entity'         => ['com_example.'],
            'extra segment'        => ['com_example.record.extra'],
            'path syntax'          => ['com_example../record'],
            'class syntax'         => ['Vendor\\Provider'],
            'wildcard'             => ['com_*.record'],
            'leading prefix'       => ['xcom_example.record'],
            'trailing suffix'      => ['com_example.record*'],
            'leading hyphen'       => ['com_-example.record'],
            'trailing hyphen'      => ['com_example-.record'],
            'empty hyphen segment' => ['com_example--addon.record'],
        ];
    }

    /**
     * @testdox  rejects provider and registration context mismatches
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testMismatchedProviderRegistrationIsRejected(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };

        $this->expectException(\InvalidArgumentException::class);
        $service->setAutosaveProvider(
            'com_example.record',
            new ServiceTraitTestProvider('com_example.other')
        );
    }

    /**
     * @testdox  exact lookup rejects a missing context
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testMissingExactProviderIsRejected(): void
    {
        $service = new class () {
            use AutosaveServiceTrait;
        };

        $this->expectException(\OutOfBoundsException::class);
        $service->getAutosaveProvider('com_example.other');
    }
}
