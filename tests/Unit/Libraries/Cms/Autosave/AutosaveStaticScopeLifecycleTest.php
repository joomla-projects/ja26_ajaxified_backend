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
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestStorage;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\StaticScopeLifecycleTestProvider;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test the immutable static creation scope lifecycle contract.
 *
 * @testdox  The immutable static creation scope lifecycle
 *
 * @since    __DEPLOY_VERSION__
 */
class AutosaveStaticScopeLifecycleTest extends UnitTestCase
{
    private const CONTINUATION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const GENERATION_ID   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testInitializeCreateAnchorsOneCanonicalScopeAfterAuthorization(): void
    {
        $events    = [];
        $provider  = new StaticScopeLifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events, 'site-secret');

        $result = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');

        $this->assertSame($storage->boundScope, 'com_content');
        $this->assertContains('storage.bindStaticScope', $events);
        $this->assertLessThan(
            array_search('storage.initialize', $events, true),
            array_search('scope.authorize:initialize-create', $events, true)
        );
        $this->assertLessThan(
            array_search('storage.bindStaticScope', $events, true),
            array_search('storage.initialize', $events, true)
        );
        $this->assertMatchesRegularExpression('/^p1:[0-9a-f]{64}$/D', $result['target_id']);
    }

    public function testInitializeCreateRetryWithSameScopeIsIdempotent(): void
    {
        $events    = [];
        $provider  = new StaticScopeLifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events, 'site-secret');

        $first  = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');
        $second = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');

        $this->assertSame($first['target_id'], $second['target_id']);
        $this->assertSame($first['continuation_id'], $second['continuation_id']);
    }

    public function testInitializeCreateRetryWithDifferentScopeFailsClosed(): void
    {
        $events    = [];
        $provider  = new StaticScopeLifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events, 'site-secret');

        $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');

        $this->assertAutosaveFailure(
            'scope_conflict',
            fn () => $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_banners')
        );
        $this->assertSame('com_content', $storage->boundScope);
    }

    public function testInitializeCreateRequiresCandidateForScopeProvider(): void
    {
        $events    = [];
        $provider  = new StaticScopeLifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events, 'site-secret');

        $this->assertAutosaveFailure(
            'scope_required',
            fn () => $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now())
        );
        $this->assertArrayNotHasKey('initialize', $storage->calls);
    }

    public function testInitializeCreateRejectsInvalidCandidateBeforeStorage(): void
    {
        $events    = [];
        $provider  = new StaticScopeLifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events, 'site-secret');

        $this->assertAutosaveFailure(
            'invalid_scope',
            fn () => $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_evil;drop')
        );
        $this->assertArrayNotHasKey('initialize', $storage->calls);
    }

    public function testInitializeCreateRejectsCandidateForScopeFreeProvider(): void
    {
        $events                    = [];
        $provider                  = new \Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\CreateLifecycleTestProvider($events);
        $storage                   = new LifecycleTestStorage($events);
        $lifecycle                 = $this->lifecycle($provider, $storage, $events, 'site-secret');

        $this->assertAutosaveFailure(
            'scope_unsupported',
            fn () => $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content')
        );
        $this->assertArrayNotHasKey('initialize', $storage->calls);
    }

    public function testCreateRevisionIncludesTheStaticScopeContractVersion(): void
    {
        $events    = [];
        $provider  = new StaticScopeLifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events, 'site-secret');

        $first                  = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');
        $provider->scopeVersion = 'scope-v2';
        $second                 = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-2', $this->now(), 'com_content');

        $this->assertNotSame($first['base_revision'], $second['base_revision']);
    }

    public function testPreserveUsesAnchoredScopeAuthorization(): void
    {
        $events                              = [];
        $provider                            = new StaticScopeLifecycleTestProvider($events);
        $storage                             = new LifecycleTestStorage($events);
        $target                              = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $lifecycle                           = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $initialized                         = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');
        $storage->inspectResult              = $this->inspectedGeneration($initialized['base_revision']);
        $storage->inspectResult['target_id'] = $target;
        $events                              = [];

        $lifecycle->preserve($this->user(), self::CONTINUATION_ID, self::GENERATION_ID, 1, ['draft' => true], 1, $this->now());

        $this->assertContains('storage.getStaticScope', $events);
        $this->assertSame('com_content', $provider->scopeArguments[array_key_last($provider->scopeArguments)][0]);
    }

    public function testPreserveFailsClosedWhenScopeIsNotBound(): void
    {
        $events                              = [];
        $provider                            = new StaticScopeLifecycleTestProvider($events);
        $storage                             = new LifecycleTestStorage($events);
        $lifecycle                           = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $initialized                         = $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');
        $target                              = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $storage->boundScope                 = null;
        $storage->inspectResult              = $this->inspectedGeneration($initialized['base_revision']);
        $storage->inspectResult['target_id'] = $target;
        $events                              = [];

        $this->assertAutosaveFailure(
            'scope_required',
            fn () => $lifecycle->preserve($this->user(), self::CONTINUATION_ID, self::GENERATION_ID, 1, ['draft' => true], 1, $this->now())
        );
    }

    public function testFinalizeVerifiesFinalTargetAgainstAnchoredScopeBeforeRetirement(): void
    {
        $events                    = [];
        $provider                  = new StaticScopeLifecycleTestProvider($events);
        $storage                   = new LifecycleTestStorage($events);
        $target                    = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $lifecycle                 = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');
        $storage->canonicalResult  = [
            'operation_id'           => 'operation-1',
            'continuation_id'        => self::CONTINUATION_ID,
            'context'                => 'com_example.record',
            'target_id'              => $target,
            'intent'                 => 'apply',
            'outcome'                => 'pending',
            'expected_base_revision' => 'base-1',
        ];
        $events                    = [];

        $lifecycle->finalizeCanonicalActionSuccess($this->user(), 'operation-1', 'com_example.record', $target, 'apply', '73', $this->now());

        $this->assertContains('scope.verifyFinal', $events);
        $this->assertSame(['73', 'com_content'], $provider->verifyArguments[0]);
        $this->assertArrayHasKey('finalizeCanonicalActionSuccess', $storage->calls);
    }

    public function testFinalizeScopeMismatchFailsClosedWithoutRetiring(): void
    {
        $events                     = [];
        $provider                   = new StaticScopeLifecycleTestProvider($events);
        $provider->verifyFinalFails = true;
        $storage                    = new LifecycleTestStorage($events);
        $target                     = AutosaveTargetIdentity::provisional(7, 'com_example.record', 'request-1', 'site-secret');
        $lifecycle                  = $this->lifecycle($provider, $storage, $events, 'site-secret');
        $lifecycle->initializeCreate($this->user(), 'com_example.record', 'request-1', $this->now(), 'com_content');
        $storage->canonicalResult  = ['operation_id' => 'operation-1', 'continuation_id' => self::CONTINUATION_ID];
        $events                    = [];

        $this->assertAutosaveFailure(
            'scope_mismatch',
            fn () => $lifecycle->finalizeCanonicalActionSuccess($this->user(), 'operation-1', 'com_example.record', $target, 'apply', '73', $this->now())
        );
        $this->assertArrayNotHasKey('finalizeCanonicalActionSuccess', $storage->calls);
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
            'payload'                => ['title' => 'Draft'],
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
