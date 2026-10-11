<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\Autosave\AutosaveStorageInterface;
use Joomla\CMS\Autosave\AutosaveTargetIdentity;
use Joomla\CMS\Date\Date;
use Joomla\CMS\User\User;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\CreateLifecycleTestProvider;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\DynamicDescriptorLifecycleTestProvider;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestStorage;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test the dynamic creation descriptor lifecycle contract.
 *
 * @testdox  The dynamic creation descriptor lifecycle
 *
 * @since    __DEPLOY_VERSION__
 */
class AutosaveDynamicDescriptorLifecycleTest extends UnitTestCase
{
    private const CONTINUATION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const GENERATION_ID   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DESCRIPTOR      = 'fd1:com_content.article:text:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testPreserveRecoversTheAnchoredDescriptorBeforeNormalization(): void
    {
        $events                              = [];
        $provider                            = new DynamicDescriptorLifecycleTestProvider($events);
        $provider->allowedScopes             = [self::DESCRIPTOR];
        $storage                             = new LifecycleTestStorage($events);
        $lifecycle                           = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $initialized                         = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), self::DESCRIPTOR);
        $target                              = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $storage->inspectResult              = $this->inspectedGeneration($initialized['base_revision']);
        $storage->inspectResult['target_id'] = $target;
        $events                              = [];

        $lifecycle->preserve($this->user(), self::CONTINUATION_ID, self::GENERATION_ID, 1, ['fieldparams' => ['maxlength' => '10']], 1, $this->now());

        // The anchored descriptor is recovered before the provider normalizes the
        // payload, and the normalized payload is what reaches scope authorization
        // and persistence.
        $this->assertContains('storage.getStaticScope', $events);
        $this->assertSame(
            [self::DESCRIPTOR, ['fieldparams' => ['maxlength' => '10']], 1],
            $provider->normalizeArguments[0]
        );
        $this->assertSame($provider->normalized, $provider->scopeArguments[array_key_last($provider->scopeArguments)][2]);
        $this->assertSame($provider->normalized, $storage->calls['preserve'][0][7]);
    }

    public function testPreserveFailsClosedWhenTheDescriptorIsNotBound(): void
    {
        $events                              = [];
        $provider                            = new DynamicDescriptorLifecycleTestProvider($events);
        $provider->allowedScopes             = [self::DESCRIPTOR];
        $storage                             = new LifecycleTestStorage($events);
        $lifecycle                           = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $initialized                         = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), self::DESCRIPTOR);
        $target                              = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $storage->boundScope                 = null;
        $storage->inspectResult              = $this->inspectedGeneration($initialized['base_revision']);
        $storage->inspectResult['target_id'] = $target;
        $events                              = [];

        $this->assertAutosaveFailure(
            'scope_required',
            fn () => $lifecycle->preserve($this->user(), self::CONTINUATION_ID, self::GENERATION_ID, 1, ['fieldparams' => ['maxlength' => '10']], 1, $this->now())
        );
        $this->assertArrayNotHasKey('preserve', $storage->calls);
        $this->assertSame([], $provider->normalizeArguments);
    }

    public function testPreserveDescriptorDriftFailsClosedBeforeAuthorization(): void
    {
        $events                              = [];
        $provider                            = new DynamicDescriptorLifecycleTestProvider($events);
        $provider->allowedScopes             = [self::DESCRIPTOR];
        $provider->denyNormalize             = true;
        $storage                             = new LifecycleTestStorage($events);
        $lifecycle                           = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $initialized                         = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), self::DESCRIPTOR);
        $target                              = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $storage->inspectResult              = $this->inspectedGeneration($initialized['base_revision']);
        $storage->inspectResult['target_id'] = $target;
        $events                              = [];

        $this->assertAutosaveFailure(
            'descriptor_stale',
            fn () => $lifecycle->preserve($this->user(), self::CONTINUATION_ID, self::GENERATION_ID, 1, ['fieldparams' => ['maxlength' => '10']], 1, $this->now())
        );
        $this->assertArrayNotHasKey('preserve', $storage->calls);
        $this->assertArrayNotHasKey('scope.authorize:preserve', $events);
    }

    public function testPrepareCanonicalActionNormalizesAgainstTheAnchoredDescriptor(): void
    {
        $events                              = [];
        $provider                            = new DynamicDescriptorLifecycleTestProvider($events);
        $provider->allowedScopes             = [self::DESCRIPTOR];
        $storage                             = new LifecycleTestStorage($events);
        $storage->canonicalResult            = ['operation_id' => 'operation-1', 'outcome' => 'pending', 'expires_at' => '2026-07-30 10:05:20'];
        $lifecycle                           = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $initialized                         = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), self::DESCRIPTOR);
        $target                              = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $storage->inspectResult              = $this->inspectedGeneration($initialized['base_revision']);
        $storage->inspectResult['target_id'] = $target;
        $events                              = [];

        $lifecycle->prepareCanonicalAction(
            $this->user(),
            'com_example.record',
            $target,
            self::CONTINUATION_ID,
            self::GENERATION_ID,
            4,
            ['fieldparams' => ['maxlength' => '10']],
            1,
            'apply',
            $initialized['base_revision'],
            $this->now()
        );

        $this->assertSame(self::DESCRIPTOR, $provider->normalizeArguments[0][0]);
        $this->assertSame($provider->normalized, $storage->calls['prepareCanonicalAction'][0][7]);
    }

    public function testScopeFreeCreateProvidersKeepScopeFreeNormalization(): void
    {
        $events                              = [];
        $provider                            = new CreateLifecycleTestProvider($events);
        $storage                             = new LifecycleTestStorage($events);
        $lifecycle                           = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $initialized                         = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now());
        $target                              = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $storage->inspectResult              = $this->inspectedGeneration($initialized['base_revision']);
        $storage->inspectResult['target_id'] = $target;
        $events                              = [];

        $lifecycle->preserve($this->user(), self::CONTINUATION_ID, self::GENERATION_ID, 1, ['draft' => true], 1, $this->now());

        $this->assertContains('normalizePayload', $events);
        $this->assertArrayNotHasKey('storage.getStaticScope', $events);
        $this->assertArrayNotHasKey('provider.normalizeDescriptor', $events);
    }

    private function lifecycle(
        \Joomla\CMS\Autosave\AutosaveProviderInterface $provider,
        AutosaveStorageInterface $storage,
        array &$events,
        string $siteSecret = ''
    ): AutosaveLifecycle {
        $component = new ResolverTestCapableComponent(['com_example.record' => true]);
        $component->setAutosaveProvider('com_example.record', $provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->method('bootComponent')->willReturnCallback(
            static function (string $componentName) use ($component): object {
                if ($componentName !== 'com_example') {
                    throw new \RuntimeException('Unsupported component.');
                }

                return $component;
            }
        );
        $resolver = new AutosaveContextResolver(
            $application,
            static fn (string $componentName): bool => $componentName === 'com_example'
        );

        return new AutosaveLifecycle($resolver, $storage, $siteSecret);
    }

    private function inspectedGeneration(string $baseRevision): array
    {
        return [
            'continuation_id'        => self::CONTINUATION_ID,
            'context'                => 'com_example.record',
            'target_id'              => 'target-42',
            'generation_id'          => self::GENERATION_ID,
            'base_revision'          => $baseRevision,
            'state'                  => 'active',
            'client_revision'        => 3,
            'payload'                => ['fieldparams' => ['maxlength' => '10']],
            'payload_schema_version' => 1,
            'created_at'             => '2026-07-30 10:00:00',
            'updated_at'             => '2026-07-30 10:00:10',
            'expires_at'             => '2026-07-30 10:01:10',
            'terminal_at'            => null,
            'retain_until'           => null,
        ];
    }

    private function user(): User
    {
        $user     = new User();
        $user->id = 7;

        return $user;
    }

    private function now(): Date
    {
        return new Date('2026-07-30 10:00:20', 'UTC');
    }

    private function assertAutosaveFailure(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (AutosaveException $exception) {
            $this->assertSame($code, $exception->getErrorCode());

            return;
        }

        $this->fail('Expected AutosaveException with code ' . $code . ' was not raised.');
    }
}
