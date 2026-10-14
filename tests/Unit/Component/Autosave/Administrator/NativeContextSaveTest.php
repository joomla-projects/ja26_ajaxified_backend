<?php

/**
 * @package Joomla.UnitTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Autosave\Administrator;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Router\Router;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Table;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

class NativeContextSaveTest extends UnitTestCase
{
    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;

    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\DraftDatabaseFixture;

    private DatabaseInterface $db;
    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousApplication = Factory::$application;
        $this->db                  = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'context_save_']);
        $this->installSchema('postgresql');
        // Native success uses the real clock; these bindings must not age out in future CI runs.
        $this->now = new \Joomla\CMS\Date\Date('now', 'UTC');
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    /** @dataProvider outcomes */
    public function testRealNativeControllerKeepsCanonicalOutcomeAndRetiresOnlyItsSubmission(string $component, string $entity, string $task, string $outcome): void
    {
        $context    = $component . '.' . strtolower($entity);
        $name       = ucfirst(substr($component, 4));
        $prefix     = 'Joomla\\Component\\' . $name . '\\Administrator\\';
        $key        = $entity === 'Language' ? 'lang_id' : ($entity === 'Filter' ? 'filter_id' : 'id');
        $source     = $task === 'create' ? '' : '42';
        $nativeTask = $task === 'create' ? 'apply' : $task;
        $result     = $source === '' ? '84' : '42';
        $scope      = $component === 'com_menus' && $entity === 'Item' && $source === ''
            ? ['menutype' => '', 'menu_id' => '0', 'client_id' => '0', 'type' => 'component', 'link' => 'index.php?option=com_content&view=article']
            : ['fixture_scope' => 'bound'];
        $resultScope = $component === 'com_menus' && $entity === 'Item' && $source === ''
            ? array_replace($scope, ['menutype' => 'draft-menu', 'menu_id' => '12']) : $scope;
        $scopeChange = str_starts_with($outcome, 'scope-');
        if ($scopeChange) {
            $scope = $entity === 'Language' ? ['lang_code' => 'en-GB'] : ['client_id' => '0', 'menutype' => 'old-menu'];
            if ($entity === 'Item') {
                $scope = ['menutype' => 'old-menu', 'menu_id' => '12', 'client_id' => '0', 'type' => 'component', 'link' => 'index.php?option=com_content&view=article'];
            }
            $resultScope = array_replace($scope, $entity === 'Language' ? ['lang_code' => 'fr-FR'] : ['menutype' => 'draft-menu']);
            if ($entity === 'Item') {
                $resultScope['menu_id'] = '13';
            }
            if ($outcome === 'scope-denied') {
                $resultScope[$entity === 'Language' ? 'foreign_key' : 'client_id'] = '1';
            } elseif ($outcome === 'scope-type') {
                $resultScope['type'] = 'alias';
            }
        }

        $data       = ['id' => (int) $source, 'lang_id' => (int) $source, 'filter_id' => (int) $source,
            'title'         => 'Native title', 'name' => 'Native name', 'created_by' => 7, 'created_user_id' => 7, 'alias' => 'native-alias', 'parent_id' => 1, 'catid' => 3,
            'context'       => 'com_content.article', 'extension' => 'com_content.article', 'workflow_id' => 12, 'tour_id' => 12,
            'menutype'      => 'draft-menu', 'client_id' => 0, 'type' => 'component', 'link' => 'index.php?option=com_content&view=article', 'params' => [], 'rules' => []];
        $input = new Input(['option' => $component, $key => $source, 'id' => $source, 'extension' => 'com_content.article',
            'context'                => 'com_content.article', 'workflow_id' => 12, 'tour_id' => 12, 'menutype' => 'draft-menu', 'task' => $nativeTask]);
        $input->post->set('jform', $data);
        $user        = $this->createMock(User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturn(true);
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'user' ? $user : $default);
        $session->method('getToken')->willReturn('context-save-token');
        $session->method('isNew')->willReturn(false);
        $states = [$component . '.edit.' . strtolower($entity) . '.id' => [42]];
        $app    = $this->createMock(CMSApplication::class);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getInput')->willReturn($input);
        $app->method('getSession')->willReturn($session);
        $app->method('getName')->willReturn('contextsave');
        $app->method('getLanguage')->willReturn(new Language('en-GB', false));
        $app->method('getDocument')->willReturn(new \Joomla\CMS\Document\HtmlDocument());
        $app->method('getLogger')->willReturn(new NullLogger());
        $app->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'secret' ? 'fixture-secret' : $default);
        $app->method('getUserState')->willReturnCallback(static fn ($key, $default = null) => $states[$key] ?? $default);
        $app->method('setUserState')->willReturnCallback(static function ($key, $value) use (&$states) {
            $states[$key] = $value;
            return null;
        });
        $app->method('getUserStateFromRequest')->willReturnCallback(static fn ($key, $request, $default = null, $type = 'none') => $input->get($request, $default, $type));
        Factory::$application = $app;
        Factory::getContainer()->set('ContextsaveRouter', new Router());
        $input->server->set('HTTP_X_CSRF_TOKEN', Session::getFormToken());

        $descriptor = ['context' => $context, 'target' => $source === '' ? null : $source, 'scope' => $scope,
            'version'            => 1, 'revision' => 'rendered', 'values' => ['title' => 'Canonical'], 'fields' => ['title' => ['control' => 'text']], 'excluded' => []];
        $draft = $this->store->open(7, $descriptor, $this->now);
        $this->store->preserve(7, $draft['draft_id'], 1, ['title' => 'Recoverable'], $this->now, static fn ($row, $payload) => $payload);
        if ($outcome !== 'absent') {
            $input->post->set('autosave', ['draft_id' => $draft['draft_id'], 'revision' => $outcome === 'malformed' ? 'invalid' : '1']);
        }
        if (\in_array($outcome, ['later', 'scope-later', 'scope-restored'], true)) {
            $this->store->preserve(7, $draft['draft_id'], 2, ['title' => 'Recoverable later'], $this->now, static fn ($row, $payload) => $payload);
        }
        $restored = null;
        $other    = null;
        if ($scopeChange) {
            $other = $this->store->open(7, $descriptor, $this->now);
            $this->store->preserve(7, $other['draft_id'], 1, ['title' => 'Another tab'], $this->now, static fn ($row, $payload) => $payload);
        }
        if ($outcome === 'scope-restored') {
            $restored = $this->store->open(7, $descriptor, $this->now);
            $this->store->preserve(7, $restored['draft_id'], 1, ['title' => 'Recoverable'], $this->now, static fn ($row, $payload) => $payload);
            $this->store->preserve(7, $restored['draft_id'], 2, ['title' => 'Restored later'], $this->now, static fn ($row, $payload) => $payload);
            $input->post->set('autosave', ['draft_id' => $draft['draft_id'], 'revision' => '1', 'source_id' => $restored['draft_id'], 'source_revision' => '1']);
        }

        $provider = $this->createMock(DraftProviderInterface::class);
        $provider->method('getContext')->willReturn($context);
        $provider->method('describe')->willReturn(array_replace($descriptor, ['target' => $result, 'revision' => 'Potential later writer', 'scope' => $resultScope]));
        $componentClass  = $prefix . 'Extension\\' . $name . 'Component';
        $nativeComponent = new $componentClass($this->createMock(\Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface::class));
        $nativeComponent->setAutosaveProvider($context, $provider);
        $resolver = new AutosaveContextResolver($app, static fn (): bool => true);
        $service  = new DraftService($resolver, $this->store);
        $autosave = new \Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent($service);
        $app->method('bootComponent')->willReturnCallback(static function ($name) use ($component, $nativeComponent, $autosave, $outcome) {
            if ($name !== 'com_autosave') {
                return $nativeComponent;
            }
            if ($outcome === 'unavailable') {
                throw new \RuntimeException('Draft storage unavailable');
            }
            return $autosave;
        });
        $model = $this->createMock($prefix . 'Model\\' . $entity . 'Model');
        $model->method('getName')->willReturn(strtolower($entity));
        $model->method('getState')->willReturnCallback(static fn ($state, $default = null) => $state === strtolower($entity) . '.id' ? (int) $result : $default);
        $model->method('checkin')->willReturn(true);
        $model->method('checkout')->willReturn(true);
        $form = $this->createMock(Form::class);
        $form->method('getFieldset')->willReturn([]);
        $model->method('getForm')->willReturn($form);
        $model->method('validate')->willReturnCallback(static fn ($form, $values) => $outcome === 'validation' ? false : $values);
        $model->method('getErrors')->willReturn([new \RuntimeException('Native validation failed')]);
        $model->expects($outcome === 'validation' ? $this->never() : $this->once())->method('save')->willReturn($outcome !== 'model');
        $model->method('getItem')->willReturn((object) (array_replace($data, ['id' => (int) $result, $key => (int) $result]) + ['metadata' => []]));
        $table = $this->createMock(Table::class);
        $table->method('getKeyName')->willReturn($key);
        $table->method('hasField')->willReturn(false);
        $table->method('load')->willReturn(true);
        $table->id        = 12;
        $table->client_id = 0;
        $model->method('getTable')->willReturn($table);
        $factory = $this->createMock(\Joomla\CMS\MVC\Factory\MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $controllerClass = $prefix . 'Controller\\' . $entity . 'Controller';
        $controller      = new $controllerClass(['base_path' => JPATH_ADMINISTRATOR . '/components/' . $component], $factory, $app, $input);
        $controller->setDispatcher(new Dispatcher());
        try {
            $success = $controller->execute($nativeTask);
        } catch (\Throwable $exception) {
            if ($component !== 'com_menus' || $entity !== 'Item' || $outcome !== 'validation') {
                throw $exception;
            }
            // The pinned native ItemController indexes validData before checking false.
            // Preserve its baseline failure behavior rather than fixing shared saving in this feature.
            $this->assertStringContainsString('array offset on false', $exception->getMessage());
            $success = false;
        }
        $this->assertSame(!\in_array($outcome, ['validation', 'model'], true), $success);
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame(\in_array($outcome, ['success', 'scope-success'], true) ? null : ['title' => \in_array($outcome, ['later', 'scope-later', 'scope-restored'], true) ? 'Recoverable later' : 'Recoverable'], $row['payload']);
        $this->assertSame($descriptor['revision'], $row['baseline_revision'], 'A post-save read is not proof of this native save revision');
        if ($scopeChange) {
            $accepted = \in_array($outcome, ['scope-success', 'scope-later', 'scope-restored'], true);
            $this->assertSame($accepted ? 1 : 0, $row['retired_revision']);
            $this->assertSame($accepted ? $resultScope : $scope, $row['scope']);
            $this->assertSame($scope, $this->store->read(7, $other['draft_id'], $this->now)['scope']);
            $this->assertSame(0, $this->store->read(7, $other['draft_id'], $this->now)['retired_revision']);
            if ($restored !== null) {
                $recovered = $this->store->read(7, $restored['draft_id'], $this->now);
                $this->assertSame(1, $recovered['retired_revision']);
                $this->assertSame($resultScope, $recovered['scope']);
                $this->assertSame(['title' => 'Restored later'], $recovered['payload']);
            }
            if ($accepted) {
                $fresh = $this->store->open(7, array_replace($descriptor, ['scope' => $resultScope]), $this->now);
                $found = $this->store->find(7, $fresh, $this->now)['candidates'];
                if ($outcome === 'scope-later') {
                    $input->post->set('autosave', ['draft_id' => $draft['draft_id'], 'revision' => '2']);
                    $changedKeys = $entity === 'Language' ? ['lang_code'] : ($entity === 'Item' ? ['menutype', 'menu_id'] : ['menutype']);
                    DraftService::reconcileNativeSave($app, $input, $context, $source, $scope, 'save', $result, null, false, [], $changedKeys);
                    $returned = $this->store->read(7, $draft['draft_id'], $this->now);
                    $this->assertSame($scope, $returned['scope']);
                    $this->assertSame(2, $returned['retired_revision']);
                    $this->assertNull($returned['payload'], 'Returning to the earlier scope cannot resurrect submitted content.');
                }

                $this->assertCount($outcome === 'scope-success' ? 0 : ($restored === null ? 1 : 2), $found);
            }
        }

        if ($source === '' && $outcome === 'success') {
            $this->assertSame($resultScope, $row['scope']);
            $this->assertSame($result, $row['target_id']);
        }
    }

    public static function outcomes(): array
    {
        $cases = [];
        foreach (NativeContextDraftTest::contexts() as [$component, $entity]) {
            foreach (['success', 'later', 'validation', 'model', 'unavailable', 'absent', 'malformed'] as $outcome) {
                $cases[] = [$component, $entity, 'apply', $outcome];
            }
            if ($component !== 'com_templates') {
                $cases[] = [$component, $entity, 'create', 'success'];
            }
        }
        foreach ([['com_menus', 'Menu'], ['com_menus', 'Item'], ['com_languages', 'Language']] as [$component, $entity]) {
            foreach (['apply', 'save'] as $task) {
                foreach (['scope-success', 'scope-later', 'scope-restored', 'scope-denied'] as $outcome) {
                    $cases[] = [$component, $entity, $task, $outcome];
                }
                if ($entity === 'Item') {
                    $cases[] = [$component, $entity, $task, 'scope-type'];
                }
            }
        }

        return $cases;
    }
}
