<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Content\View\Article;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\HTMLDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Toolbar\ContainerAwareToolbarFactory;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\Component\Content\Administrator\View\Article\HtmlView;
use Joomla\DI\Container;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Actual native layout output and Web Asset Manager dependency resolution.
 */
class DraftPresentationTest extends UnitTestCase
{
    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;

    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\DraftDatabaseFixture;

    private \Joomla\Database\DatabaseInterface $db;

    private mixed $previousApplication;

    private mixed $previousLanguage;

    private mixed $previousDocument;

    private array $previousServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousServer      = $_SERVER;
        $_SERVER['HTTP_HOST']      = 'localhost';
        $_SERVER['REQUEST_URI']    = '/administrator/index.php';
        $_SERVER['SCRIPT_NAME']    = '/administrator/index.php';
        $this->previousApplication = Factory::$application;
        $this->previousLanguage    = Factory::$language;
        $this->previousDocument    = Factory::$document;
        Factory::$document         = null;
        $language                  = new Language('en-GB', false);
        $language->load('com_autosave', JPATH_ADMINISTRATOR);
        Factory::$language = $language;
        $app               = $this->createMock(CMSApplication::class);
        $app->method('getName')->willReturn('administrator');
        $app->method('getClientId')->willReturn(1);
        $app->method('getLanguage')->willReturn($language);
        $app->method('getInput')->willReturn(new Input());
        Factory::$application = $app;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->previousServer;
        \Joomla\CMS\Uri\Uri::reset();
        Factory::$application = $this->previousApplication;
        Factory::$language    = $this->previousLanguage;
        Factory::$document    = $this->previousDocument;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    private function output(string $part): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(LayoutHelper::render('joomla.autosave.draft', ['part' => $part], JPATH_ROOT . '/layouts'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new \DOMXPath($document);
    }

    public function testUninitializedStatusCannotClaimReady(): void
    {
        $output = $this->output('status');
        $this->assertFalse($output->query('//*[@data-draft-state="unavailable"]')->item(0)->hasAttribute('hidden'));
        $this->assertTrue($output->query('//*[@data-draft-state="clean"]')->item(0)->hasAttribute('hidden'));
        $this->assertSame(0, $output->query('//*[@data-draft-recovery]')->length);
        $this->assertSame('false', $output->query('//*[@data-draft-status]')->item(0)->getAttribute('aria-busy'));
    }

    public function testStatusKeepsOriginalLabelsWithoutOptionalToolbarControls(): void
    {
        $output = $this->output('status');
        $this->assertSame(0, $output->query('//time | //*[@data-draft-error]')->length);
        $saved = $output->query('//*[@data-draft-state="preserved"]')->item(0);
        $this->assertSame('Saved', trim($saved->textContent));
        $this->assertSame(0, $output->query('//details | //*[@data-draft-toggle or @data-draft-coverage]')->length);
        foreach (['dirty', 'preserving'] as $state) {
            $this->assertSame('Saving...', trim($output->query('//*[@data-draft-state="' . $state . '"]')->item(0)->textContent));
        }
        $this->assertTrue($saved->hasAttribute('hidden'));
        $this->assertSame(2, $output->query('//*[@data-draft-state="clean" or @data-draft-state="preserved"]/*[contains(@class, "icon-check")]')->length);
        $this->assertSame(3, $output->query('//*[@data-draft-state="detecting" or @data-draft-state="dirty" or @data-draft-state="preserving"]/*[contains(@class, "icon-spin")]')->length);
        $status = $output->query('//*[@data-draft-status]')->item(0);
        $this->assertStringContainsString('Use Save or Apply', $status->getAttribute('title'));
        $this->assertSame(1, $output->query('//*[@id="' . $status->getAttribute('aria-describedby') . '"]')->length);
    }

    public function testRecoveryHasItsOwnNativeMount(): void
    {
        $output = $this->output('recovery');
        $this->assertSame(0, $output->query('//*[@data-draft-ui]')->length);
        $this->assertTrue($output->query('//*[@data-draft-recovery]')->item(0)->hasAttribute('hidden'));
        $this->assertTrue($output->query('//*[@data-draft-action="restore"]')->item(0)->hasAttribute('disabled'));
    }

    public function testRecoveryHasNoSeparateEntryButtonInToolbarOrForm(): void
    {
        foreach (['status', 'recovery'] as $part) {
            $output = $this->output($part);
            $this->assertSame(0, $output->query('//*[@data-draft-action="show-recovery"]')->length);
        }
        $this->assertSame(1, $this->output('recovery')->query('//*[@data-draft-action="restore"]')->length);
    }

    /** @dataProvider migratedContexts */
    public function testNativeRegistryActivatesTheNewImportMapAndEditorDependencies(string $component, string $script): void
    {
        $registry = new WebAssetRegistry();
        foreach (['system', 'com_autosave', $component] as $registryComponent) {
            $registry->addRegistryFile('media_source/' . $registryComponent . '/joomla.asset.json');
        }
        $manager = new WebAssetManager($registry);
        $manager->useScript($script);
        $assets = $manager->getAssets('script', true);
        $names  = array_map(static fn ($asset) => $asset->getName(), $assets);
        $this->assertContains('com_autosave.drafts', $names);
        $this->assertContains('editor-api', $names);
        $this->assertContains('joomla.dialog', $names);
        $this->assertNotContains('com_autosave.integration-controller', $names);
        $this->assertTrue((bool) $registry->get('script', 'com_autosave.drafts')->getOption('importmap'));
    }
    public static function migratedContexts(): array
    {
        return [
            ['com_content', 'com_content.article-autosave'],
            ['com_categories', 'com_categories.category-autosave'],
            ['com_languages', 'com_languages.override-autosave'],
            ['com_banners', 'com_banners.banner-autosave'],
            ['com_banners', 'com_banners.client-autosave'],
            ['com_contact', 'com_contact.contact-autosave'],
            ['com_fields', 'com_fields.field-autosave'],
            ['com_fields', 'com_fields.group-autosave'],
            ['com_finder', 'com_finder.filter-autosave'],
            ['com_guidedtours', 'com_guidedtours.tour-autosave'],
            ['com_guidedtours', 'com_guidedtours.step-autosave'],
            ['com_languages', 'com_languages.language-autosave'],
            ['com_menus', 'com_menus.item-autosave'],
            ['com_menus', 'com_menus.menu-autosave'],
            ['com_modules', 'com_modules.module-autosave'],
            ['com_newsfeeds', 'com_newsfeeds.newsfeed-autosave'],
            ['com_redirect', 'com_redirect.link-autosave'],
            ['com_tags', 'com_tags.tag-autosave'],
            ['com_templates', 'com_templates.style-autosave'],
            ['com_users', 'com_users.group-autosave'],
            ['com_users', 'com_users.level-autosave'],
            ['com_users', 'com_users.note-autosave'],
            ['com_workflow', 'com_workflow.workflow-autosave'],
            ['com_workflow', 'com_workflow.stage-autosave'],
            ['com_workflow', 'com_workflow.transition-autosave'],
        ];
    }


    /** @dataProvider storageAvailability */
    public function testSharedViewOpensOnlyMetadataAndKeepsNativeErrorValues(bool $available): void
    {
        $this->db = (new \Joomla\Database\DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'view_draft_']);
        $this->installSchema('postgresql');
        $application = $this->createMock(CMSApplication::class);
        $application->method('getName')->willReturn('draftviewtest');
        $application->method('getClientId')->willReturn(1);
        $application->method('getInput')->willReturn(new Input());
        $application->method('getLanguage')->willReturn(Factory::getLanguage());
        Factory::$application = $application;
        $user                 = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id             = 7;
        $user->guest          = false;
        $user->method('getParam')->willReturn('UTC');
        $form = new \Joomla\CMS\Form\Form('native.category', ['control' => 'jform']);
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $form->bind(['title' => 'Native validation-error value']);
        $item   = (object) ['id' => 42, 'title' => 'Canonical value'];
        $policy = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $policy->method('getContext')->willReturn('com_categories.category');
        $policy->expects($this->once())->method('describe')->with($form, $item, $user)->willReturn([
            'context' => 'com_categories.category', 'target' => '42', 'scope' => ['extension' => 'com_content'],
            'version' => 1, 'revision' => 'native snapshot', 'values' => ['title' => 'Canonical value'],
            'fields'  => ['title' => ['control' => 'text', 'label' => 'Title', 'id' => 'jform_title', 'name' => 'jform[title]']], 'excluded' => [],
        ]);
        $component = new \Joomla\Component\Categories\Administrator\Extension\CategoriesComponent(
            $this->createMock(\Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface::class)
        );
        $component->setAutosaveProvider('com_categories.category', $policy);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($application, static fn (): bool => true);
        $service  = new \Joomla\CMS\Autosave\DraftService($resolver, $this->store);
        $autosave = new \Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent($service);
        $application->method('bootComponent')->willReturnCallback(static function ($name) use ($component, $autosave, $available) {
            if ($name === 'com_categories') {
                return $component;
            }
            if (!$available) {
                throw new \RuntimeException('Draft service unavailable');
            }
            return $autosave;
        });
        Factory::getContainer()->set('DraftviewtestRouter', new \Joomla\CMS\Router\Router());
        $document = new HTMLDocument();
        $application->method('getDocument')->willReturn($document);
        foreach (['system', 'com_autosave', 'com_categories'] as $name) {
            $document->getWebAssetManager()->getRegistry()->addRegistryFile('media_source/' . $name . '/joomla.asset.json');
        }
        \Joomla\CMS\Autosave\DraftView::configure($application, $document, $user, $form, $item, [
            'context'    => 'com_categories.category', 'formId' => 'item-form', 'statusId' => 'category-autosave-status',
            'optionsKey' => 'com_categories.autosave.category', 'script' => 'com_categories.category-autosave',
            'saveTasks'  => ['category.apply', 'category.save'],
        ]);
        $configuration = $document->getScriptOptions('com_categories.autosave.category');
        $this->assertSame($available, $configuration['available']);
        $this->assertSame('Native validation-error value', $form->getValue('title'));
        $this->assertStringNotContainsString('Canonical value', json_encode($configuration));
        $this->assertStringNotContainsString('Native validation-error value', json_encode($configuration));
        if ($available) {
            $row = $this->store->read(7, $configuration['draftId'], $this->now);
            $this->assertNull($row['payload']);
            $this->assertSame('native snapshot', $row['baseline_revision']);
        } else {
            $this->assertSame('service_unavailable', $configuration['errorCode']);
        }
    }

    public static function storageAvailability(): array
    {
        return [[true], [false]];
    }


    /** @dataProvider exceptionTemplates */
    public function testNativeExceptionTemplateSuppliesCompleteDraftMounts(string $component, string $template, string $formId): void
    {
        $document          = new HTMLDocument();
        Factory::$document = $document;
        foreach (['system', 'com_autosave', $component] as $name) {
            $document->getWebAssetManager()->getRegistry()->addRegistryFile('media_source/' . $name . '/joomla.asset.json');
        }
        $application = $this->getMockBuilder(CMSApplication::class)->disableOriginalConstructor()
            ->onlyMethods(['getName', 'getClientId', 'isClient', 'getLanguage', 'getDocument', 'getInput', 'bootComponent', 'get', 'getTemplate'])
            ->addMethods(['getLanguageFilter'])->getMockForAbstractClass();
        $application->method('getName')->willReturn('drafttemplatetest');
        $application->method('getClientId')->willReturn(1);
        $application->method('isClient')->willReturn(true);
        $application->method('getLanguageFilter')->willReturn(false);
        $application->method('getLanguage')->willReturn(Factory::getLanguage());
        $application->method('getDocument')->willReturn($document);
        $application->method('getInput')->willReturn(new Input());
        $application->method('bootComponent')->willReturn($this->createMock(\Joomla\CMS\Extension\ComponentInterface::class));
        Factory::$application = $application;
        Factory::getContainer()->set('DrafttemplatetestRouter', new \Joomla\CMS\Router\Router());
        $form = $this->createMock(\Joomla\CMS\Form\Form::class);
        $form->method('getFieldsets')->willReturn([]);
        $stop = new \RuntimeException('Native business fields reached');
        $form->method('renderField')->willThrowException($stop);
        // Render the actual template through its draft region. Unrelated native field rendering is outside this regression.
        $view = new class ($document, $form, $stop) {
            public bool $autosaveEnabled    = true;
            public string $autosaveStatusId = 'exception-draft-status';
            public object $item;
            public \Joomla\Registry\Registry $state;
            public array $ignore_fieldsets = [];
            public array $fieldsets        = [];
            public string $extension       = 'com_content.article';
            public int $workflowID         = 12;
            public \Joomla\Input\Input $input;
            public bool $useCoreUI         = false;
            public function __construct(private HTMLDocument $document, public \Joomla\CMS\Form\Form $form, private \RuntimeException $stop)
            {
                $this->input = new \Joomla\Input\Input();
                $this->item  = (object) ['lang_id' => 42, 'id' => 42, 'key' => 'COM_EXAMPLE', 'filter_id' => 42, 'client_id' => 0, 'workflow_id' => 12, 'tour_id' => 12, 'context' => 'com_content.article', 'extension' => 'com_content.article', 'type' => 'text', 'xml' => new \SimpleXMLElement('<extension/>')];
                $this->state = new \Joomla\Registry\Registry(['category.extension' => 'com_content']);
            }
            public function getDocument(): HTMLDocument
            {
                return $this->document;
            }
            public function escape($value): string
            {
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            }
            public function getLanguage(): \Joomla\CMS\Language\Language
            {
                return \Joomla\CMS\Factory::getLanguage();
            }
            public function getCurrentUser(): \Joomla\CMS\User\User
            {
                return new \Joomla\CMS\User\User();
            }
            public function getForm(): \Joomla\CMS\Form\Form
            {
                throw $this->stop;
            }
            public function render(string $path): void
            {
                include $path;
            }
        };
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $view->render(JPATH_ADMINISTRATOR . '/components/' . $component . '/tmpl/' . $template . '/edit.php');
            $this->fail('Expected the native business field boundary');
        } catch (\RuntimeException $exception) {
            $this->assertSame($stop, $exception, $exception->getMessage());
        } finally {
            $html = '';
            while (ob_get_level() > $bufferLevel) {
                $html = ob_get_clean() . $html;
            }
        }
        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html . '</form>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $output = new \DOMXPath($dom);
        $prefix = '//form[@id="' . $formId . '"]';
        foreach (['data-draft-feedback', 'data-draft-partial-recovery', 'data-draft-recovery'] as $attribute) {
            $this->assertSame(1, $output->query($prefix . '//*[@' . $attribute . ']')->length, $component . ': ' . $attribute);
        }
        $this->assertSame(1, $output->query($prefix . '//*[@data-draft-error and contains(@data-draft-error, "service_unavailable")]')->length);
        $this->assertSame(0, $output->query($prefix . '//*[@data-draft-ui]')->length, 'Status belongs in the toolbar');
    }

    public static function exceptionTemplates(): array
    {
        return [
            ['com_categories', 'category', 'item-form'],
            ['com_languages', 'override', 'override-form'],
            ['com_banners', 'banner', 'banner-form'],
            ['com_banners', 'client', 'client-form'],
            ['com_contact', 'contact', 'contact-form'],
            ['com_fields', 'field', 'item-form'],
            ['com_fields', 'group', 'item-form'],
            ['com_finder', 'filter', 'adminForm'],
            ['com_guidedtours', 'tour', 'guidedtours-form'],
            ['com_guidedtours', 'step', 'guidedtour-dates-form'],
            ['com_languages', 'language', 'language-form'],
            ['com_menus', 'item', 'item-form'],
            ['com_menus', 'menu', 'item-form'],
            ['com_modules', 'module', 'module-form'],
            ['com_newsfeeds', 'newsfeed', 'newsfeed-form'],
            ['com_redirect', 'link', 'link-form'],
            ['com_tags', 'tag', 'item-form'],
            ['com_templates', 'style', 'style-form'],
            ['com_users', 'group', 'group-form'],
            ['com_users', 'level', 'level-form'],
            ['com_users', 'note', 'note-form'],
            ['com_workflow', 'workflow', 'workflow-form'],
            ['com_workflow', 'stage', 'workflow-form'],
            ['com_workflow', 'transition', 'workflow-form'],
        ];
    }

    public function testSharedStatusStaysAfterActionsWhenNativeToolbarHasNoDivider(): void
    {
        [$view, $toolbar] = $this->toolbarView();
        $save             = $toolbar->save('field.save');
        $close            = $toolbar->cancel('field.cancel');
        $inline           = $toolbar->inlinehelp();
        $help             = $toolbar->help('Fields:_Edit');
        \Joomla\CMS\Autosave\DraftView::addStatus($toolbar, 'field-autosave-status');
        $items = $toolbar->getItems();
        $this->assertSame([$save, $close], \array_slice($items, 0, 2));
        $this->assertSame('autosave-status', $items[2]->getName());
        $this->assertSame([$inline, $help], \array_slice($items, 3));
    }

    public function testSpecificConflictMessagesExplainLocalRetentionAndExplicitRecovery(): void
    {
        $feedback = $this->output('feedback');
        $this->assertSame(0, $feedback->query('//*[@data-draft-conflict-status]')->length);
        $this->assertSame(1, $feedback->query('//*[@data-draft-error and contains(@data-draft-error, "scope_conflict")]')->length);
        $this->assertSame(1, $feedback->query('//*[@data-draft-error and contains(@data-draft-error, "base_revision_conflict")]')->length);
        $paused = $this->output('status')->query('//*[@data-draft-state="conflict"]')->item(0);
        $this->assertSame('Autosave paused', trim($paused->textContent));
        $this->assertTrue($paused->hasAttribute('hidden'));
        $this->assertSame(1, $this->output('recovery')->query('//*[@data-draft-action="review"]')->length);
    }

    /** @dataProvider nativeToolbarLifecycles */
    public function testActualNativeViewLifecycleMountsStatusAfterActions(string $component, string $entity, string $layout): void
    {
        $this->db = (new \Joomla\Database\DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'view_draft_']);
        $this->installSchema('postgresql');
        $user        = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturn(true);
        $user->method('getParam')->willReturn('UTC');
        $session = $this->createMock(\Joomla\Session\SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'user' ? $user : $default);
        $dispatcher = new \Joomla\Event\Dispatcher();
        $context    = $component . '.' . strtolower($entity);
        $name       = ucfirst(substr($component, 4));
        $prefix     = 'Joomla\\Component\\' . $name . '\\Administrator\\';
        $app        = $this->createMock(CMSApplication::class);
        $input      = new Input(['option' => $component, 'layout' => $layout]);
        $app->method('getInput')->willReturn($input);
        $app->method('getName')->willReturn('draftlifecycle');
        $app->method('getTemplate')->willReturnCallback(static fn ($params = false) => $params ? (object) ['template' => 'atum', 'parent' => '', 'params' => new \Joomla\Registry\Registry()] : 'atum');
        $app->method('isClient')->willReturnCallback(static fn ($client) => $client === 'administrator');
        $app->method('getClientId')->willReturn(1);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getSession')->willReturn($session);
        $app->method('getDispatcher')->willReturn($dispatcher);
        $app->method('getLanguage')->willReturn(Factory::getLanguage());
        $app->method('getUserState')->willReturnArgument(1);
        Factory::$application = $app;
        Factory::getContainer()->set('DraftlifecycleRouter', new \Joomla\CMS\Router\Router());
        $document = new HTMLDocument();
        $app->method('getDocument')->willReturn($document);
        Factory::$document = $document;
        $toolbarFactory    = new ContainerAwareToolbarFactory();
        $toolbarFactory->setContainer(new Container());
        $toolbar = new Toolbar('toolbar', $toolbarFactory);
        $document->setToolbar('toolbar', $toolbar);
        foreach (['system', 'com_autosave', $component] as $name) {
            $document->getWebAssetManager()->getRegistry()->addRegistryFile('media_source/' . $name . '/joomla.asset.json');
        }
        $form = new \Joomla\CMS\Form\Form('native.lifecycle', ['control' => 'jform']);
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $item = (object) ['id' => 42, 'catid' => 3, 'name' => 'Canonical', 'title' => 'Canonical', 'client_id' => 0,
            'type'             => 'component', 'xml' => new \SimpleXMLElement('<extension/>')];
        $provider = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $provider->method('getContext')->willReturn($context);
        $provider->method('describe')->willReturn(['context' => $context, 'target' => '42', 'scope' => [], 'version' => 1,
            'revision'                                       => 'canonical snapshot', 'values' => ['title' => 'Canonical'],
            'fields'                                         => ['title' => ['id' => 'jform_title', 'name' => 'jform[title]', 'control' => 'text', 'label' => 'Title']], 'excluded' => []]);
        $class     = $prefix . 'Extension\\' . ucfirst(substr($component, 4)) . 'Component';
        $extension = new $class($this->createMock(\Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface::class));
        $extension->setAutosaveProvider($context, $provider);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $service  = new \Joomla\CMS\Autosave\DraftService($resolver, $this->store);
        $autosave = new \Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent($service);
        $app->method('bootComponent')->willReturnCallback(static fn ($name) => $name === $component ? $extension : $autosave);
        $model = $this->createMock($prefix . 'Model\\' . $entity . 'Model');
        $model->method('getName')->willReturn(strtolower($entity));
        $model->method('getItem')->willReturn($item);
        $model->method('getForm')->willReturn($form);
        $model->method('getErrors')->willReturn([]);
        $model->method('getState')->willReturnCallback(static fn ($key = null, $default = null) => $key === null
            ? new \Joomla\Registry\Registry(['params' => [], 'item.menutypeid' => 12]) : $default);
        $model->expects($this->never())->method('save');
        $model->expects($this->never())->method('validate');
        if ($entity === 'Item') {
            $model->method('getModules')->willReturn([]);
            $model->method('getViewLevels')->willReturn([]);
        }
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
        $this->assertSame(['save', 'cancel', 'autosave-status', 'help'], $names);
        $this->assertTrue($document->getScriptOptions($component . '.autosave.' . strtolower($entity))['available']);
    }

    public static function nativeToolbarLifecycles(): array
    {
        return [
            ['com_menus', 'Item', 'edit'], ['com_menus', 'Item', 'modal'], ['com_menus', 'Menu', 'edit'],
            ['com_modules', 'Module', 'edit'], ['com_modules', 'Module', 'modal'],
            ['com_redirect', 'Link', 'edit'], ['com_tags', 'Tag', 'edit'],
            ['com_templates', 'Style', 'edit'], ['com_users', 'Note', 'edit'],
            ['com_contact', 'Contact', 'modal'],
        ];
    }

    private function toolbarView(): array
    {
        $factory = new ContainerAwareToolbarFactory();
        $factory->setContainer(new Container());
        $toolbar  = new Toolbar('toolbar', $factory);
        $document = new HTMLDocument();
        $document->setToolbar('toolbar', $toolbar);
        $view = (new \ReflectionClass(HtmlView::class))->newInstanceWithoutConstructor();
        $view->setDocument($document);
        $view->autosaveEnabled = true;

        return [$view, $toolbar];
    }

    public function testStatusFollowsNativeActionsBeforeUtilityDivider(): void
    {
        [$view, $toolbar]     = $this->toolbarView();
        $lastAction           = $toolbar->customHtml('<button type="button">Accessibility Check</button>', 'last-action');
        $divider              = $toolbar->divider();
        $utility              = $toolbar->customHtml('<button type="button">Help</button>', 'utility');
        $app                  = Factory::getApplication();
        $app->JComponentTitle = 'Native Article heading';
        (new \ReflectionMethod(HtmlView::class, 'addAutosaveStatus'))->invoke($view);
        $items = $toolbar->getItems();
        $this->assertSame($lastAction, $items[0]);
        $this->assertSame('autosave-status', $items[1]->getName());
        $this->assertSame($divider, $items[2]);
        $this->assertSame($utility, $items[3]);
        $this->assertSame('Native Article heading', $app->JComponentTitle);
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($items[1]->render());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $output = new \DOMXPath($document);
        $this->assertSame(1, $output->query('//*[@id="article-autosave-status" and @data-draft-ui]')->length);
    }

    public function testModalStatusFollowsCancelWithoutAnotherFormCopy(): void
    {
        [$view, $toolbar] = $this->toolbarView();
        $cancel           = $toolbar->customHtml('<button type="button">Cancel</button>', 'cancel');
        Factory::getApplication()->getInput()->set('tmpl', 'component');
        (new \ReflectionMethod(HtmlView::class, 'addAutosaveStatus'))->invoke($view);
        $items = $toolbar->getItems();
        $this->assertCount(2, $items);
        $this->assertSame($cancel, $items[0]);
        $this->assertSame('autosave-status', $items[1]->getName());
    }

    public function testRecoveryUsesNativeAlertButtonsAndReadOnlyComparisonTable(): void
    {
        $output = $this->output('recovery');
        $this->assertStringContainsString('alert-info', $output->query('//*[@data-draft-recovery]')->item(0)->getAttribute('class'));
        $this->assertSame(3, $output->query('//table/thead/tr/th[@scope="col"]')->length);
        $this->assertSame(0, $output->query('//textarea')->length);
        foreach (['restore' => 'btn-primary', 'review' => 'btn-secondary', 'keep' => 'btn-secondary', 'discard' => 'btn-danger'] as $action => $class) {
            $button = $output->query('//*[@data-draft-action="' . $action . '"]')->item(0);
            $this->assertSame('button', $button->getAttribute('type'));
            $this->assertStringContainsString($class, $button->getAttribute('class'));
        }
        $this->assertTrue($output->query('//*[@data-draft-comparison-container]')->item(0)->hasAttribute('hidden'));
    }
    public function testNativeConfigurationCannotOfferAnEnableSwitch(): void
    {
        $config = simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_autosave/config.xml');
        $this->assertSame([], $config->xpath('//field[@name="enabled"]'));
        $this->assertCount(1, $config->xpath('//field[@name="interval" and @default="30"]'));
    }
}
