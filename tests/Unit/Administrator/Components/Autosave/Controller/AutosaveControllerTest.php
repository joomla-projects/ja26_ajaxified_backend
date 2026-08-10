<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\Date\Date;
use Joomla\CMS\User\User;
use Joomla\Component\Autosave\Administrator\Controller\AutosaveController;
use Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestProvider;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestStorage;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Joomla\Tests\Unit\UnitTestCase;

// phpcs:disable PSR1.Files.SideEffects
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Controller/AutosaveController.php';
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Extension/AutosaveComponent.php';
// phpcs:enable PSR1.Files.SideEffects

/**
 * Tests strict operation envelopes at the lifecycle façade boundary.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveControllerTest extends UnitTestCase
{
    public function testDeploymentStoragePolicyIsExplicitAndFrozen(): void
    {
        $this->assertSame(
            [
                'idle_ttl'               => 604800,
                'max_lifetime'           => 2592000,
                'tombstone_retention'    => 86400,
                'max_active_generations' => 20,
                'max_payload_bytes'      => 4194304,
            ],
            AutosaveComponent::STORAGE_POLICY
        );
    }

    public function testInitializeUsesOnlyExactServerBoundArguments(): void
    {
        [$controller, $storage] = $this->controller();
        $result                 = $controller->execute(
            'initialize',
            ['context' => 'com_example.record', 'target_id' => '42', 'initialization_key' => 'request-1'],
            $this->user(),
            $this->now()
        );

        $this->assertSame('com_example.record', $result['context']);
        $this->assertSame('target-42', $result['target_id']);
        $this->assertSame('request-1', $storage->calls['initialize'][0][4]);
    }

    public function testPreservePassesPayloadWithoutTransportRewriting(): void
    {
        [$controller, $storage, $provider] = $this->controller();
        $storage->inspectResult            = $this->generation();
        $provider->normalized              = ['articletext' => '<p>& draft</p>'];
        $payload                           = ['articletext' => '<p>& draft</p>'];

        $result = $controller->execute(
            'preserve',
            [
                'continuation_id'        => str_repeat('a', 64),
                'generation_id'          => str_repeat('b', 64),
                'client_revision'        => 1,
                'payload_schema_version' => 1,
                'payload'                => $payload,
            ],
            $this->user(),
            $this->now()
        );

        $this->assertSame(['status' => 'accepted'], $result);
        $this->assertSame([$payload, 1], $provider->normalizationArguments);
        $this->assertSame($provider->normalized, $storage->calls['preserve'][0][7]);
    }

    public function testPrepareCanonicalActionUsesExactValidatedSnapshotAndBinding(): void
    {
        [$controller, $storage, $provider] = $this->controller();
        $storage->canonicalResult = [
            'operation_id' => str_repeat('c', 64),
            'intent'       => 'apply',
            'outcome'      => 'pending',
            'expires_at'   => '2026-08-01 10:00:00',
        ];
        $payload = ['articletext' => '<p>submitted</p>'];

        $result = $controller->execute(
            'prepareCanonicalAction',
            [
                'context'                => 'com_example.record',
                'target_id'              => '42',
                'continuation_id'        => str_repeat('a', 64),
                'generation_id'          => str_repeat('b', 64),
                'client_revision'        => 2,
                'payload_schema_version' => 1,
                'payload'                => $payload,
                'intent'                 => 'apply',
                'expected_base_revision' => 'base-1',
            ],
            $this->user(),
            $this->now()
        );

        $this->assertSame($storage->canonicalResult, $result);
        $this->assertSame([$payload, 1], $provider->normalizationArguments);
        $this->assertSame($provider->normalized, $storage->calls['prepareCanonicalAction'][0][7]);
        $this->assertSame('apply', $storage->calls['prepareCanonicalAction'][0][9]);
    }

    public function testCanonicalOutcomeQueryReturnsMetadataOnly(): void
    {
        [$controller, $storage] = $this->controller();
        $storage->canonicalResult = [
            'operation_id' => str_repeat('c', 64),
            'outcome'      => 'pending',
        ];

        $result = $controller->execute(
            'getCanonicalActionOutcome',
            [
                'operation_id' => str_repeat('c', 64),
                'context'      => 'com_example.record',
                'target_id'    => '42',
            ],
            $this->user(),
            $this->now()
        );

        $this->assertSame($storage->canonicalResult, $result);
        $this->assertSame(str_repeat('c', 64), $storage->calls['inspectCanonicalAction'][0][1]);
        $this->assertArrayNotHasKey('payload', $result);
    }

    /**
     * @dataProvider invalidEnvelopeProvider
     */
    public function testInvalidEnvelopeNeverReachesStorage(string $operation, array $request): void
    {
        [$controller, $storage] = $this->controller();

        try {
            $controller->execute($operation, $request, $this->user(), $this->now());
            $this->fail('Invalid envelope accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame([], $storage->calls);
        }
    }

    public function invalidEnvelopeProvider(): array
    {
        return [
            'unknown operation'      => ['display', []],
            'initialize missing key' => ['initialize', ['context' => 'com_example.record', 'target_id' => '42']],
            'initialize extra key'   => [
                'initialize',
                ['context' => 'com_example.record', 'target_id' => '42', 'initialization_key' => 'k', 'user_id' => 7],
            ],
            'preserve client context' => [
                'preserve',
                [
                    'continuation_id'        => 'a',
                    'generation_id'          => 'b',
                    'client_revision'        => 1,
                    'payload_schema_version' => 1,
                    'payload'                => [],
                    'context'                => 'com_content.article',
                ],
            ],
            'preserve zero revision' => [
                'preserve',
                [
                    'continuation_id'        => 'a',
                    'generation_id'          => 'b',
                    'client_revision'        => 0,
                    'payload_schema_version' => 1,
                    'payload'                => [],
                ],
            ],
            'read client binding' => [
                'read',
                ['continuation_id' => 'a', 'generation_id' => 'b', 'target_id' => '42'],
            ],
            'prepare missing intent' => [
                'prepareCanonicalAction',
                [
                    'context'                => 'com_example.record',
                    'target_id'              => '42',
                    'continuation_id'        => 'a',
                    'generation_id'          => 'b',
                    'client_revision'        => 1,
                    'payload_schema_version' => 1,
                    'payload'                => [],
                    'expected_base_revision' => 'base-1',
                ],
            ],
            'outcome extra payload' => [
                'getCanonicalActionOutcome',
                [
                    'operation_id' => 'c',
                    'context'      => 'com_example.record',
                    'target_id'    => '42',
                    'payload'      => [],
                ],
            ],
        ];
    }

    private function controller(): array
    {
        $events    = [];
        $provider  = new LifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $component = new ResolverTestCapableComponent(['com_example.record' => true]);
        $component->setAutosaveProvider('com_example.record', $provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->method('bootComponent')->willReturn($component);
        $resolver = new AutosaveContextResolver(
            $application,
            static fn (string $name): bool => $name === 'com_example'
        );

        return [new AutosaveController(new AutosaveLifecycle($resolver, $storage)), $storage, $provider];
    }

    private function generation(): array
    {
        return [
            'continuation_id'        => str_repeat('a', 64),
            'generation_id'          => str_repeat('b', 64),
            'context'                => 'com_example.record',
            'target_id'              => 'target-42',
            'base_revision'          => 'base-1',
            'state'                  => 'active',
            'client_revision'        => 0,
            'payload'                => null,
            'payload_schema_version' => null,
            'created_at'             => '2026-07-31 10:00:00',
            'updated_at'             => '2026-07-31 10:00:00',
            'expires_at'             => '2026-08-07 10:00:00',
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
        return new Date('2026-07-31 10:00:00', 'UTC');
    }
}
