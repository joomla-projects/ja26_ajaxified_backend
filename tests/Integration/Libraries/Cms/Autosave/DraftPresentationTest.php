<?php

/**
 * @package   Joomla.IntegrationTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Toolbar\ContainerAwareToolbarFactory;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\DI\Container;
use Joomla\Input\Input;
use Joomla\Tests\Integration\IntegrationTestCase;
use Psr\Log\LoggerInterface;

/**
 * Native view allocation, configuration, quota metadata and toolbar integration.
 */
class DraftPresentationTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\DraftPresentationFixture;

    use \Joomla\Tests\Integration\Libraries\Cms\Autosave\DraftDatabaseFixture;

    private \Joomla\Database\DatabaseInterface $db;

    /**
     * @dataProvider storageAvailability
     */
    public function testSharedViewOpensOnlyMetadataAndKeepsNativeErrorValues(bool $available, int $interval, int $expectedInterval, bool $loggerFails = false): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        (new \ReflectionProperty(\Joomla\CMS\Plugin\PluginHelper::class, 'plugins'))->setValue(
            null,
            [
            (object) ['type' => 'system', 'name' => 'autosave', 'params' => json_encode(['interval' => $interval]), 'id' => 1],
            ]
        );
        $application = $this->createMock(CMSApplication::class);
        $application->method('getName')->willReturn('draftviewtest');
        $application->method('isClient')->willReturnCallback(static fn (string $client): bool => $client === 'administrator');
        $application->method('getClientId')->willReturn(1);
        $application->method('getInput')->willReturn(new Input());
        $application->method('getLanguage')->willReturn(Factory::getLanguage());
        $logger = $this->createMock(LoggerInterface::class);
        $warning = $logger->expects($available ? $this->never() : $this->once())->method('warning')->with(
            'Optional draft form setup failed.',
            ['exception_class' => \RuntimeException::class]
        );
        if ($loggerFails) {
            $warning->willThrowException(new \RuntimeException('Private logger detail'));
        }
        $application->method('getLogger')->willReturn($logger);
        Factory::$application = $application;
        $user                 = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id             = 7;
        $user->guest          = false;
        $user->method('getParam')->willReturn('UTC');
        $user->method('getTimezone')->willReturn(new \DateTimeZone('Asia/Kolkata'));
        $form = new \Joomla\CMS\Form\Form('native.category', ['control' => 'jform']);
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $form->bind(['title' => 'Native validation-error value']);
        $item   = (object) ['id' => 42, 'title' => 'Canonical value'];
        $policy = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $policy->method('getContext')->willReturn('com_categories.category');
        $policy->expects($this->once())->method('describe')->with($form, $item, $user)->willReturn(
            [
            'context' => 'com_categories.category', 'target' => '42', 'scope' => ['extension' => 'com_content'],
            'version' => 1, 'revision' => 'native snapshot', 'values' => ['title' => 'Canonical value'],
            'fields'  => ['title' => ['control' => 'text', 'label' => 'Title', 'id' => 'jform_title', 'name' => 'jform[title]']], 'excluded' => [],
            ]
        );
        $component = new \Joomla\Component\Categories\Administrator\Extension\CategoriesComponent(
            $this->createMock(\Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface::class)
        );
        $component->setAutosaveProvider('com_categories.category', $policy);
        $resolver    = new \Joomla\CMS\Autosave\AutosaveContextResolver($application, static fn (): bool => true);
        $service     = new \Joomla\CMS\Autosave\DraftService($resolver, $this->store);
        $unavailable = $this->createMock(\Joomla\Database\DatabaseInterface::class);
        $unavailable->method('createQuery')->willThrowException(new \RuntimeException('Storage unavailable'));
        $helper = new \Joomla\CMS\Autosave\DraftView(
            new \Joomla\CMS\Autosave\DraftService($resolver, $available ? $this->store : new \Joomla\CMS\Autosave\DraftStore($unavailable)),
            $resolver
        );
        $application->method('bootComponent')->willReturn($component);
        Factory::getContainer()->set('DraftviewtestRouter', new \Joomla\CMS\Router\Router());
        $document = $this->document();
        $application->method('getDocument')->willReturn($document);
        self::registerSourceAssetManifests(
            $document->getWebAssetManager()->getRegistry(),
            ['system', 'com_autosave', 'com_categories']
        );
        $helper->configure(
            $application,
            $document,
            $user,
            $form,
            $item,
            [
            'context' => 'com_categories.category', 'formId' => 'item-form', 'statusId' => 'category-autosave-status',

            'saveTasks' => ['category.apply', 'category.save'],
            ]
        );
        $configuration = $document->getScriptOptions('com_autosave.forms')['item-form'];
        $this->assertSame($expectedInterval, $configuration['interval']);
        $this->assertSame($available, $configuration['available']);
        $this->assertSame('Native validation-error value', $form->getValue('title'));
        $this->assertStringNotContainsString('Canonical value', json_encode($configuration));
        $this->assertStringNotContainsString('Native validation-error value', json_encode($configuration));
        if ($available) {
            $this->assertSame('Asia/Kolkata', $configuration['timeZone']);
            $row = $this->store->read(7, $configuration['draftId'], $this->now);
            $this->assertNull($row['payload']);
            $this->assertSame('native snapshot', $row['baseline_revision']);
        } else {
            $this->assertSame('service_unavailable', $configuration['errorCode']);
        }
    }

    /**
     * @dataProvider quotaMetadataAvailability
     */
    public function testFullQuotaPublishesOnlyOptionalOwnerHeadersAndKeepsNativeValues(bool $metadataFails): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $descriptor = [
            'context' => 'com_content.article', 'target' => '42', 'scope' => [], 'version' => 1,
            'revision' => 'saved item', 'values' => ['title' => 'Saved'],
            'fields' => ['title' => ['control' => 'text', 'label' => 'Title']], 'excluded' => [],
        ];
        $now = new \Joomla\CMS\Date\Date('now', 'UTC');
        for ($index = 0; $index < \Joomla\CMS\Autosave\DraftStore::MAX_OWNER_ROWS; $index++) {
            $this->store->open(7, $descriptor, $now);
        }
        $app      = Factory::getApplication();
        $logger = $this->createMock(LoggerInterface::class);
        $warning = $logger->expects($metadataFails ? $this->once() : $this->never())->method('warning')->with(
            'Optional draft allocation metadata could not be listed.',
            ['exception_class' => \RuntimeException::class]
        );
        if ($metadataFails) {
            $warning->willThrowException(new \RuntimeException('Private logger detail'));
        }
        $app->method('getLogger')->willReturn($logger);
        $provider = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $provider->method('getContext')->willReturn($descriptor['context']);
        $provider->method('describe')->willReturn($descriptor);
        $component = new \Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent([$descriptor['context'] => true]);
        $component->setAutosaveProvider($descriptor['context'], $provider);
        $app->method('bootComponent')->willReturn($component);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $helper   = new \Joomla\CMS\Autosave\DraftView(new \Joomla\CMS\Autosave\DraftService($resolver, $this->store), $resolver);
        $user        = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturn(true);
        $user->method('getParam')->willReturn('UTC');
        $user->method('getTimezone')->willReturn(new \DateTimeZone('Asia/Kolkata'));
        $document = $this->document();
        Factory::getContainer()->set('DraftpresentationtestRouter', new \Joomla\CMS\Router\Router());
        $app->method('getDocument')->willReturn($document);
        $form = new \Joomla\CMS\Form\Form('quota.form', ['control' => 'jform']);
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $form->bind(['title' => 'Local validation values']);
        if ($metadataFails) {
            $this->db->setMonitor(
                new class () implements \Joomla\Database\QueryMonitorInterface {
                    public function startQuery(string $sql, ?array $boundParams = null): void
                    {
                        if (str_contains($sql, 'has_preserved_values')) {
                            throw new \RuntimeException('Metadata listing unavailable');
                        }
                    }

                    public function stopQuery(): void
                    {
                    }
                }
            );
        }
        try {
            $this->assertTrue(
                $helper->configure(
                    $app,
                    $document,
                    $user,
                    $form,
                    (object) [],
                    [
                    'context' => $descriptor['context'], 'formId' => 'item-form', 'statusId' => 'quota-status', 'saveTasks' => ['article.save'],
                    ]
                )
            );
        } finally {
            $this->db->setMonitor(null);
        }
        $configuration = $document->getScriptOptions('com_autosave.forms')['item-form'];
        $this->assertFalse($configuration['available']);
        $this->assertSame('draft_limit_reached', $configuration['errorCode']);
        $this->assertArrayNotHasKey('draftId', $configuration);
        $this->assertArrayNotHasKey('fields', $configuration);
        $this->assertSame('Local validation values', $form->getValue('title'));
        $this->assertStringNotContainsString('Local validation values', json_encode($configuration));
        if ($metadataFails) {
            $this->assertArrayNotHasKey('quota', $configuration);
        } else {
            $this->assertSame('Asia/Kolkata', $configuration['timeZone']);
            $this->assertCount(\Joomla\CMS\Autosave\DraftStore::MAX_OWNER_ROWS, $configuration['quota']);
            $this->assertStringContainsString('option=com_ajax', $configuration['endpoint']);
            $this->assertSame(
                [
                'draft_id', 'context', 'target_id', 'created_at', 'updated_at', 'expires_at', 'has_preserved_values', 'has_save_marker',
                ],
                array_keys($configuration['quota'][0])
            );
            $this->assertStringEndsWith('Z', $configuration['quota'][0]['updated_at']);
        }
    }

    public static function quotaMetadataAvailability(): array
    {
        return [[false], [true]];
    }

    public function testSharedOptionsKeepSeparateFormsAndDoNotAllocateTheSameFormTwice(): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $app      = Factory::getApplication();
        $provider = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $provider->method('getContext')->willReturn('com_content.article');
        $provider->expects($this->exactly(2))->method('describe')->willReturn(
            [
            'context'  => 'com_content.article', 'target' => '42', 'scope' => [], 'version' => 1,
            'revision' => 'saved item', 'values' => ['title' => 'Saved'], 'fields' => ['title' => ['control' => 'text', 'label' => 'Title']], 'excluded' => [],
            ]
        );
        $component = new \Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent(['com_content.article' => true]);
        $component->setAutosaveProvider('com_content.article', $provider);
        $app->method('bootComponent')->willReturn($component);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $helper   = new \Joomla\CMS\Autosave\DraftView(new \Joomla\CMS\Autosave\DraftService($resolver, $this->store), $resolver);
        $user     = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id = 7;
        $user->method('getParam')->willReturn('UTC');
        $user->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
        $document = $this->document();
        Factory::getContainer()->set('DraftpresentationtestRouter', new \Joomla\CMS\Router\Router());
        $app->method('getDocument')->willReturn($document);
        $form = new \Joomla\CMS\Form\Form('two.forms');
        foreach (['first', 'second'] as $id) {
            $this->assertTrue(
                $helper->configure(
                    $app,
                    $document,
                    $user,
                    $form,
                    (object) [],
                    [
                    'context' => 'com_content.article', 'formId' => $id, 'statusId' => $id . '-status', 'saveTasks' => ['article.save'],
                    ]
                )
            );
        }
        $before = $document->getScriptOptions('com_autosave.forms');
        $this->assertNotSame($before['first']['draftId'], $before['second']['draftId']);
        $this->assertFalse($helper->configure($app, $document, $user, $form, (object) [], ['formId' => 'first']));
        $this->assertSame($before, $document->getScriptOptions('com_autosave.forms'));
        $this->assertSame(2, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
    }

    public static function storageAvailability(): array
    {
        return [[true, 45, 45000], [false, 45, 45000], [true, 0, 1000], [false, 45, 45000, true]];
    }

    /**
     * @dataProvider nativeToolbarLifecycles
     */
    public function testActualNativeViewLifecycleMountsStatusAfterActions(string $component, string $entity, string $layout, bool $assetsAvailable = true): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        [$app, $user, $dispatcher, $document] = $this->nativeViewApplication($component, $layout);
        $toolbar = $document->getToolbar('toolbar');
        $context = $component . '.' . strtolower($entity);
        $prefix  = 'Joomla\\Component\\' . ucfirst(substr($component, 4)) . '\\Administrator\\';
        if (!$assetsAvailable) {
            $registry = $document->getWebAssetManager()->getRegistry();
            self::primeAutosaveAssetRegistry($registry);
            $registry->remove('script', 'com_autosave.draft-form');
        }
        $form = new \Joomla\CMS\Form\Form('native.lifecycle', ['control' => 'jform']);
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $item = (object) ['id' => 42, 'catid' => 3, 'name' => 'Canonical', 'title' => 'Canonical', 'client_id' => 0,
            'type'             => 'component', 'xml' => new \SimpleXMLElement('<extension/>')];
        $provider = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $provider->method('getContext')->willReturn($context);
        $provider->method('describe')->willReturn(
            ['context' => $context, 'target' => '42', 'scope' => [], 'version' => 1,
            'revision'                                       => 'canonical snapshot', 'values' => ['title' => 'Canonical'],
            'fields'                                         => ['title' => ['id' => 'jform_title', 'name' => 'jform[title]', 'control' => 'text', 'label' => 'Title']], 'excluded' => []]
        );
        $class     = $prefix . 'Extension\\' . ucfirst(substr($component, 4)) . 'Component';
        $extension = new $class($this->createMock(\Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface::class));
        $extension->setAutosaveProvider($context, $provider);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $service  = new \Joomla\CMS\Autosave\DraftService($resolver, $this->store);
        $this->registerDraftService($service, true, $resolver);
        $app->method('bootComponent')->willReturn($extension);
        $model = $this->nativeViewModel($prefix, $entity, $item, $form);
        $model->method('getState')->willReturnCallback(
            static fn ($key = null, $default = null) => $key === null
            ? new \Joomla\Registry\Registry(['params' => [], 'item.menutypeid' => 12]) : $default
        );
        $methods  = ['loadTemplate', 'getCurrentUser'];
        $hasModal = method_exists($prefix . 'View\\' . $entity . '\\HtmlView', 'addModalToolbar');
        if ($hasModal) {
            $methods[] = 'addModalToolbar';
        }
        if ($entity !== 'Contact') {
            $methods[] = 'addToolbar';
        }
        $view = $this->getMockBuilder($prefix . 'View\\' . $entity . '\\HtmlView')
            ->setConstructorArgs([['name' => strtolower($entity), 'option' => $component, 'base_path' => JPATH_ADMINISTRATOR . '/components/' . $component]])->onlyMethods($methods)->getMock();
        $view->setDispatcher($dispatcher);
        $view->setDocument($document);
        $view->setLayout($layout);
        $view->setModel($model, true);
        $view->method('getCurrentUser')->willReturn($user);
        $view->method('loadTemplate')->willReturn('');
        $build = static function () use ($toolbar): void {
            $toolbar->save('entity.save');
            $toolbar->cancel('entity.cancel');
            $toolbar->help('Native_Edit');
        };
        if ($hasModal) {
            $view->method('addModalToolbar')->willReturnCallback($build);
        }
        if ($entity !== 'Contact') {
            $view->method('addToolbar')->willReturnCallback($build);
        }
        ob_start();
        try {
            $view->display();
        } finally {
            ob_end_clean();
        }
        $names = array_map(static fn ($button) => $button->getName(), $toolbar->getItems());
        $this->assertSame($assetsAvailable ? ['save', 'cancel', 'autosave-status', 'help'] : ['save', 'cancel', 'help'], $names);
        $configuration = $document->getScriptOptions('com_autosave.forms');
        if ($assetsAvailable) {
            $this->assertTrue(reset($configuration)['available']);
        } else {
            $this->assertSame([], $configuration);
        }
    }

    public static function nativeToolbarLifecycles(): array
    {
        return [
            ['com_menus', 'Item', 'edit'], ['com_menus', 'Item', 'modal'], ['com_menus', 'Menu', 'edit'],
            ['com_modules', 'Module', 'edit'], ['com_modules', 'Module', 'modal'],
            ['com_redirect', 'Link', 'edit'], ['com_tags', 'Tag', 'edit'],
            ['com_templates', 'Style', 'edit'], ['com_users', 'Note', 'edit'],
            ['com_contact', 'Contact', 'modal'],
            ['com_menus', 'Item', 'edit', false], ['com_contact', 'Contact', 'modal', false],
        ];
    }

    /**
     * @dataProvider sharedNativeViews
     */
    public function testEveryNativeViewUsesTheSharedServiceAndKeepsItsSaveTasks(string $component, string $entity, string $context, string $formId, array $tasks, bool $serviceAvailable): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        [$app, $user, $dispatcher, $document] = $this->nativeViewApplication($component, 'edit');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($serviceAvailable ? $this->never() : $this->once())->method('warning')->with(
            'Optional draft view setup failed.',
            ['exception_class' => \RuntimeException::class]
        )->willThrowException(new \RuntimeException('Private logger detail'));
        $app->method('getLogger')->willReturn($logger);
        $app->method('getUserStateFromRequest')->willReturn('en-GB1');
        $provider = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $provider->method('getContext')->willReturn($context);
        $provider->expects($serviceAvailable ? $this->once() : $this->never())->method('describe')->willReturnCallback(
            function ($form, $item) use ($context): array {
                $this->assertSame('Local validation-error value', $form->getValue('title'));
                $this->assertSame('Saved', $item->title);
                $scope = match ($context) {
                    'com_categories.category' => ['draftExtension' => 'com_content'],
                    'com_fields.field', 'com_fields.group' => ['draftContext' => 'com_content.article'],
                    'com_languages.override' => ['client' => 'administrator', 'language' => 'en-GB'],
                    'com_workflow.workflow', 'com_workflow.stage', 'com_workflow.transition' => ['draftExtension' => 'com_content.article'],
                    'com_guidedtours.step' => ['tour_id' => 12],
                    default => [],
                };
                foreach ($scope as $property => $value) {
                    $this->assertSame($value, $item->$property);
                }
                return [
                'context'  => $context, 'target' => '42', 'scope' => [], 'version' => 1,
                'revision' => 'saved item', 'values' => ['title' => 'Saved'], 'fields' => ['title' => ['control' => 'text', 'label' => 'Title']], 'excluded' => [],
                ];
            }
        );
        $extension = new \Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent([$context => true]);
        $extension->setAutosaveProvider($context, $provider);
        $app->method('bootComponent')->willReturnCallback(
            function ($name) use ($component, $extension) {
                $this->assertContains($name, [$component, 'com_content']);
                return $name === $component ? $extension : $this->createMock(\Joomla\CMS\Extension\ComponentInterface::class);
            }
        );
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $this->registerDraftService(new \Joomla\CMS\Autosave\DraftService($resolver, $this->store), $serviceAvailable, $resolver);
        $prefix = 'Joomla\\Component\\' . ucfirst(substr($component, 4)) . '\\Administrator\\';
        $form = new \Joomla\CMS\Form\Form('shared.native.form');
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $form->bind(['title' => 'Local validation-error value']);
        $item = (object) ['id' => 42, 'title' => 'Saved', 'name' => 'Saved', 'alias' => 'saved', 'catid' => 3, 'language' => '*',
            'workflow_id' => 12, 'tour_id' => 12, 'context' => 'com_content.article', 'client_id' => 0,
            'extension' => 'com_content.article', 'type' => 'text', 'key' => 'COM_EXAMPLE', 'lang_id' => 42,
            'filter_id' => 42, 'xml' => new \SimpleXMLElement('<extension/>')];
        $state = new \Joomla\Registry\Registry(
            [
            'category' => ['extension' => 'com_content', 'component' => 'com_content'],
            'filter'   => ['extension' => 'com_content.article', 'context' => 'com_content.article', 'client' => 'administrator', 'language' => 'en-GB'],
            'field'    => ['context' => 'com_content.article'],
            'item'     => ['menutypeid' => 12],
            'params'   => [],
            ]
        );
        $model = $this->nativeViewModel($prefix, $entity, $item, $form);
        $model->method('getState')->willReturnCallback(static fn ($key = null, $default = null) => $key === null ? $state : $state->get($key, $default));
        $class   = $prefix . 'View\\' . $entity . '\\HtmlView';
        $methods = ['getCurrentUser', 'loadTemplate', 'addToolbar'];
        if (method_exists($class, 'addModalToolbar')) {
            $methods[] = 'addModalToolbar';
        }
        $view = $this->getMockBuilder($class)
            ->setConstructorArgs([['name' => strtolower($entity), 'option' => $component, 'base_path' => JPATH_ADMINISTRATOR . '/components/' . $component]])
            ->onlyMethods($methods)->getMock();
        $view->method('getCurrentUser')->willReturn($user);
        $view->method('loadTemplate')->willReturn('');
        $view->setDispatcher($dispatcher);
        $view->setDocument($document);
        $view->setLayout('edit');
        $view->setModel($model, true);
        if ($serviceAvailable) {
            $this->assertInstanceOf(\Joomla\CMS\Autosave\DraftView::class, Factory::getContainer()->get(\Joomla\CMS\Autosave\DraftView::class));
        }
        ob_start();
        try {
            $view->display();
        } finally {
            ob_end_clean();
        }
        $this->assertSame('Local validation-error value', $form->getValue('title'));
        $this->assertSame($serviceAvailable, $view->autosaveEnabled);
        $options = $document->getScriptOptions('com_autosave.forms');
        if ($serviceAvailable) {
            $this->assertSame([$formId], array_keys($options));
            $this->assertTrue($options[$formId]['available']);
            $this->assertSame($context, $options[$formId]['context']);
            $this->assertSame($view->autosaveStatusId, $options[$formId]['statusId']);
            $this->assertSame($tasks, $options[$formId]['saveTasks']);
            $this->assertContains('com_autosave.draft-form', array_keys($document->getWebAssetManager()->getAssets('script', true)));
        } else {
            $this->assertSame([], $options);
        }
        $this->assertSame($serviceAvailable ? 1 : 0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
    }

    public static function sharedNativeViews(): array
    {
        $contexts = [
            ['com_banners', 'Banner', 'com_banners.banner', 'banner-form', ['banner.apply', 'banner.save', 'banner.save2new', 'banner.save2copy']],
            ['com_banners', 'Client', 'com_banners.client', 'client-form', ['client.apply', 'client.save', 'client.save2new', 'client.save2copy']],
            ['com_categories', 'Category', 'com_categories.category', 'item-form', ['category.apply', 'category.save', 'category.save2new', 'category.save2copy', 'category.save2menulist', 'category.save2menublog', 'category.editAssociations']],
            ['com_contact', 'Contact', 'com_contact.contact', 'contact-form', ['contact.apply', 'contact.save', 'contact.save2new', 'contact.save2copy', 'contact.save2menu', 'contact.editAssociations']],
            ['com_content', 'Article', 'com_content.article', 'item-form', ['article.apply', 'article.save', 'article.save2new', 'article.save2copy', 'article.save2menu', 'article.editAssociations']],
            ['com_fields', 'Field', 'com_fields.field', 'item-form', ['field.apply', 'field.save', 'field.save2new', 'field.save2copy']],
            ['com_fields', 'Group', 'com_fields.group', 'item-form', ['group.apply', 'group.save', 'group.save2new', 'group.save2copy']],
            ['com_finder', 'Filter', 'com_finder.filter', 'adminForm', ['filter.apply', 'filter.save', 'filter.save2new', 'filter.save2copy']],
            ['com_guidedtours', 'Step', 'com_guidedtours.step', 'guidedtour-dates-form', ['step.apply', 'step.save', 'step.save2new', 'step.save2copy']],
            ['com_guidedtours', 'Tour', 'com_guidedtours.tour', 'guidedtours-form', ['tour.apply', 'tour.save', 'tour.save2new', 'tour.save2copy']],
            ['com_languages', 'Language', 'com_languages.language', 'language-form', ['language.apply', 'language.save', 'language.save2new', 'language.save2copy']],
            ['com_languages', 'Override', 'com_languages.override', 'override-form', ['override.apply', 'override.save', 'override.save2new']],
            ['com_menus', 'Item', 'com_menus.item', 'item-form', ['item.apply', 'item.save', 'item.save2new', 'item.save2copy', 'item.editAssociations']],
            ['com_menus', 'Menu', 'com_menus.menu', 'item-form', ['menu.apply', 'menu.save', 'menu.save2new', 'menu.save2copy']],
            ['com_modules', 'Module', 'com_modules.module', 'module-form', ['module.apply', 'module.save', 'module.save2new', 'module.save2copy']],
            ['com_newsfeeds', 'Newsfeed', 'com_newsfeeds.newsfeed', 'newsfeed-form', ['newsfeed.apply', 'newsfeed.save', 'newsfeed.save2new', 'newsfeed.save2copy', 'newsfeed.editAssociations']],
            ['com_redirect', 'Link', 'com_redirect.link', 'link-form', ['link.apply', 'link.save', 'link.save2new', 'link.save2copy']],
            ['com_tags', 'Tag', 'com_tags.tag', 'item-form', ['tag.apply', 'tag.save', 'tag.save2new', 'tag.save2copy']],
            ['com_templates', 'Style', 'com_templates.style', 'style-form', ['style.apply', 'style.save', 'style.save2new', 'style.save2copy']],
            ['com_users', 'Group', 'com_users.group', 'group-form', ['group.apply', 'group.save', 'group.save2new', 'group.save2copy']],
            ['com_users', 'Level', 'com_users.level', 'level-form', ['level.apply', 'level.save', 'level.save2new', 'level.save2copy']],
            ['com_users', 'Note', 'com_users.note', 'note-form', ['note.apply', 'note.save', 'note.save2new', 'note.save2copy']],
            ['com_workflow', 'Stage', 'com_workflow.stage', 'workflow-form', ['stage.apply', 'stage.save', 'stage.save2new', 'stage.save2copy']],
            ['com_workflow', 'Transition', 'com_workflow.transition', 'workflow-form', ['transition.apply', 'transition.save', 'transition.save2new', 'transition.save2copy']],
            ['com_workflow', 'Workflow', 'com_workflow.workflow', 'workflow-form', ['workflow.apply', 'workflow.save', 'workflow.save2new', 'workflow.save2copy']],
        ];
        $cases = [];
        foreach ($contexts as $case) {
            $cases[$case[2] . ' available']           = [...$case, true];
            $cases[$case[2] . ' service unavailable'] = [...$case, false];
        }
        return $cases;
    }

    /**
     * @dataProvider nonEditingLayouts
     */
    public function testNonEditingLayoutsDoNotResolveDraftServices(string $component, string $entity, string $layout): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $lookups = 0;
        Factory::getContainer()->share(
            \Joomla\CMS\Autosave\DraftView::class,
            static function () use (&$lookups): void {
                $lookups++;
                throw new \LogicException('Draft services must not be used by this layout.');
            }
        );
        $document = $this->document();
        $class    = 'Joomla\\Component\\' . ucfirst(substr($component, 4)) . '\\Administrator\\View\\' . $entity . '\\HtmlView';
        $view     = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $view->setDocument($document);
        $view->setLayout($layout);
        (new \ReflectionMethod($class, 'prepareAutosave'))->invoke(
            $view,
            [
            'context' => $component . '.' . strtolower($entity), 'formId' => 'non-edit-form',
            'statusId' => 'non-edit-status', 'saveTasks' => [],
            ]
        );
        $this->assertSame(0, $lookups);
        $this->assertFalse($view->autosaveEnabled);
        $this->assertSame([], $document->getScriptOptions('com_autosave.forms'));
        $this->assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
    }

    public static function nonEditingLayouts(): array
    {
        return [
            ['com_content', 'Article', 'pagebreak'],
            ['com_content', 'Article', 'modalreturn'],
            ['com_contact', 'Contact', 'modalreturn'],
            ['com_modules', 'Module', 'modalreturn'],
            ['com_banners', 'Banner', 'raw'],
        ];
    }

    private function nativeViewApplication(string $component, string $layout): array
    {
        $user        = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturn(true);
        $user->method('getParam')->willReturn('UTC');
        $user->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
        $session = $this->createMock(\Joomla\Session\SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'user' ? $user : $default);
        $dispatcher = new \Joomla\Event\Dispatcher();
        $app        = Factory::getApplication();
        $app->getInput()->set('option', $component);
        $app->getInput()->set('layout', $layout);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getSession')->willReturn($session);
        $app->method('getDispatcher')->willReturn($dispatcher);
        $app->method('getUserState')->willReturnArgument(1);
        $app->method('getTemplate')->willReturnCallback(
            static fn ($params = false) => $params
            ? (object) ['template' => 'atum', 'parent' => '', 'params' => new \Joomla\Registry\Registry()] : 'atum'
        );
        Factory::getContainer()->set('DraftpresentationtestRouter', new \Joomla\CMS\Router\Router());
        $document = $this->document();
        $app->method('getDocument')->willReturn($document);
        Factory::$document = $document;
        $factory = new ContainerAwareToolbarFactory();
        $factory->setContainer(new Container());
        $document->setToolbar('toolbar', new Toolbar('toolbar', $factory));
        self::registerSourceAssetManifests($document->getWebAssetManager()->getRegistry(), ['system', 'com_autosave', $component]);

        return [$app, $user, $dispatcher, $document];
    }

    private function nativeViewModel(string $prefix, string $entity, object $item, \Joomla\CMS\Form\Form $form): object
    {
        $model = $this->createMock($prefix . 'Model\\' . $entity . 'Model');
        $model->method('getName')->willReturn(strtolower($entity));
        $model->method('getItem')->willReturn($item);
        $model->method('getForm')->willReturn($form);
        $model->method('getErrors')->willReturn([]);
        $model->expects($this->never())->method('save');
        $model->expects($this->never())->method('validate');
        if ($entity === 'Item') {
            $model->method('getModules')->willReturn([]);
            $model->method('getViewLevels')->willReturn([]);
        }

        return $model;
    }
}
