<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Autosave\Administrator\Dispatcher;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Date\Date;
use Joomla\CMS\User\User;
use Joomla\Component\Autosave\Administrator\Dispatcher\Dispatcher;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Unit\Component\Autosave\Administrator\Stub\AutosaveTestInput;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\DraftDatabaseFixture;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Joomla\Tests\Unit\UnitTestCase;

// phpcs:disable PSR1.Files.SideEffects
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Dispatcher/Dispatcher.php';
// phpcs:enable PSR1.Files.SideEffects

/**
 * Tests the protected JSON dispatcher boundary.
 *
 * @since  __DEPLOY_VERSION__
 */
class DispatcherTest extends UnitTestCase
{
    use DraftDatabaseFixture;

    private DatabaseInterface $db;

    /**
     * @dataProvider transportFailureProvider
     */
    public function testTransportFailuresStopDraftAccess(
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
        $provider                = null;
        [$dispatcher, $headers]  = $this->dispatcher(
            $userId,
            $guest,
            $backendAccess,
            $task,
            $body,
            $method,
            $contentType,
            $token,
            $contentLength,
            $provider
        );
        $provider->expects($this->never())->method('read');
        ob_start();
        $dispatcher->dispatch();
        $decoded = json_decode(ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame($expectedCode, $decoded['error']['code']);
        $this->assertSame($expectedStatus, $headers()['status']);
        $this->assertSame('no-store', $headers()['Cache-Control']);
        $this->assertSame('nosniff', $headers()['X-Content-Type-Options']);
        $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts WHERE payload IS NOT NULL')->loadResult());
    }

    public function transportFailureProvider(): array
    {
        $detect = '{"draft_id":"TEST_DRAFT_ID"}';

        return [
            'guest'          => [0, true, false, 'draft.detect', $detect, 'POST', 'application/json', 'token', null, 'authentication_required', 401],
            'backend denied' => [7, false, false, 'draft.detect', $detect, 'POST', 'application/json', 'token', null, 'backend_access_denied', 403],
            'method'         => [7, false, true, 'draft.detect', $detect, 'GET', 'application/json', 'token', null, 'method_not_allowed', 405],
            'csrf missing'   => [7, false, true, 'draft.detect', $detect, 'POST', 'application/json', '', null, 'invalid_csrf_token', 403],
            'task'           => [7, false, true, 'display', $detect, 'POST', 'application/json', 'token', null, 'unsupported_operation', 400],
            'media type'     => [7, false, true, 'draft.detect', $detect, 'POST', 'text/plain', 'token', null, 'unsupported_media_type', 415],
            'declared size'  => [7, false, true, 'draft.detect', $detect, 'POST', 'application/json', 'token', '5242881', 'request_too_large', 413],
            'malformed'      => [7, false, true, 'draft.detect', '{"context":', 'POST', 'application/json', 'token', null, 'malformed_json', 400],
            'list root'      => [7, false, true, 'draft.detect', '[]', 'POST', 'application/json', 'token', null, 'invalid_request', 400],
            'old initialize' => [7, false, true, 'autosave.initialize', $detect, 'POST', 'application/json', 'token', null, 'unsupported_operation', 400],
            'old preserve'   => [7, false, true, 'autosave.preserve', $detect, 'POST', 'application/json', 'token', null, 'unsupported_operation', 400],
            'old prepare'    => [7, false, true, 'autosave.prepareCanonicalAction', $detect, 'POST', 'application/json', 'token', null, 'unsupported_operation', 400],
            'old outcome'    => [7, false, true, 'autosave.getCanonicalActionOutcome', $detect, 'POST', 'application/json', 'token', null, 'unsupported_operation', 400],
        ];
    }

    public function testDetectUsesDraftStoreAndReleasesSession(): void
    {
        $closed                  = false;
        $provider                = null;
        [$dispatcher, $headers]  = $this->dispatcher(
            7,
            false,
            true,
            'draft.detect',
            '{"draft_id":"TEST_DRAFT_ID"}',
            'POST',
            'Application/JSON; charset=utf-8',
            'token',
            null,
            $provider,
            $closed
        );
        ob_start();
        $dispatcher->dispatch();
        $decoded = json_decode(ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['success']);
        $this->assertSame([], $decoded['data']['candidates']);
        $this->assertFalse($decoded['data']['conflict']);
        $this->assertNull($decoded['data']['next']);
        $this->assertSame(200, $headers()['status']);
        $this->assertTrue($closed);
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
        ?DraftProviderInterface &$provider,
        bool &$sessionClosed = false
    ): array {
        $this->db = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'dispatcher_draft_']);
        $this->installSchema('postgresql');
        $this->now  = new Date('now', 'UTC');
        $descriptor = [
            'context'  => 'com_example.record', 'target' => '42', 'scope' => [], 'version' => 1,
            'revision' => 'canonical', 'fields' => ['title' => ['control' => 'text']], 'values' => ['title' => 'Canonical'], 'excluded' => [],
        ];
        $draft    = $this->store->open(7, $descriptor, $this->now);
        $body     = str_replace('TEST_DRAFT_ID', $draft['draft_id'], $body);
        $provider = $this->createMock(DraftProviderInterface::class);
        $provider->method('getContext')->willReturn('com_example.record');
        $provider->method('read')->willReturn($descriptor);
        $component = new ResolverTestCapableComponent(['com_example.record' => true]);
        $component->setAutosaveProvider('com_example.record', $provider);
        $resolverApp = $this->createMock(CMSApplicationInterface::class);
        $resolverApp->method('bootComponent')->willReturn($component);
        $service = new DraftService(
            new AutosaveContextResolver($resolverApp, static fn (string $name): bool => $name === 'com_example'),
            $this->store
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
            new Dispatcher($app, $input, $service),
            static function () use (&$headers): array {
                return $headers;
            },
        ];
    }
}
