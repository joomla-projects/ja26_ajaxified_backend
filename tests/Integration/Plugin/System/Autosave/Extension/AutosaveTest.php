<?php

/**
 * @package     Joomla.IntegrationTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Plugin\System\Autosave\Extension;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Plugin\System\Autosave\Extension\Autosave;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Integration\IntegrationTestCase;
use Joomla\Tests\Integration\Libraries\Cms\Autosave\DraftDatabaseFixture;
use Joomla\Tests\Unit\Component\Autosave\Administrator\Stub\AutosaveTestInput;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent;
use Psr\Log\LoggerInterface;

/** Actual administrator com_ajax dispatch, native response and real draft store. */
class AutosaveTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use DraftDatabaseFixture;

    private DatabaseInterface $db;
    private mixed $previousApplication;
    private mixed $previousPlugins;
    private DraftProviderInterface $provider;
    private DraftService $service;
    private array $descriptor;
    private string $draftId;
    private bool $closed;
    private bool $resolved;

    // PluginHelper caches imports by dispatcher identity; keep identities unique for this process.
    private static array $dispatchers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Factory::$application;
        $plugins                   = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $this->previousPlugins     = $plugins->getValue();
        $plugins->setValue(null, [(object) ['type' => 'system', 'name' => 'autosave', 'params' => '{}', 'id' => 1]]);
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $this->now        = new Date('now', 'UTC');
        $this->descriptor = [
            'context'  => 'com_example.record', 'target' => '42', 'scope' => [], 'version' => 1,
            'revision' => 'canonical', 'fields' => ['title' => ['control' => 'text']],
            'values'   => ['title' => 'Canonical'], 'excluded' => [],
        ];
        $this->draftId  = $this->store->open(7, $this->descriptor, $this->now)['draft_id'];
        $this->provider = $this->createMock(DraftProviderInterface::class);
        $this->provider->method('getContext')->willReturn('com_example.record');
        $component = new ResolverTestCapableComponent(['com_example.record' => true]);
        $component->setAutosaveProvider('com_example.record', $this->provider);
        $application = $this->createMock(CMSApplicationInterface::class);
        $application->method('bootComponent')->willReturn($component);
        $this->service = new DraftService(new AutosaveContextResolver($application, static fn (string $name): bool => $name === 'com_example'), $this->store);
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        (new \ReflectionProperty(PluginHelper::class, 'plugins'))->setValue(null, $this->previousPlugins);
        parent::tearDown();
    }

    /** @dataProvider transportFailures */
    public function testInvalidTransportCannotResolveService(array $options, string $code, int $status): void
    {
        $this->provider->expects($this->never())->method('read');
        [$body, $headers] = $this->request('draft.detect', ['draft_id' => $this->draftId], $options);
        $this->assertFalse($body['success']);
        $this->assertSame($code, $body['data']['code']);
        $this->assertSame($status, $headers['status']);
        $this->assertFalse($this->resolved);
        $this->assertFalse($this->closed);
        $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts WHERE payload IS NOT NULL')->loadResult());
    }

    public static function transportFailures(): array
    {
        return [
            'guest through native attribute' => [['guest' => true, 'owner' => 0], 'authentication_required', 401],
            'new session'                    => [['newSession' => true], 'authentication_required', 401],
            'backend denied'                 => [['backend' => false], 'backend_access_denied', 403],
            'frontend'                       => [['client' => 0], 'backend_access_denied', 403],
            'method'                         => [['method' => 'GET'], 'method_not_allowed', 405],
            'csrf missing'                   => [['token' => ''], 'invalid_csrf_token', 403],
            'csrf invalid'                   => [['validToken' => false], 'invalid_csrf_token', 403],
            'task'                           => [['task' => 'display'], 'unsupported_operation', 400],
            'removed resume'                 => [['task' => 'draft.resume'], 'unsupported_operation', 400],
            'old initialize'                 => [['task' => 'autosave.initialize'], 'unsupported_operation', 400],
            'native save'                    => [['task' => 'article.save'], 'unsupported_operation', 400],
            'media type'                     => [['contentType' => 'text/plain'], 'unsupported_media_type', 415],
            'invalid parameter'              => [['contentType' => 'application/json; invalid'], 'unsupported_media_type', 415],
            'declared size'                  => [['length' => '5242881'], 'request_too_large', 413],
            'actual size'                    => [['raw' => str_repeat(' ', 5242881), 'length' => '1'], 'request_too_large', 413],
            'malformed'                      => [['raw' => '{"draft_id":'], 'malformed_json', 400],
            'depth'                          => [['raw' => str_repeat('{"nested":', 33) . '0' . str_repeat('}', 33)], 'malformed_json', 400],
            'list root'                      => [['raw' => '[]'], 'invalid_request', 400],
            'scalar root'                    => [['raw' => 'true'], 'invalid_request', 400],
        ];
    }

    public function testFourOperationsUseOneNativeEnvelopeAndReleaseSessionBeforeProviderWork(): void
    {
        $this->provider->method('read')->willReturnCallback(function (): array {
            $this->assertTrue($this->closed);
            return $this->descriptor;
        });
        [$detect] = $this->request('draft.detect', ['draft_id' => $this->draftId]);
        $this->assertTrue($detect['success']);
        $this->assertSame(['candidates' => [], 'conflict' => false], $detect['data']);
        [$obsolete, $headers] = $this->request('draft.detect', ['draft_id' => $this->draftId, 'cursor' => str_repeat('a', 32)]);
        $this->assertFalse($obsolete['success']);
        $this->assertSame('invalid_request', $obsolete['data']['code']);
        $this->assertSame(400, $headers['status']);
        $source  = $this->store->open(7, $this->descriptor, $this->now)['draft_id'];
        [$saved] = $this->request('draft.preserve', ['draft_id' => $source, 'client_revision' => 1, 'schema_version' => 1, 'payload' => ['title' => 'Private selected value']]);
        $this->assertTrue($saved['success']);
        $this->assertSame(1, $saved['data']['client_revision']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $saved['data']['updated_at']);
        [$read] = $this->request('draft.read', ['draft_id' => $this->draftId, 'source_id' => $source]);
        $this->assertTrue($read['success']);
        $this->assertSame(['title' => 'Private selected value'], $read['data']['payload']);
        [$discard] = $this->request('draft.discard', ['draft_id' => $source]);
        $this->assertSame(['discarded' => true], $discard['data']);
        $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts WHERE draft_id = ' . $this->db->quote($source))->loadResult());
    }

    /** @dataProvider missingBindings */
    public function testForeignMissingAndExpiredIdsCannotReachProvider(string $kind, string $code, int $status): void
    {
        $id = match ($kind) {
            'foreign' => $this->store->open(8, $this->descriptor, $this->now)['draft_id'],
            'expired' => $this->store->open(7, $this->descriptor, new Date('-2 days', 'UTC'))['draft_id'],
            default   => str_repeat('a', 32),
        };
        $this->provider->expects($this->never())->method('read');
        [$body, $headers] = $this->request('draft.detect', ['draft_id' => $id]);
        $this->assertFalse($body['success']);
        $this->assertSame($code, $body['data']['code']);
        $this->assertSame($status, $headers['status']);
    }

    public static function missingBindings(): array
    {
        return [['foreign', 'draft_not_found', 404], ['missing', 'draft_not_found', 404], ['expired', 'draft_expired', 410]];
    }

    public function testCurrentProviderAuthorizationIsNotBypassed(): void
    {
        $this->provider->method('read')->willThrowException(new AutosaveException('forbidden', 'Draft access is denied.'));
        [$body, $headers] = $this->request('draft.preserve', ['draft_id' => $this->draftId, 'client_revision' => 1, 'schema_version' => 1, 'payload' => ['title' => 'Denied']]);
        $this->assertFalse($body['success']);
        $this->assertSame('forbidden', $body['data']['code']);
        $this->assertSame(403, $headers['status']);
        $this->assertNull($this->store->read(7, $this->draftId, $this->now)['payload']);
    }

    /** @dataProvider loggerFailures */
    public function testUnexpectedFailuresExposeNeitherPayloadNorInternalMessages(bool $loggerFails): void
    {
        $this->provider->method('read')->willThrowException(new \RuntimeException('Private SQL and credentials'));
        $logger   = $this->createMock(LoggerInterface::class);
        $expected = $logger->expects($this->once())->method('error')->with(
            'An unexpected Autosave request failure occurred.',
            ['exception_class' => \RuntimeException::class]
        );
        if ($loggerFails) {
            $expected->willThrowException(new \RuntimeException('Private logger failure'));
        }
        [$body, $headers] = $this->request('draft.detect', ['draft_id' => $this->draftId], ['logger' => $logger]);
        $this->assertSame(500, $headers['status']);
        $this->assertSame('internal_error', $body['data']['code']);
        $this->assertSame('The Autosave request could not be completed.', $body['message']);
        $this->assertStringNotContainsString('Private', json_encode($body));
    }

    public static function loggerFailures(): array
    {
        return [[false], [true]];
    }

    private function request(string $task, array $payload, array $options = []): array
    {
        $input       = new AutosaveTestInput($options['task'] ?? $task, $options['raw'] ?? json_encode($payload), $options['method'] ?? 'POST', $options['contentType'] ?? 'Application/JSON; charset=utf-8', $options['token'] ?? 'token', $options['length'] ?? null);
        $user        = $this->createMock(User::class);
        $user->id    = $options['owner'] ?? 7;
        $user->guest = $options['guest'] ?? false;
        $user->method('authorise')->with('core.login.admin')->willReturn($options['backend'] ?? true);
        $this->closed   = false;
        $this->resolved = false;
        $session        = $this->createMock(SessionInterface::class);
        $session->method('isNew')->willReturn($options['newSession'] ?? false);
        $session->method('close')->willReturnCallback(function (): void {
            $this->closed = true;
        });
        $headers = [];
        $app     = $this->createMock(CMSApplication::class);
        $app->method('getName')->willReturn(($options['client'] ?? 1) === 1 ? 'administrator' : 'site');
        $app->method('isClient')->with('administrator')->willReturn(($options['client'] ?? 1) === 1);
        $app->method('getClientId')->willReturn($options['client'] ?? 1);
        $app->method('getInput')->willReturn($input);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getSession')->willReturn($session);
        $app->method('checkToken')->with('post')->willReturn($options['validToken'] ?? true);
        $app->method('setHeader')->willReturnCallback(static function (string $name, mixed $value) use (&$headers): void {
            $headers[$name] = $value;
        });
        $app->expects($this->never())->method('close');
        $app->expects($this->never())->method('sendHeaders');
        $app->expects($this->never())->method('getMessageQueue');
        if (isset($options['logger'])) {
            $app->method('getLogger')->willReturn($options['logger']);
        }
        $dispatcher          = new Dispatcher();
        self::$dispatchers[] = $dispatcher;
        $app->method('getDispatcher')->willReturn($dispatcher);
        $plugin = new Autosave([], function (): DraftService {
            $this->assertTrue($this->closed);
            $this->resolved = true;
            return $this->service;
        });
        $plugin->setApplication($app);
        $app->method('bootPlugin')->with('autosave', 'system')->willReturn($plugin);
        Factory::$application = $app;
        ob_start();
        try {
            // The administrator wrapper requires this unchanged core route once per HTTP request.
            // Include its target directly so repeated requests execute within this test process.
            require JPATH_SITE . '/components/com_ajax/ajax.php';
            $raw = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(['success', 'message', 'messages', 'data'], array_keys($body));
        $this->assertNull($body['messages']);
        $this->assertSame('no-store', $headers['Cache-Control']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        return [$body, $headers];
    }
}
