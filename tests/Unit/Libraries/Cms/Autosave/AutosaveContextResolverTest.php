<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestProvider;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestTraitOnlyComponent;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests exact-context component capability discovery.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveContextResolverTest extends UnitTestCase
{
    /**
     * @testdox  rejects malformed and component-only contexts before booting a component
     *
     * @param   string  $context  The malformed context.
     *
     * @return  void
     *
     * @dataProvider malformedContextProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testMalformedContextsFailBeforeBoot(string $context): void
    {
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->expects($this->never())->method('bootComponent');
        $resolver = new AutosaveContextResolver($application, static fn (): bool => true);

        try {
            $resolver->resolve($context);
            $this->fail('The malformed context was accepted.');
        } catch (AutosaveException $exception) {
            $this->assertSame('malformed_context', $exception->getErrorCode());
        }
    }

    /**
     * Malformed context cases.
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
     * @testdox  resolves a fake third-party provider only through explicit interface capability and exact context
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExactContextResolvesFakeProvider(): void
    {
        $provider  = new ResolverTestProvider('com_example.record');
        $component = new ResolverTestCapableComponent(['com_example.record' => 'Record']);
        $component->setAutosaveProvider('com_example.record', $provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->expects($this->once())
            ->method('bootComponent')
            ->with('com_example')
            ->willReturn($component);
        $enabledComponents = [];
        $resolver          = new AutosaveContextResolver(
            $application,
            static function (string $componentName) use (&$enabledComponents): bool {
                $enabledComponents[] = $componentName;

                return true;
            }
        );

        $this->assertSame($provider, $resolver->resolve('com_example.record'));
        $this->assertSame(['com_example'], $enabledComponents);
    }

    /**
     * @testdox  resolves a valid provider owned by a hyphenated Joomla component
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testHyphenatedComponentContextResolves(): void
    {
        $context   = 'com_example-addon.record';
        $provider  = new ResolverTestProvider($context);
        $component = new ResolverTestCapableComponent([$context => 'Record']);
        $component->setAutosaveProvider($context, $provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->expects($this->once())
            ->method('bootComponent')
            ->with('com_example-addon')
            ->willReturn($component);
        $resolver = new AutosaveContextResolver(
            $application,
            static fn (string $componentName): bool => $componentName === 'com_example-addon'
        );

        $this->assertSame($provider, $resolver->resolve($context));
    }

    /**
     * @testdox  accepts the storage-compatible context boundary and rejects the next byte
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testContextLengthBoundary(): void
    {
        $acceptedContext = 'com_example.' . str_repeat('a', 243);
        $rejectedContext = 'com_example.' . str_repeat('a', 244);
        $provider        = new ResolverTestProvider($acceptedContext);
        $component       = new ResolverTestCapableComponent([$acceptedContext => 'Record']);
        $component->setAutosaveProvider($acceptedContext, $provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->expects($this->once())
            ->method('bootComponent')
            ->with('com_example')
            ->willReturn($component);
        $resolver = new AutosaveContextResolver($application, static fn (): bool => true);

        $this->assertSame(255, \strlen($acceptedContext));
        $this->assertSame(256, \strlen($rejectedContext));
        $this->assertSame($provider, $resolver->resolve($acceptedContext));

        $rejectedApplication = $this->createMock(CMSApplicationInterface::class);
        $rejectedApplication->expects($this->never())->method('bootComponent');
        $rejectedResolver = new AutosaveContextResolver($rejectedApplication, static fn (): bool => true);

        try {
            $rejectedResolver->resolve($rejectedContext);
            $this->fail('The overlong context was accepted.');
        } catch (AutosaveException $exception) {
            $this->assertSame('malformed_context', $exception->getErrorCode());
        }
    }

    /**
     * @testdox  rejects a trait-only component because the interface is the discoverable capability
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testTraitAloneDoesNotAdvertiseCapability(): void
    {
        $component = new ResolverTestTraitOnlyComponent();
        $component->setAutosaveProvider(
            'com_example.record',
            new ResolverTestProvider('com_example.record')
        );

        $this->assertUnsupported($this->applicationReturning($component), 'com_example.record');
    }

    /**
     * @testdox  rejects undeclared exact contexts on otherwise capable components
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testUndeclaredContextFailsClosed(): void
    {
        $component = new ResolverTestCapableComponent(['com_example.record' => 'Record']);
        $component->setAutosaveProvider(
            'com_example.record',
            new ResolverTestProvider('com_example.record')
        );

        $this->assertUnsupported($this->applicationReturning($component), 'com_example.other');
    }

    /**
     * @testdox  requires supported contexts to be associative keys rather than list values
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testContextDeclarationIsKeyBased(): void
    {
        $component = new ResolverTestCapableComponent(['com_example.record']);
        $component->setAutosaveProvider(
            'com_example.record',
            new ResolverTestProvider('com_example.record')
        );

        $this->assertUnsupported($this->applicationReturning($component), 'com_example.record');
    }

    /**
     * @testdox  resolves the same exact provider deterministically on repeated requests
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRepeatedResolutionIsDeterministic(): void
    {
        $provider   = new ResolverTestProvider('com_example.record');
        $component  = new ResolverTestCapableComponent(['com_example.record' => 'Record']);
        $component->setAutosaveProvider('com_example.record', $provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->expects($this->exactly(2))
            ->method('bootComponent')
            ->with('com_example')
            ->willReturn($component);
        $enabledCount = 0;
        $resolver     = new AutosaveContextResolver(
            $application,
            static function (string $componentName) use (&$enabledCount): bool {
                if ($componentName === 'com_example') {
                    $enabledCount++;
                }

                return true;
            }
        );

        $this->assertSame($provider, $resolver->resolve('com_example.record'));
        $this->assertSame($provider, $resolver->resolve('com_example.record'));
        $this->assertSame(2, $enabledCount);
    }

    /**
     * @testdox  normalizes disabled, incapable, mismatched and failing component resolution
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testResolutionFailuresAreNormalized(): void
    {
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->expects($this->never())->method('bootComponent');
        $this->assertUnsupported($application, 'com_example.record', static fn (): bool => false);

        $bootFailure = $this->createMock(CMSApplicationInterface::class);
        $bootFailure->method('bootComponent')->willThrowException(new \RuntimeException('private boot failure'));
        $this->assertUnsupported($bootFailure, 'com_example.record');

        $this->assertUnsupported(
            $this->applicationReturning($this->createMock(ComponentInterface::class)),
            'com_example.record'
        );

        $mismatched = new ResolverTestCapableComponent(['com_example.record' => 'Record']);
        $mismatched->forceProvider(
            'com_example.record',
            new ResolverTestProvider('com_example.other')
        );
        $this->assertUnsupported($this->applicationReturning($mismatched), 'com_example.record');

        $failing = new ResolverTestCapableComponent(['com_example.record' => 'Record'], true);
        $this->assertUnsupported($this->applicationReturning($failing), 'com_example.record');
    }

    /**
     * @testdox  exposes stable transport-neutral domain failure identifiers
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExceptionContractIsTransportNeutral(): void
    {
        $exception = new AutosaveException('example_failure', 'A safe public failure.');

        $this->assertSame('example_failure', $exception->getErrorCode());
        $this->assertSame('A safe public failure.', $exception->getMessage());
        $this->assertFalse(method_exists($exception, 'getHttpStatus'));
    }

    /**
     * @testdox  rejects malformed public domain failure identifiers
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExceptionRejectsMalformedErrorIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AutosaveException('Invalid Failure', 'A safe public failure.');
    }

    /**
     * Build an application mock returning a component.
     *
     * @return  CMSApplicationInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function applicationReturning(ComponentInterface $component): CMSApplicationInterface
    {
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->method('bootComponent')->willReturn($component);

        return $application;
    }

    /**
     * Assert normalized unsupported-context behavior.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function assertUnsupported(
        CMSApplicationInterface $application,
        string $context,
        ?callable $enabled = null
    ): void {
        $resolver = new AutosaveContextResolver($application, $enabled ?? static fn (): bool => true);

        try {
            $resolver->resolve($context);
            $this->fail('The unsupported context was accepted.');
        } catch (AutosaveException $exception) {
            $this->assertSame('unsupported_context', $exception->getErrorCode());
            $this->assertSame('The Autosave context is not supported.', $exception->getMessage());
        }
    }
}
