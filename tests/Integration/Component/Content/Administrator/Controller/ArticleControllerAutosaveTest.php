<?php

/**
 * @package     Joomla.IntegrationTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Component\Content\Administrator\Controller;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Language;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Router\Router;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Table;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Controller\ArticleController;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Integration\IntegrationTestCase;
use Joomla\Tests\Integration\Libraries\Cms\Autosave\DraftDatabaseFixture;
use Psr\Log\NullLogger;

/**
 * Public native controller tasks and real optional reconciliation; no private trait reflection.
 */
class ArticleControllerAutosaveTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;

    use DraftDatabaseFixture;

    private DatabaseInterface $db;
    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousApplication = Factory::$application;
        $this->db                  = $this->getDBDriver();
        $this->initializeStore();
        // Native success uses the real clock; these bindings must not age out in future CI runs.
        $this->now = new \Joomla\CMS\Date\Date('now', 'UTC');
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    /** @dataProvider nativeOutcomes */
    public function testNativeTaskKeepsItsResultAndOnlySuccessfulBoundMetadataRetires(
        string $task,
        string $outcome,
        ?array $metadata,
        int $source,
        int $result
    ): void {
        $input = new Input(['option' => 'com_content', 'id' => $source]);
        $input->post->set('jform', ['title' => 'Native title', 'alias' => '', 'catid' => '2', 'articletext' => 'Native body']);
        $user        = $this->createMock(User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturn(true);
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'user' ? $user : $default);
        $session->method('getToken')->willReturn('draft-test-token');
        $session->method('isNew')->willReturn(false);
        $app = $this->createMock(CMSApplication::class);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getInput')->willReturn($input);
        $app->method('getSession')->willReturn($session);
        $app->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'secret' ? 'test-secret' : $default);
        $app->method('getName')->willReturn('drafttest');
        $app->method('getLanguage')->willReturn(new Language('en-GB', false));
        $app->method('getLogger')->willReturn(new NullLogger());
        Factory::$application = $app;
        Factory::getContainer()->set('DrafttestRouter', new Router());
        $input->server->set('HTTP_X_CSRF_TOKEN', Session::getFormToken());
        $states = [];
        $app->method('getUserState')->willReturnCallback(static function ($key, $default = null) use (&$states) {
            return $states[$key] ?? $default;
        });
        $app->method('setUserState')->willReturnCallback(static function ($key, $value) use (&$states) {
            $states[$key] = $value;
            return null;
        });

        $descriptor = [
            'context' => 'com_content.article', 'target' => $source === 0 ? null : (string) $source,
            'scope'   => [], 'version' => 1, 'revision' => $source === 0 ? null : 'rendered',
            'fields'  => ['title' => ['control' => 'text']],
        ];
        $draft = $this->store->open(7, $descriptor, $this->now);
        $this->store->preserve(7, $draft['draft_id'], 1, ['title' => 'recoverable'], $this->now, static fn ($row, $payload) => $payload);
        if ($metadata !== null) {
            $metadata['draft_id'] = $metadata['draft_id'] === 'own' ? $draft['draft_id'] : str_repeat('b', 32);
            $input->post->set('autosave', $metadata);
        }
        $resolver  = new AutosaveContextResolver($app, static fn (): bool => true);
        $service   = new DraftService($resolver, $this->store);

        $this->registerDraftService($service, $outcome !== 'unavailable');

        $table = $this->createMock(Table::class);
        $table->method('getKeyName')->willReturn('id');
        $table->method('hasField')->willReturn(\in_array($outcome, ['checkin', 'checkout'], true));
        $model = $this->createMock(\Joomla\Component\Content\Administrator\Model\ArticleModel::class);
        $model->method('getTable')->willReturn($table);
        $model->method('getName')->willReturn('article');
        $model->method('getState')->willReturnCallback(static fn ($key, $default = null) => $key === 'article.id' ? $result : $default);
        $form = new Form('native.article.save', ['control' => 'jform']);
        $form->setCurrentUser($user);
        $sourceXml = simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_content/forms/article.xml');
        $xml       = new \SimpleXMLElement('<form><fieldset/></form>');
        foreach ($sourceXml->xpath('//field[@name="title" or @name="alias" or @name="articletext" or @name="catid" or @name="id"]') as $field) {
            $imported = dom_import_simplexml($field);
            $target   = dom_import_simplexml($xml->fieldset);
            $target->appendChild($target->ownerDocument->importNode($imported, true));
        }
        $form->load($xml);
        $model->method('getForm')->willReturn($form);
        $model->method('validate')->willReturnCallback(static fn ($form, $data) => $outcome === 'validation' ? false : $data);
        $model->method('getErrors')->willReturn([new \RuntimeException('Native validation failure')]);
        $model->expects($outcome === 'validation' ? $this->never() : $this->once())->method('save')
            ->willReturn($outcome !== 'model');
        $model->method('getItem')->willReturn((object) ['id' => $result, 'title' => 'Native title']);
        $model->method('checkin')->willReturn($outcome !== 'checkin');
        $model->method('checkout')->willReturn($outcome !== 'checkout');
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $controller = new ArticleController(
            ['base_path' => JPATH_ADMINISTRATOR . '/components/com_content'],
            $factory,
            $app,
            $input
        );
        $controller->setDispatcher(new Dispatcher());
        $success = $controller->execute($task);
        $this->assertSame(!\in_array($outcome, ['validation', 'model', 'checkin'], true), $success);
        $row     = $this->store->read(7, $draft['draft_id'], $this->now);
        $retires = $result > 0 && \in_array($outcome, ['success', 'checkout'], true) && $metadata !== null && $metadata['draft_id'] === $draft['draft_id']
            && ($metadata['revision'] ?? null) === '1';
        $this->assertSame($retires ? null : ['title' => 'recoverable'], $row['payload']);
        if ($retires && $source === 0) {
            $this->assertSame((string) $result, $row['target_id']);
        }
        if ($task === 'save2menu' && $success) {
            $this->assertSame($result, $states['com_menus.edit.item']['data']['request']['id']);
        }
        if (!$success) {
            $this->assertArrayHasKey('com_content.edit.article.data', $states);
        }
    }

    public static function nativeOutcomes(): array
    {
        $own = ['draft_id' => 'own', 'revision' => '1'];
        return [
            ['apply', 'success', $own, 42, 42],
            ['save', 'success', null, 42, 42],
            ['save', 'success', ['draft_id' => 'own', 'revision' => 'invalid'], 42, 42],
            ['save', 'success', ['draft_id' => 'foreign', 'revision' => '1'], 42, 42],
            ['save', 'unavailable', $own, 42, 42],
            ['apply', 'validation', $own, 42, 42],
            ['apply', 'model', $own, 42, 42],
            ['save', 'checkin', $own, 42, 42],
            ['apply', 'checkout', $own, 42, 42],
            ['apply', 'success', $own, 0, 84],
            ['apply', 'success', $own, 0, 0],
            ['save2new', 'success', $own, 42, 42],
            ['save2copy', 'success', $own, 42, 84],
            ['save2menu', 'success', $own, 42, 42],
            ['editAssociations', 'success', $own, 42, 42],
            ['editAssociations', 'validation', $own, 42, 42],
            ['editAssociations', 'model', $own, 42, 42],
        ];
    }
}
