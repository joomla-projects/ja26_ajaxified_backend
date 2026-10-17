<?php

/**
 * @package     Joomla.IntegrationTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

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
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Actual native layout output and Web Asset Manager dependency resolution.
 */
class DraftPresentationTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;

    use \Joomla\Tests\Integration\Libraries\Cms\Autosave\DraftDatabaseFixture;

    public function testUnavailablePluginDoesNotAllocateOrActivateDraftControls(): void
    {
        (new \ReflectionProperty(\Joomla\CMS\Plugin\PluginHelper::class, 'plugins'))->setValue(null, []);
        $application = Factory::$application;
        $application->expects($this->never())->method('bootComponent');
        $document = $this->document();
        $this->assertFalse($this->draftView()->configure(
            $application,
            $document,
            $this->createMock(\Joomla\CMS\User\User::class),
            new \Joomla\CMS\Form\Form('native.plugin.unavailable'),
            (object) [],
            ['context' => 'com_content.article']
        ));
        $this->assertSame([], $document->getWebAssetManager()->getAssets('script', true));
    }

    public function testMissingOptionalScriptLeavesNativeDisplayAvailable(): void
    {
        $application = Factory::$application;
        $application->expects($this->never())->method('bootComponent');
        $document = $this->document();
        $registry = $document->getWebAssetManager()->getRegistry();
        self::primeAutosaveAssetRegistry($registry);
        $registry->remove('script', 'com_autosave.draft-form');
        $form     = new \Joomla\CMS\Form\Form('native.missing.assets');
        $user     = $this->createMock(\Joomla\CMS\User\User::class);

        $this->assertFalse($this->draftView()->configure($application, $document, $user, $form, (object) [], [
            'context'   => 'com_content.article', 'formId' => 'item-form', 'statusId' => 'draft-status',
            'saveTasks' => ['article.save'],
        ]));
        $this->assertSame([], $document->getScriptOptions('com_autosave.forms'));
    }

    public function testBrokenOptionalDependencyDoesNotActivateAnInvalidDocumentAsset(): void
    {
        $document = $this->document();
        $assets   = $document->getWebAssetManager();
        $assets->registerScript('native.available', 'native.js')->useScript('native.available');
        self::primeAutosaveAssetRegistry($assets->getRegistry());
        $assets->registerScript('com_autosave.draft-form', 'draft.js', [], [], ['draft.missing-dependency']);
        $this->assertFalse($this->draftView()->configure(Factory::$application, $document, $this->createMock(\Joomla\CMS\User\User::class), new \Joomla\CMS\Form\Form('native.broken.asset'), (object) [], [
            'context' => 'com_content.article', 'formId' => 'broken-form',
        ]));
        $this->assertSame(['native.available'], array_keys($assets->getAssets('script', true)));
    }

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
        $language->load('plg_system_autosave', JPATH_PLUGINS . '/system/autosave');
        Factory::$language = $language;
        $app               = $this->createMock(CMSApplication::class);
        $app->method('getName')->willReturn('draftpresentationtest');
        $app->method('isClient')->willReturnCallback(static fn (string $client): bool => $client === 'administrator');
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

    public function testSharedAssetUsesCoreEditorAndDialogDependenciesWithoutAnAutosaveImportMap(): void
    {
        $registry = new WebAssetRegistry();
        self::registerSourceAssetManifests($registry, ['system', 'com_autosave']);
        $manager = new WebAssetManager($registry);
        $manager->useScript('com_autosave.draft-form');
        $names = array_keys($manager->getAssets('script', true));
        $this->assertContains('com_autosave.draft-form', $names);
        $this->assertContains('editor-api', $names);
        $this->assertContains('joomla.dialog', $names);
        $this->assertFalse((bool) $registry->get('script', 'com_autosave.draft-form')->getOption('importmap'));
    }

    private static function registerSourceAssetManifests(WebAssetRegistry $registry, array $names): void
    {
        foreach ($names as $name) {
            $registry->addRegistryFile('media_source/' . $name . '/joomla.asset.json');
        }
    }

    private static function primeAutosaveAssetRegistry(WebAssetRegistry $registry): void
    {
        $registry->addRegistryFile('media/com_autosave/joomla.asset.json');
        self::registerSourceAssetManifests($registry, ['com_autosave']);
        $registry->get('script', 'com_autosave.draft-form');
    }

    private function document(): HTMLDocument
    {
        $registry = new WebAssetRegistry();
        $registry->addRegistryFile('media/com_autosave/joomla.asset.json');

        self::registerSourceAssetManifests($registry, ['system', 'com_autosave']);

        return new HTMLDocument(['webAssetManager' => new WebAssetManager($registry)]);
    }

    private function draftView(): \Joomla\CMS\Autosave\DraftView
    {
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver(Factory::getApplication());
        return new \Joomla\CMS\Autosave\DraftView(
            new \Joomla\CMS\Autosave\DraftService($resolver, new \Joomla\CMS\Autosave\DraftStore($this->createMock(\Joomla\Database\DatabaseInterface::class))),
            $resolver
        );
    }

    /** @dataProvider storageAvailability */
    public function testSharedViewOpensOnlyMetadataAndKeepsNativeErrorValues(bool $available, int $interval, int $expectedInterval): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        (new \ReflectionProperty(\Joomla\CMS\Plugin\PluginHelper::class, 'plugins'))->setValue(null, [
            (object) ['type' => 'system', 'name' => 'autosave', 'params' => json_encode(['interval' => $interval]), 'id' => 1],
        ]);
        $application = $this->createMock(CMSApplication::class);
        $application->method('getName')->willReturn('draftviewtest');
        $application->method('isClient')->willReturnCallback(static fn (string $client): bool => $client === 'administrator');
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
        $helper->configure($application, $document, $user, $form, $item, [
            'context' => 'com_categories.category', 'formId' => 'item-form', 'statusId' => 'category-autosave-status',

            'saveTasks' => ['category.apply', 'category.save'],
        ]);
        $configuration = $document->getScriptOptions('com_autosave.forms')['item-form'];
        $this->assertSame($expectedInterval, $configuration['interval']);
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

    public function testSharedOptionsKeepSeparateFormsAndDoNotAllocateTheSameFormTwice(): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $app      = Factory::getApplication();
        $provider = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $provider->method('getContext')->willReturn('com_content.article');
        $provider->expects($this->exactly(2))->method('describe')->willReturn([
            'context'  => 'com_content.article', 'target' => '42', 'scope' => [], 'version' => 1,
            'revision' => 'saved item', 'values' => ['title' => 'Saved'], 'fields' => ['title' => ['control' => 'text', 'label' => 'Title']], 'excluded' => [],
        ]);
        $component = new \Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent(['com_content.article' => true]);
        $component->setAutosaveProvider('com_content.article', $provider);
        $app->method('bootComponent')->willReturn($component);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $helper   = new \Joomla\CMS\Autosave\DraftView(new \Joomla\CMS\Autosave\DraftService($resolver, $this->store), $resolver);
        $user     = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id = 7;
        $user->method('getParam')->willReturn('UTC');
        $document = $this->document();
        Factory::getContainer()->set('DraftpresentationtestRouter', new \Joomla\CMS\Router\Router());
        $app->method('getDocument')->willReturn($document);
        $form = new \Joomla\CMS\Form\Form('two.forms');
        foreach (['first', 'second'] as $id) {
            $this->assertTrue($helper->configure($app, $document, $user, $form, (object) [], [
                'context' => 'com_content.article', 'formId' => $id, 'statusId' => $id . '-status', 'saveTasks' => ['article.save'],
            ]));
        }
        $before = $document->getScriptOptions('com_autosave.forms');
        $this->assertNotSame($before['first']['draftId'], $before['second']['draftId']);
        $this->assertFalse($helper->configure($app, $document, $user, $form, (object) [], ['formId' => 'first']));
        $this->assertSame($before, $document->getScriptOptions('com_autosave.forms'));
        $this->assertSame(2, (int) $this->db->setQuery('SELECT COUNT(*) FROM #__autosave_drafts')->loadResult());
    }

    public static function storageAvailability(): array
    {
        return [[true, 45, 45000], [false, 45, 45000], [true, 0, 1000]];
    }


    /** @dataProvider exceptionTemplates */
    public function testNativeExceptionTemplateSuppliesCompleteDraftMounts(string $component, string $template, string $formId): void
    {
        $assets = new WebAssetManager(new WebAssetRegistry());
        // These native widget assets are outside the draft mount boundary and need no built media.
        $assets->registerStyle('webcomponent.joomla-tab', '');
        $assets->registerScript('webcomponent.joomla-tab', '');
        $assets->registerScript('awesomplete', '');
        $document          = new HTMLDocument(['webAssetManager' => $assets]);
        Factory::$document = $document;
        self::registerSourceAssetManifests(
            $document->getWebAssetManager()->getRegistry(),
            ['system', 'com_autosave', $component]
        );
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
    public function testActualNativeViewLifecycleMountsStatusAfterActions(string $component, string $entity, string $layout, bool $assetsAvailable = true): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
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
        $document = $this->document();
        $app->method('getDocument')->willReturn($document);
        Factory::$document = $document;
        $toolbarFactory    = new ContainerAwareToolbarFactory();
        $toolbarFactory->setContainer(new Container());
        $toolbar = new Toolbar('toolbar', $toolbarFactory);
        $document->setToolbar('toolbar', $toolbar);
        self::registerSourceAssetManifests(
            $document->getWebAssetManager()->getRegistry(),
            ['system', 'com_autosave', $component]
        );
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
        $provider->method('describe')->willReturn(['context' => $context, 'target' => '42', 'scope' => [], 'version' => 1,
            'revision'                                       => 'canonical snapshot', 'values' => ['title' => 'Canonical'],
            'fields'                                         => ['title' => ['id' => 'jform_title', 'name' => 'jform[title]', 'control' => 'text', 'label' => 'Title']], 'excluded' => []]);
        $class     = $prefix . 'Extension\\' . ucfirst(substr($component, 4)) . 'Component';
        $extension = new $class($this->createMock(\Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface::class));
        $extension->setAutosaveProvider($context, $provider);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $service  = new \Joomla\CMS\Autosave\DraftService($resolver, $this->store);
        $this->registerDraftService($service, true, $resolver);
        $app->method('bootComponent')->willReturn($extension);
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

    /** @dataProvider sharedNativeViews */
    public function testEveryNativeViewUsesTheSharedServiceAndKeepsItsSaveTasks(string $component, string $entity, string $context, string $formId, array $tasks, bool $serviceAvailable): void
    {
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $document = $this->document();
        $factory  = new ContainerAwareToolbarFactory();
        $factory->setContainer(new Container());
        $document->setToolbar('toolbar', new Toolbar('toolbar', $factory));
        $app      = Factory::getApplication();
        $app->method('getDocument')->willReturn($document);
        $user     = $this->createMock(\Joomla\CMS\User\User::class);
        $user->id = 7;
        $user->method('getParam')->willReturn('UTC');
        $provider = $this->createMock(\Joomla\CMS\Autosave\DraftProviderInterface::class);
        $provider->method('getContext')->willReturn($context);
        $provider->expects($serviceAvailable ? $this->once() : $this->never())->method('describe')->willReturn([
            'context'  => $context, 'target' => '42', 'scope' => [], 'version' => 1,
            'revision' => 'saved item', 'values' => ['title' => 'Saved'], 'fields' => ['title' => ['control' => 'text', 'label' => 'Title']], 'excluded' => [],
        ]);
        $extension = new \Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\ResolverTestCapableComponent([$context => true]);
        $extension->setAutosaveProvider($context, $provider);
        $app->method('bootComponent')->with($component)->willReturn($extension);
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver($app, static fn (): bool => true);
        $this->registerDraftService(new \Joomla\CMS\Autosave\DraftService($resolver, $this->store), $serviceAvailable, $resolver);
        Factory::getContainer()->set('DraftpresentationtestRouter', new \Joomla\CMS\Router\Router());
        $class = 'Joomla\\Component\\' . ucfirst(substr($component, 4)) . '\\Administrator\\View\\' . $entity . '\\HtmlView';
        $view  = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['getCurrentUser'])->getMock();
        $view->method('getCurrentUser')->willReturn($user);
        $view->setDocument($document);
        $view->setLayout('edit');
        $form = new \Joomla\CMS\Form\Form('shared.native.form');
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $form->bind(['title' => 'Local validation-error value']);
        $item  = (object) ['id' => 42, 'title' => 'Saved', 'workflow_id' => 12, 'tour_id' => 12, 'context' => 'com_content.article', 'client_id' => 0];
        $state = new \Joomla\Registry\Registry(['category.extension' => 'com_content', 'filter.extension' => 'com_content.article', 'field.context' => 'com_content.article', 'filter.context' => 'com_content.article', 'filter.client' => 'administrator', 'filter.language' => 'en-GB']);
        foreach (['form' => $form, 'item' => $item, 'state' => $state] as $property => $value) {
            (new \ReflectionProperty($class, $property))->setValue($view, $value);
        }
        if ($serviceAvailable) {
            $this->assertInstanceOf(\Joomla\CMS\Autosave\DraftView::class, Factory::getContainer()->get(\Joomla\CMS\Autosave\DraftView::class));
        }
        (new \ReflectionMethod($class, 'prepareAutosave'))->invoke($view);
        $this->assertSame('Local validation-error value', $form->getValue('title'));
        $this->assertSame($serviceAvailable, $view->autosaveEnabled);
        $options = $document->getScriptOptions('com_autosave.forms');
        if ($serviceAvailable) {
            $this->assertSame([$formId], array_keys($options));
            $this->assertTrue($options[$formId]['available']);
            $this->assertSame($context, $options[$formId]['context']);
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

    private function toolbarView(): array
    {
        $factory = new ContainerAwareToolbarFactory();
        $factory->setContainer(new Container());
        $toolbar  = new Toolbar('toolbar', $factory);
        $document = $this->document();
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
        $config = simplexml_load_file(JPATH_PLUGINS . '/system/autosave/autosave.xml');
        $this->assertSame([], $config->xpath('//field[@name="enabled"]'));
        $this->assertCount(1, $config->xpath('//field[@name="interval" and @default="30"]'));
    }
}
