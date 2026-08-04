<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave\Dispatcher;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\User\User;
use Joomla\Component\Autosave\Administrator\Dispatcher\Dispatcher;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Unit\Administrator\Components\Autosave\Stub\AutosaveTestInput;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestProvider;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\LifecycleTestStorage;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Joomla\Tests\Unit\UnitTestCase;

// phpcs:disable PSR1.Files.SideEffects
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Controller/AutosaveController.php';
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Dispatcher/Dispatcher.php';
// phpcs:enable PSR1.Files.SideEffects

/**
 * Tests the protected JSON dispatcher boundary.
 *
 * @since  __DEPLOY_VERSION__
 */
class DispatcherTest extends UnitTestCase
{
    /**
     * @dataProvider transportFailureProvider
     */
    public function testTransportFailuresStopLifecycle(
        int $userId,
        bool $guest,
        bool $backendAccess,
        string $task,
        string $body,
        string $method,
        string $contentType,
        string $token,
        ?string $contentLength,
        string $expectedCode,
        int $expectedStatus
    ): void {
        $storage                = null;
        [$dispatcher, $headers] = $this->dispatcher(
            $userId,
            $guest,
            $backendAccess,
            $task,
            $body,
            $method,
            $contentType,
            $token,
            $contentLength,
            $storage
        );
        ob_start();
        $dispatcher->dispatch();
        $decoded = json_decode(ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame($expectedCode, $decoded['error']['code']);
        $this->assertSame($expectedStatus, $headers()['status']);
        $this->assertSame('no-store', $headers()['Cache-Control']);
        $this->assertSame('nosniff', $headers()['X-Content-Type-Options']);
        $this->assertSame([], $storage->calls);
    }

    public function transportFailureProvider(): array
    {
        $detect = '{"context":"com_example.record","target_id":"42"}';

        return [
            'guest'          => [0, true, false, 'autosave.detect', $detect, 'POST', 'application/json', 'token', null, 'authentication_required', 401],
            'backend denied' => [7, false, false, 'autosave.detect', $detect, 'POST', 'application/json', 'token', null, 'backend_access_denied', 403],
            'method'         => [7, false, true, 'autosave.detect', $detect, 'GET', 'application/json', 'token', null, 'method_not_allowed', 405],
            'csrf missing'   => [7, false, true, 'autosave.detect', $detect, 'POST', 'application/json', '', null, 'invalid_csrf_token', 403],
            'task'           => [7, false, true, 'display', $detect, 'POST', 'application/json', 'token', null, 'unsupported_operation', 400],
            'media type'     => [7, false, true, 'autosave.detect', $detect, 'POST', 'text/plain', 'token', null, 'unsupported_media_type', 415],
            'declared size'  => [7, false, true, 'autosave.detect', $detect, 'POST', 'application/json', 'token', '5242881', 'request_too_large', 413],
            'malformed'      => [7, false, true, 'autosave.detect', '{"context":', 'POST', 'application/json', 'token', null, 'malformed_json', 400],
            'list root'      => [7, false, true, 'autosave.detect', '[]', 'POST', 'application/json', 'token', null, 'invalid_request', 400],
        ];
    }

    public function testDetectNullIsSuccessfulAndReleasesSession(): void
    {
        $closed                 = false;
        $storage                = null;
        [$dispatcher, $headers] = $this->dispatcher(
            7,
            false,
            true,
            'autosave.detect',
            '{"context":"com_example.record","target_id":"42"}',
            'POST',
            'Application/JSON; charset=utf-8',
            'token',
            null,
            $storage,
            $closed
        );
        ob_start();
        $dispatcher->dispatch();
        $decoded = json_decode(ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['success']);
        $this->assertNull($decoded['data']);
        $this->assertSame(200, $headers()['status']);
        $this->assertTrue($closed);
        $this->assertArrayHasKey('detect', $storage->calls);
    }

    private function dispatcher(
        int $userId,
        bool $guest,
        bool $backendAccess,
        string $task,
        string $body,
        string $method,
        string $contentType,
        string $token,
        ?string $contentLength,
        ?LifecycleTestStorage &$storage,
        bool &$sessionClosed = false
    ): array {
        $events    = [];
        $provider  = new LifecycleTestProvider($events);
        $storage   = new LifecycleTestStorage($events);
        $component = new ResolverTestCapableComponent(['com_example.record' => true]);
        $component->setAutosaveProvider('com_example.record', $provider);
        $resolverApp = $this->createMock(CMSApplicationInterface::class);
        $resolverApp->method('bootComponent')->willReturn($component);
        $lifecycle = new AutosaveLifecycle(
            new AutosaveContextResolver($resolverApp, static fn (string $name): bool => $name === 'com_example'),
            $storage
        );
        $input       = new AutosaveTestInput($task, $body, $method, $contentType, $token, $contentLength);
        $user        = $this->createMock(User::class);
        $user->id    = $userId;
        $user->guest = $guest ? 1 : 0;
        $user->method('authorise')->with('core.login.admin')->willReturn($backendAccess);

        $headers      = [];
        $session      = $this->createMock(SessionInterface::class);
        $session->method('isNew')->willReturn(false);
        $session->method('close')->willReturnCallback(
            static function () use (&$sessionClosed): void {
                $sessionClosed = true;
            }
        );
        $app = $this->getMockBuilder(CMSApplication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doExecute', 'getIdentity', 'checkToken', 'getSession', 'setHeader', 'sendHeaders', 'close'])
            ->getMock();
        $app->method('getIdentity')->willReturn($user);
        $app->method('checkToken')->willReturn(true);
        $app->method('getSession')->willReturn($session);
        $app->method('setHeader')->willReturnCallback(
            static function (string $name, mixed $value) use (&$headers): void {
                $headers[$name] = $value;
            }
        );

        return [
            new Dispatcher($app, $input, $lifecycle),
            static function () use (&$headers): array {
                return $headers;
            },
        ];
    }
}
