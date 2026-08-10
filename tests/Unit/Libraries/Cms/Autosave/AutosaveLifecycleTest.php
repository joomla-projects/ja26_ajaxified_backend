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
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveStorage;
use Joomla\CMS\Autosave\AutosaveStorageInterface;
use Joomla\CMS\Date\Date;
use Joomla\CMS\User\User;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestProvider;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestStorage;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestProvider;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for \Joomla\CMS\Autosave\AutosaveLifecycle.
 *
 * @testdox  The Autosave lifecycle
 *
 * @since    __DEPLOY_VERSION__
 */
class AutosaveLifecycleTest extends UnitTestCase
{
    private const CONTINUATION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const GENERATION_ID   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @testdox  storage implements the narrow persistence contract used by lifecycle
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testStorageImplementsLifecycleContract(): void
    {
        $this->assertContains(AutosaveStorageInterface::class, class_implements(AutosaveStorage::class));

        $constructor = new \ReflectionMethod(AutosaveLifecycle::class, '__construct');
        $parameters  = $constructor->getParameters();

        $this->assertSame(AutosaveContextResolver::class, $parameters[0]->getType()->getName());
        $this->assertSame(AutosaveStorageInterface::class, $parameters[1]->getType()->getName());
    }

    /**
     * @testdox  initializes through the exact provider contract in security-sensitive order
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInitializeOrchestratesCanonicalBindingInOrder(): void
    {
        $events                    = [];
        $provider                  = new LifecycleTestProvider($events);
        $storage                   = new LifecycleTestStorage($events);
        $storage->initializeResult = [
            'continuation_id' => self::CONTINUATION_ID,
            'generation_id'   => self::GENERATION_ID,
        ];
        $lifecycle = $this->lifecycle($provider, $storage, $events);
        $now       = $this->now();

        $result = $lifecycle->initialize(
            $this->user(),
            'com_example.record',
            'client-target',
            " key \u{00E9} ",
            $now
        );

        $this->assertSame(
            [
                'continuation_id'        => self::CONTINUATION_ID,
                'generation_id'          => self::GENERATION_ID,
                'context'                => 'com_example.record',
                'target_id'              => 'target-42',
                'base_revision'          => 'base-1',
                'payload_schema_version' => 1,
            ],
            $result
        );
        $this->assertSame(
            [
                'resolve',
                'canonicalizeTargetId',
                'targetExists',
                'authorize:initialize',
                'getBaseRevision',
                'getPayloadSchemaVersion',
                'storage.initialize',
            ],
            $events
        );
        $this->assertSame(
            [7, 'com_example.record', 'target-42', 'base-1', " key \u{00E9} ", $now],
            $storage->calls['initialize'][0]
        );
    }

    /**
     * @testdox  target absence stops initialization before authorization or persistence
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInitializeTargetNotFoundStopsBeforeAuthorization(): void
    {
        $events           = [];
        $provider         = new LifecycleTestProvider($events);
        $provider->exists = false;
        $storage          = new LifecycleTestStorage($events);
        $lifecycle        = $this->lifecycle($provider, $storage, $events);

        $this->assertAutosaveFailure(
            'target_not_found',
            fn () => $lifecycle->initialize(
                $this->user(),
                'com_example.record',
                'client-target',
                'key',
                $this->now()
            )
        );
        $this->assertSame(['resolve', 'canonicalizeTargetId', 'targetExists'], $events);
        $this->assertArrayNotHasKey('initialize', $storage->calls);
    }

    /**
     * @testdox  authorization failure prevents initialization metadata queries and persistence
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInitializeAuthorizationFailureStopsStorage(): void
    {
        $events                     = [];
        $provider                   = new LifecycleTestProvider($events);
        $provider->authorizeFailure = new AutosaveException('forbidden', 'Forbidden.');
        $storage                    = new LifecycleTestStorage($events);
        $lifecycle                  = $this->lifecycle($provider, $storage, $events);

        try {
            $lifecycle->initialize(
                $this->user(),
                'com_example.record',
                'client-target',
                'key',
                $this->now()
            );
            $this->fail('Authorization failure did not stop initialization.');
        } catch (AutosaveException $exception) {
            $this->assertSame($provider->authorizeFailure, $exception);
        }

        $this->assertSame(
            ['resolve', 'canonicalizeTargetId', 'targetExists', 'authorize:initialize'],
            $events
        );
        $this->assertArrayNotHasKey('initialize', $storage->calls);
    }

    /**
     * @testdox  initialization infrastructure failures propagate unchanged
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInitializeStorageFailurePropagates(): void
    {
        $events                     = [];
        $failure                    = new \RuntimeException('storage unavailable');
        $provider                   = new LifecycleTestProvider($events);
        $storage                    = new LifecycleTestStorage($events);
        $storage->initializeFailure = $failure;
        $lifecycle                  = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            $failure,
            $this->captureFailure(
                fn () => $lifecycle->initialize(
                    $this->user(),
                    'com_example.record',
                    'client-target',
                    'key',
                    $this->now()
                )
            )
        );
    }

    /**
     * @testdox  preserves only normalized payload against the trusted stored binding
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveUsesInspectedBindingAndNormalizedPayloadInOrder(): void
    {
        $events                 = [];
        $provider               = new LifecycleTestProvider($events);
        $provider->normalized   = ['normalized' => '<p>draft</p>'];
        $storage                = new LifecycleTestStorage($events);
        $storage->inspectResult = $this->inspectedGeneration();
        $lifecycle              = $this->lifecycle($provider, $storage, $events);
        $now                    = $this->now();
        $payload                = ['client' => '<p>draft</p>'];

        $result = $lifecycle->preserve(
            $this->user(),
            self::CONTINUATION_ID,
            self::GENERATION_ID,
            3,
            $payload,
            1,
            $now
        );

        $this->assertSame(['status' => 'accepted'], $result);
        $this->assertSame(
            [
                'storage.inspect',
                'resolve',
                'authorize:preserve',
                'getPayloadSchemaVersion',
                'normalizePayload',
                'storage.preserve',
            ],
            $events
        );
        $this->assertSame([$payload, 1], $provider->normalizationArguments);
        $this->assertSame(
            [
                7,
                self::CONTINUATION_ID,
                self::GENERATION_ID,
                'com_example.record',
                'target-42',
                'base-1',
                3,
                ['normalized' => '<p>draft</p>'],
                1,
                $now,
            ],
            $storage->calls['preserve'][0]
        );
        $this->assertNotContains('canonicalizeTargetId', $events);
        $this->assertNotContains('targetExists', $events);
        $this->assertNotContains('getBaseRevision', $events);
    }

    /**
     * @testdox  returns both storage preservation acknowledgements unchanged
     *
     * @param   string  $status  The storage acknowledgement.
     *
     * @return  void
     *
     * @dataProvider preservationStatusProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveReturnsStorageStatus(string $status): void
    {
        $events                  = [];
        $provider                = new LifecycleTestProvider($events);
        $storage                 = new LifecycleTestStorage($events);
        $storage->inspectResult  = $this->inspectedGeneration();
        $storage->preserveStatus = $status;
        $lifecycle               = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            ['status' => $status],
            $lifecycle->preserve(
                $this->user(),
                self::CONTINUATION_ID,
                self::GENERATION_ID,
                1,
                ['value' => 'draft'],
                1,
                $this->now()
            )
        );
    }

    /**
     * Preservation acknowledgement cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function preservationStatusProvider(): array
    {
        return [
            'accepted'   => ['accepted'],
            'idempotent' => ['idempotent'],
        ];
    }

    /**
     * @testdox  preservation authorization failure prevents schema and payload processing
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveAuthorizationFailureStopsNormalizationAndMutation(): void
    {
        $events                     = [];
        $provider                   = new LifecycleTestProvider($events);
        $provider->authorizeFailure = new AutosaveException('forbidden', 'Forbidden.');
        $storage                    = new LifecycleTestStorage($events);
        $storage->inspectResult     = $this->inspectedGeneration();
        $lifecycle                  = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            $provider->authorizeFailure,
            $this->captureFailure(
                fn () => $lifecycle->preserve(
                    $this->user(),
                    self::CONTINUATION_ID,
                    self::GENERATION_ID,
                    1,
                    ['value' => 'draft'],
                    1,
                    $this->now()
                )
            )
        );
        $this->assertSame(['storage.inspect', 'resolve', 'authorize:preserve'], $events);
        $this->assertArrayNotHasKey('preserve', $storage->calls);
    }

    /**
     * @testdox  schema mismatch stops preservation before normalization and mutation
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveSchemaMismatchUsesExistingFailureVocabulary(): void
    {
        $events                 = [];
        $provider               = new LifecycleTestProvider($events);
        $storage                = new LifecycleTestStorage($events);
        $storage->inspectResult = $this->inspectedGeneration();
        $lifecycle              = $this->lifecycle($provider, $storage, $events);

        $this->assertAutosaveFailure(
            'unsupported_schema_version',
            fn () => $lifecycle->preserve(
                $this->user(),
                self::CONTINUATION_ID,
                self::GENERATION_ID,
                1,
                ['value' => 'draft'],
                2,
                $this->now()
            )
        );
        $this->assertSame(
            ['storage.inspect', 'resolve', 'authorize:preserve', 'getPayloadSchemaVersion'],
            $events
        );
        $this->assertArrayNotHasKey('preserve', $storage->calls);
    }

    /**
     * @testdox  invalid provider payload never reaches persistence
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveNormalizationFailureStopsStorageMutation(): void
    {
        $events                      = [];
        $provider                    = new LifecycleTestProvider($events);
        $provider->normalizeFailure  = new AutosaveException('invalid_payload', 'Invalid payload.');
        $storage                     = new LifecycleTestStorage($events);
        $storage->inspectResult      = $this->inspectedGeneration();
        $lifecycle                   = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            $provider->normalizeFailure,
            $this->captureFailure(
                fn () => $lifecycle->preserve(
                    $this->user(),
                    self::CONTINUATION_ID,
                    self::GENERATION_ID,
                    1,
                    ['value' => new \stdClass()],
                    1,
                    $this->now()
                )
            )
        );
        $this->assertArrayNotHasKey('preserve', $storage->calls);
    }

    /**
     * @testdox  storage failures propagate unchanged during preservation
     *
     * @param   \Throwable  $failure  The configured storage failure.
     *
     * @return  void
     *
     * @dataProvider storageFailureProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreserveStorageFailuresPropagate(\Throwable $failure): void
    {
        $events                   = [];
        $provider                 = new LifecycleTestProvider($events);
        $storage                  = new LifecycleTestStorage($events);
        $storage->inspectResult   = $this->inspectedGeneration();
        $storage->preserveFailure = $failure;
        $lifecycle                = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            $failure,
            $this->captureFailure(
                fn () => $lifecycle->preserve(
                    $this->user(),
                    self::CONTINUATION_ID,
                    self::GENERATION_ID,
                    1,
                    ['value' => 'draft'],
                    1,
                    $this->now()
                )
            )
        );
    }

    /**
     * Storage failure cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function storageFailureProvider(): array
    {
        return [
            'domain'         => [new AutosaveException('revision_conflict', 'Conflict.')],
            'infrastructure' => [new \RuntimeException('database unavailable')],
        ];
    }

    /**
     * @testdox  null detection avoids an unnecessary canonical revision query
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectReturnsNullWithoutRevisionQuery(): void
    {
        $events                = [];
        $provider              = new LifecycleTestProvider($events);
        $storage               = new LifecycleTestStorage($events);
        $storage->detectResult = null;
        $lifecycle             = $this->lifecycle($provider, $storage, $events);

        $this->assertNull(
            $lifecycle->detect($this->user(), 'com_example.record', 'client-target', $this->now())
        );
        $this->assertSame(
            [
                'resolve',
                'canonicalizeTargetId',
                'targetExists',
                'authorize:detect',
                'storage.detect',
            ],
            $events
        );
    }

    /**
     * @testdox  detection derives current and stale metadata without exposing payload
     *
     * @param   string  $storedRevision   The stored base revision.
     * @param   string  $currentRevision  The provider's current base revision.
     * @param   string  $classification   The expected derived classification.
     *
     * @return  void
     *
     * @dataProvider classificationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectDerivesClassification(
        string $storedRevision,
        string $currentRevision,
        string $classification
    ): void {
        $events                 = [];
        $provider               = new LifecycleTestProvider($events);
        $provider->baseRevision = $currentRevision;
        $storage                = new LifecycleTestStorage($events);
        $storage->detectResult  = $this->detectedGeneration($storedRevision);
        $lifecycle              = $this->lifecycle($provider, $storage, $events);
        $now                    = $this->now();

        $result = $lifecycle->detect($this->user(), 'com_example.record', 'client-target', $now);

        $this->assertSame($classification, $result['classification']);
        $this->assertSame($storedRevision, $result['base_revision']);
        $this->assertSame($currentRevision, $result['current_base_revision']);
        $this->assertArrayNotHasKey('payload', $result);
        $this->assertSame(
            [
                'resolve',
                'canonicalizeTargetId',
                'targetExists',
                'authorize:detect',
                'storage.detect',
                'getBaseRevision',
            ],
            $events
        );
        $this->assertSame(
            [7, 'com_example.record', 'target-42', $now],
            $storage->calls['detect'][0]
        );
        $this->assertArrayNotHasKey('inspect', $storage->calls);
    }

    /**
     * Current and stale classification cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function classificationProvider(): array
    {
        return [
            'current' => ['base-1', 'base-1', 'current'],
            'stale'   => ['base-1', 'base-2', 'stale'],
        ];
    }

    /**
     * @testdox  detection target absence and authorization failures stop persistence
     *
     * @param   string  $failurePoint  The provider failure point.
     * @param   string  $errorCode     The expected stable error identifier.
     *
     * @return  void
     *
     * @dataProvider detectionFailureProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectProviderFailuresStopStorage(string $failurePoint, string $errorCode): void
    {
        $events   = [];
        $provider = new LifecycleTestProvider($events);

        if ($failurePoint === 'target') {
            $provider->exists = false;
        } else {
            $provider->authorizeFailure = new AutosaveException($errorCode, 'Denied.');
        }

        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events);

        $this->assertAutosaveFailure(
            $errorCode,
            fn () => $lifecycle->detect(
                $this->user(),
                'com_example.record',
                'client-target',
                $this->now()
            )
        );
        $this->assertArrayNotHasKey('detect', $storage->calls);
    }

    /**
     * @testdox  detection infrastructure failures propagate unchanged
     *
     * @param   string  $failurePoint  The configured failure point.
     *
     * @return  void
     *
     * @dataProvider detectionInfrastructureFailureProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectInfrastructureFailuresPropagate(string $failurePoint): void
    {
        $events   = [];
        $failure  = new \RuntimeException($failurePoint . ' unavailable');
        $provider = new LifecycleTestProvider($events);
        $storage  = new LifecycleTestStorage($events);

        if ($failurePoint === 'storage') {
            $storage->detectFailure = $failure;
        } else {
            $storage->detectResult         = $this->detectedGeneration('base-1');
            $provider->baseRevisionFailure = $failure;
        }

        $lifecycle = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            $failure,
            $this->captureFailure(
                fn () => $lifecycle->detect(
                    $this->user(),
                    'com_example.record',
                    'client-target',
                    $this->now()
                )
            )
        );
    }

    /**
     * Detection infrastructure failure cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function detectionInfrastructureFailureProvider(): array
    {
        return [
            'storage'  => ['storage'],
            'provider' => ['provider'],
        ];
    }

    /**
     * Detection provider failure cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function detectionFailureProvider(): array
    {
        return [
            'target missing'       => ['target', 'target_not_found'],
            'authorization denied' => ['authorize', 'forbidden'],
        ];
    }

    /**
     * @testdox  read authorizes the stored binding before returning payload and classification
     *
     * @param   string  $storedRevision   The stored base revision.
     * @param   string  $currentRevision  The provider's current base revision.
     * @param   string  $classification   The expected derived classification.
     *
     * @return  void
     *
     * @dataProvider classificationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadReturnsAuthorizedPayload(
        string $storedRevision,
        string $currentRevision,
        string $classification
    ): void {
        $events                  = [];
        $provider                = new LifecycleTestProvider($events);
        $provider->baseRevision  = $currentRevision;
        $storage                 = new LifecycleTestStorage($events);
        $storage->inspectResult  = $this->inspectedGeneration($storedRevision);
        $lifecycle               = $this->lifecycle($provider, $storage, $events);

        $result = $lifecycle->read(
            $this->user(),
            self::CONTINUATION_ID,
            self::GENERATION_ID,
            $this->now()
        );

        $this->assertSame(['title' => 'Draft'], $result['payload']);
        $this->assertSame($classification, $result['classification']);
        $this->assertSame($currentRevision, $result['current_base_revision']);
        $this->assertSame(
            ['storage.inspect', 'resolve', 'authorize:read', 'getBaseRevision'],
            $events
        );
        $this->assertNotContains('canonicalizeTargetId', $events);
        $this->assertNotContains('targetExists', $events);
    }

    /**
     * @testdox  revoked read permission prevents revision lookup and result exposure
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadAuthorizationFailureDoesNotExposePayload(): void
    {
        $events                     = [];
        $provider                   = new LifecycleTestProvider($events);
        $provider->authorizeFailure = new AutosaveException('forbidden', 'Forbidden.');
        $storage                    = new LifecycleTestStorage($events);
        $storage->inspectResult     = $this->inspectedGeneration();
        $lifecycle                  = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            $provider->authorizeFailure,
            $this->captureFailure(
                fn () => $lifecycle->read(
                    $this->user(),
                    self::CONTINUATION_ID,
                    self::GENERATION_ID,
                    $this->now()
                )
            )
        );
        $this->assertSame(['storage.inspect', 'resolve', 'authorize:read'], $events);
    }

    /**
     * @testdox  read preserves storage privacy failures and resolver failures
     *
     * @param   string  $failurePoint  The configured failure point.
     * @param   string  $errorCode     The expected stable error identifier.
     *
     * @return  void
     *
     * @dataProvider readFailureProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadDomainFailuresPropagate(string $failurePoint, string $errorCode): void
    {
        $events   = [];
        $provider = new LifecycleTestProvider($events);
        $storage  = new LifecycleTestStorage($events);

        if ($failurePoint === 'storage') {
            $storage->inspectFailure = new AutosaveException($errorCode, 'Draft not found.');
        } else {
            $storage->inspectResult            = $this->inspectedGeneration();
            $storage->inspectResult['context'] = 'com_missing.record';
        }

        $lifecycle = $this->lifecycle($provider, $storage, $events);

        $this->assertAutosaveFailure(
            $errorCode,
            fn () => $lifecycle->read(
                $this->user(),
                self::CONTINUATION_ID,
                self::GENERATION_ID,
                $this->now()
            )
        );
    }

    /**
     * @testdox  read storage infrastructure failures propagate unchanged before resolution
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadInfrastructureFailurePropagates(): void
    {
        $events                  = [];
        $failure                 = new \RuntimeException('storage unavailable');
        $provider                = new LifecycleTestProvider($events);
        $storage                 = new LifecycleTestStorage($events);
        $storage->inspectFailure = $failure;
        $lifecycle               = $this->lifecycle($provider, $storage, $events);

        $this->assertSame(
            $failure,
            $this->captureFailure(
                fn () => $lifecycle->read(
                    $this->user(),
                    self::CONTINUATION_ID,
                    self::GENERATION_ID,
                    $this->now()
                )
            )
        );
        $this->assertSame(['storage.inspect'], $events);
    }

    /**
     * @testdox  read rejects a provider whose declared context differs from the stored context
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadProviderContextMismatchPropagatesResolverFailure(): void
    {
        $events                 = [];
        $provider               = new LifecycleTestProvider($events);
        $storage                = new LifecycleTestStorage($events);
        $storage->inspectResult = $this->inspectedGeneration();
        $component              = new ResolverTestCapableComponent(['com_example.record' => true]);
        $component->setAutosaveProvider('com_example.record', $provider);
        $component->forceProvider(
            'com_example.record',
            new ResolverTestProvider('com_other.record')
        );
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->method('bootComponent')->willReturn($component);
        $resolver  = new AutosaveContextResolver($application, static fn (): bool => true);
        $lifecycle = new AutosaveLifecycle($resolver, $storage);

        $this->assertAutosaveFailure(
            'unsupported_context',
            fn () => $lifecycle->read(
                $this->user(),
                self::CONTINUATION_ID,
                self::GENERATION_ID,
                $this->now()
            )
        );
        $this->assertSame(['storage.inspect'], $events);
    }

    /**
     * Read failure cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function readFailureProvider(): array
    {
        return [
            'missing or foreign storage identity' => ['storage', 'draft_not_found'],
            'unsupported stored context'          => ['resolver', 'unsupported_context'],
        ];
    }

    /**
     * @testdox  discard remains provider-independent for both acknowledgements
     *
     * @param   string  $status  The storage acknowledgement.
     *
     * @return  void
     *
     * @dataProvider discardStatusProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDiscardNeverUsesResolverOrProvider(string $status): void
    {
        $events                 = [];
        $provider               = new LifecycleTestProvider($events);
        $storage                = new LifecycleTestStorage($events);
        $storage->discardStatus = $status;
        $lifecycle              = $this->lifecycle($provider, $storage, $events, true);
        $now                    = $this->now();

        $this->assertSame(
            ['status' => $status],
            $lifecycle->discard(
                $this->user(),
                self::CONTINUATION_ID,
                self::GENERATION_ID,
                $now
            )
        );
        $this->assertSame(['storage.discard'], $events);
        $this->assertSame(
            [7, self::CONTINUATION_ID, self::GENERATION_ID, $now],
            $storage->calls['discard'][0]
        );
        $this->assertArrayNotHasKey('inspect', $storage->calls);
    }

    /**
     * Discard acknowledgement cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function discardStatusProvider(): array
    {
        return [
            'discarded'  => ['discarded'],
            'idempotent' => ['idempotent'],
        ];
    }

    /**
     * @testdox  discard propagates storage domain and infrastructure failures unchanged
     *
     * @param   \Throwable  $failure  The configured storage failure.
     *
     * @return  void
     *
     * @dataProvider storageFailureProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDiscardStorageFailuresPropagate(\Throwable $failure): void
    {
        $events                  = [];
        $provider                = new LifecycleTestProvider($events);
        $storage                 = new LifecycleTestStorage($events);
        $storage->discardFailure = $failure;
        $lifecycle               = $this->lifecycle($provider, $storage, $events, true);

        $this->assertSame(
            $failure,
            $this->captureFailure(
                fn () => $lifecycle->discard(
                    $this->user(),
                    self::CONTINUATION_ID,
                    self::GENERATION_ID,
                    $this->now()
                )
            )
        );
        $this->assertSame(['storage.discard'], $events);
    }

    /**
     * @testdox  canonical preparation validates provider state and stores only normalized exact data
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPrepareCanonicalActionOrchestratesProviderAndStorage(): void
    {
        $events                   = [];
        $provider                 = new LifecycleTestProvider($events);
        $provider->normalized     = ['title' => 'Normalized'];
        $storage                  = new LifecycleTestStorage($events);
        $storage->canonicalResult = ['operation_id' => 'operation', 'outcome' => 'pending'];
        $lifecycle                = $this->lifecycle($provider, $storage, $events);
        $now                      = $this->now();
        $result                   = $lifecycle->prepareCanonicalAction($this->user(), 'com_example.record', 'client-target', self::CONTINUATION_ID, self::GENERATION_ID, 4, ['title' => 'Client value'], 1, 'apply', 'base-1', $now);
        $this->assertSame($storage->canonicalResult, $result);
        $this->assertSame(['title' => 'Client value'], $provider->normalizationArguments[0]);
        $this->assertSame([
                'resolve',
                'canonicalizeTargetId',
                'targetExists',
                'authorize:prepare-canonical-action',
                'getBaseRevision',
                'getPayloadSchemaVersion',
                'normalizePayload',
                'storage.prepareCanonicalAction',
            ], $events);
        $this->assertSame([
                7,
                self::CONTINUATION_ID,
                self::GENERATION_ID,
                'com_example.record',
                'target-42',
                'base-1',
                4,
                ['title' => 'Normalized'],
                1,
                'apply',
                $now,
            ], $storage->calls['prepareCanonicalAction'][0]);
    }

    /**
     * @testdox  canonical preparation failures before storage cannot close a generation
     *
     * @param   string  $failurePoint  Provider validation point.
     * @param   string  $errorCode     Expected stable failure.
     *
     * @dataProvider canonicalPreparationFailureProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPrepareCanonicalActionProviderFailureStopsStorage(string $failurePoint, string $errorCode): void
    {
        $events   = [];
        $provider = new LifecycleTestProvider($events);
        $storage  = new LifecycleTestStorage($events);
        if ($failurePoint === 'target') {
            $provider->exists = false;
        } elseif ($failurePoint === 'authorize') {
            $provider->authorizeFailure = new AutosaveException($errorCode, 'Denied.');
        } elseif ($failurePoint === 'base') {
            $provider->baseRevision = 'base-2';
        } elseif ($failurePoint === 'schema') {
            $provider->schemaVersion = 2;
        } else {
            $provider->normalizeFailure = new AutosaveException($errorCode, 'Invalid payload.');
        }

        $lifecycle = $this->lifecycle($provider, $storage, $events);
        $this->assertAutosaveFailure($errorCode, fn () => $lifecycle->prepareCanonicalAction($this->user(), 'com_example.record', 'client-target', self::CONTINUATION_ID, self::GENERATION_ID, 4, ['title' => 'Client value'], 1, 'apply', 'base-1', $this->now()));
        $this->assertArrayNotHasKey('prepareCanonicalAction', $storage->calls);
    }

    /**
     * Canonical preparation failures before persistence.
     *
     * @return  array<string, array{string, string}>
     */
    public function canonicalPreparationFailureProvider(): array
    {
        return [
            'missing target'       => ['target', 'target_not_found'],
            'authorization denied' => ['authorize', 'forbidden'],
            'stale base'           => ['base', 'base_revision_conflict'],
            'unsupported schema'   => ['schema', 'unsupported_schema_version'],
            'invalid payload'      => ['payload', 'invalid_payload'],
        ];
    }

    /**
     * @testdox  canonical query, verification and finalization preserve generic provider ownership boundaries
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalOperationLifecycleDelegatesLiteralMetadata(): void
    {
        $events                   = [];
        $provider                 = new LifecycleTestProvider($events);
        $storage                  = new LifecycleTestStorage($events);
        $storage->canonicalResult = ['operation_id' => 'operation', 'outcome' => 'pending'];
        $lifecycle                = $this->lifecycle($provider, $storage, $events);
        $user                     = $this->user();
        $now                      = $this->now();
        $this->assertSame($storage->canonicalResult, $lifecycle->getCanonicalActionOutcome($user, 'operation', 'com_example.record', 'client-target', $now));
        $this->assertSame($storage->canonicalResult, $lifecycle->verifyCanonicalAction($user, 'operation', 'com_example.record', 'client-target', 'apply', $now));
        $this->assertSame($storage->canonicalResult, $lifecycle->finalizeCanonicalActionSuccess($user, 'operation', 'com_example.record', 'client-target', 'apply', 'final-target', $now));
        $this->assertSame($storage->canonicalResult, $lifecycle->finalizeCanonicalActionFailure($user, 'operation', 'com_example.record', 'client-target', 'apply', 'canonical_save_failed', $now));
        $this->assertSame([7, 'operation', 'com_example.record', 'target-42', $now], $storage->calls['inspectCanonicalAction'][0]);
        $this->assertSame([7, 'operation', 'com_example.record', 'target-42', 'apply', 'base-1', $now], $storage->calls['verifyCanonicalAction'][0]);
        $this->assertSame('target-42', $storage->calls['finalizeCanonicalActionSuccess'][0][5]);
        $this->assertSame('base-1', $storage->calls['finalizeCanonicalActionSuccess'][0][6]);
        $this->assertSame('canonical_save_failed', $storage->calls['finalizeCanonicalActionFailure'][0][5]);
        $this->assertContains('authorize:query-canonical-action', $events);
        $this->assertContains('authorize:prepare-canonical-action', $events);
    }
    /**
     * @testdox  all operations reject invalid owner and time before resolver or storage access
     *
     * @param   string  $operation  The lifecycle operation.
     * @param   User    $user       The supplied server-side user.
     * @param   Date    $now        The supplied operation time.
     *
     * @return  void
     *
     * @dataProvider invalidInvocationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInvalidInvocationStopsAllDependencies(string $operation, User $user, Date $now): void
    {
        $events    = [];
        $provider  = new LifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $lifecycle = $this->lifecycle($provider, $storage, $events, true);

        $exception = $this->captureFailure(
            static fn () => match ($operation) {
                'initialize' => $lifecycle->initialize($user, 'com_example.record', 'target', 'key', $now),
                'preserve'   => $lifecycle->preserve(
                    $user,
                    self::CONTINUATION_ID,
                    self::GENERATION_ID,
                    1,
                    [],
                    1,
                    $now
                ),
                'detect'                         => $lifecycle->detect($user, 'com_example.record', 'target', $now),
                'read'                           => $lifecycle->read($user, self::CONTINUATION_ID, self::GENERATION_ID, $now),
                'discard'                        => $lifecycle->discard($user, self::CONTINUATION_ID, self::GENERATION_ID, $now),
                'prepareCanonicalAction'         => $lifecycle->prepareCanonicalAction($user, 'com_example.record', 'target', self::CONTINUATION_ID, self::GENERATION_ID, 1, [], 1, 'apply', 'base-1', $now),
                'getCanonicalActionOutcome'      => $lifecycle->getCanonicalActionOutcome($user, 'operation', 'com_example.record', 'target', $now),
                'verifyCanonicalAction'          => $lifecycle->verifyCanonicalAction($user, 'operation', 'com_example.record', 'target', 'apply', $now),
                'finalizeCanonicalActionSuccess' => $lifecycle->finalizeCanonicalActionSuccess($user, 'operation', 'com_example.record', 'target', 'apply', 'target', $now),
                'finalizeCanonicalActionFailure' => $lifecycle->finalizeCanonicalActionFailure($user, 'operation', 'com_example.record', 'target', 'apply', 'canonical_save_failed', $now),
            }
        );

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertSame([], $events);
    }

    /**
     * Invalid common invocation cases for every operation.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidInvocationProvider(): array
    {
        $anonymous = $this->user(0);
        $localTime = new Date('2026-07-30 10:00:00', 'Asia/Kolkata');
        $cases     = [];

        foreach (
            [
                'initialize',
                'preserve',
                'detect',
                'read',
                'discard',
                'prepareCanonicalAction',
                'getCanonicalActionOutcome',
                'verifyCanonicalAction',
                'finalizeCanonicalActionSuccess',
                'finalizeCanonicalActionFailure',
            ] as $operation
        ) {
            $cases[$operation . ' anonymous'] = [$operation, $anonymous, $this->now()];
            $cases[$operation . ' non-UTC']   = [$operation, $this->user(), $localTime];
        }

        return $cases;
    }

    /**
     * @testdox  discard remains absent from provider authorization operations
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDiscardIsNotAProviderOperation(): void
    {
        $this->assertSame(
            [
                'initialize',
                'preserve',
                'detect',
                'read',
                'prepare-canonical-action',
                'query-canonical-action',
            ],
            array_column(AutosaveOperation::cases(), 'value')
        );
    }

    /**
     * Create a lifecycle wired through the real exact-context resolver.
     */
    private function lifecycle(
        LifecycleTestProvider $provider,
        LifecycleTestStorage $storage,
        array &$events,
        bool $failIfResolved = false
    ): AutosaveLifecycle {
        $component = new ResolverTestCapableComponent(['com_example.record' => true]);
        $component->setAutosaveProvider('com_example.record', $provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->method('bootComponent')->willReturnCallback(
            static function (string $componentName) use ($component, &$events, $failIfResolved) {
                $events[] = 'resolve';

                if ($failIfResolved) {
                    throw new \RuntimeException('The resolver must not be invoked.');
                }

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

        return new AutosaveLifecycle($resolver, $storage);
    }

    /**
     * Return a complete inspected generation.
     *
     * @return  array
     */
    private function inspectedGeneration(string $baseRevision = 'base-1'): array
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

    /**
     * Return complete detection metadata.
     *
     * @return  array
     */
    private function detectedGeneration(string $baseRevision): array
    {
        return [
            'continuation_id'        => self::CONTINUATION_ID,
            'generation_id'          => self::GENERATION_ID,
            'base_revision'          => $baseRevision,
            'client_revision'        => 3,
            'payload_schema_version' => 1,
            'updated_at'             => '2026-07-30 10:00:10',
            'expires_at'             => '2026-07-30 10:01:10',
        ];
    }

    /**
     * Return a user with the requested identity.
     */
    private function user(int $id = 7): User
    {
        $user     = new User();
        $user->id = $id;

        return $user;
    }

    /**
     * Return the common UTC operation time.
     */
    private function now(): Date
    {
        return new Date('2026-07-30 10:00:20', 'UTC');
    }

    /**
     * Capture an expected failure for identity comparison.
     */
    private function captureFailure(callable $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            return $exception;
        }

        $this->fail('The expected failure was not raised.');
    }

    /**
     * Assert a stable Autosave failure identifier.
     */
    private function assertAutosaveFailure(string $errorCode, callable $operation): void
    {
        $exception = $this->captureFailure($operation);

        $this->assertInstanceOf(AutosaveException::class, $exception);
        $this->assertSame($errorCode, $exception->getErrorCode());
    }
}
