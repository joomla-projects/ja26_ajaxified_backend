<?php

/**
 * @package   Joomla.UnitTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\HTMLDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\Component\Content\Administrator\View\Article\HtmlView;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\LoggerInterface;

class DraftLayoutTest extends UnitTestCase
{
    use DraftPresentationFixture;

    public function testUnavailablePluginDoesNotAllocateOrActivateDraftControls(): void
    {
        (new \ReflectionProperty(\Joomla\CMS\Plugin\PluginHelper::class, 'plugins'))->setValue(null, []);
        $application = Factory::$application;
        $application->expects($this->never())->method('bootComponent');
        $document = $this->document();
        $this->assertFalse(
            $this->draftView()->configure(
                $application,
                $document,
                $this->createMock(\Joomla\CMS\User\User::class),
                new \Joomla\CMS\Form\Form('native.plugin.unavailable'),
                (object) [],
                ['context' => 'com_content.article']
            )
        );
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $application->method('getLogger')->willReturn($logger);
        $form     = new \Joomla\CMS\Form\Form('native.missing.assets');
        $user     = $this->createMock(\Joomla\CMS\User\User::class);

        $this->assertFalse(
            $this->draftView()->configure(
                $application,
                $document,
                $user,
                $form,
                (object) [],
                [
                'context'   => 'com_content.article', 'formId' => 'item-form', 'statusId' => 'draft-status',
                'saveTasks' => ['article.save'],
                ]
            )
        );
        $this->assertSame([], $document->getScriptOptions('com_autosave.forms'));
    }

    public function testBrokenOptionalDependencyDoesNotActivateAnInvalidDocumentAsset(): void
    {
        $document = $this->document();
        $assets   = $document->getWebAssetManager();
        $assets->registerScript('native.available', 'native.js')->useScript('native.available');
        self::primeAutosaveAssetRegistry($assets->getRegistry());
        $assets->registerScript('com_autosave.draft-form', 'draft.js', [], [], ['draft.missing-dependency']);
        $this->assertFalse(
            $this->draftView()->configure(
                Factory::$application,
                $document,
                $this->createMock(\Joomla\CMS\User\User::class),
                new \Joomla\CMS\Form\Form('native.broken.asset'),
                (object) [],
                [
                'context' => 'com_content.article', 'formId' => 'broken-form',
                ]
            )
        );
        $this->assertSame(['native.available'], array_keys($assets->getAssets('script', true)));
    }

    /**
     * @dataProvider diagnosticLoggerFailures
     */
    public function testUnexpectedAssetFailureKeepsNativeValuesEvenIfLoggingFails(bool $loggerFails): void
    {
        $application = Factory::getApplication();
        $application->expects($this->never())->method('bootComponent');
        $logger  = $this->createMock(LoggerInterface::class);
        $warning = $logger->expects($this->once())->method('warning')->with(
            'Optional draft assets could not be prepared.',
            ['exception_class' => \Joomla\CMS\WebAsset\Exception\InvalidActionException::class]
        );
        if ($loggerFails) {
            $warning->willThrowException(new \RuntimeException('Private logger detail'));
        }
        $application->method('getLogger')->willReturn($logger);
        $document = $this->document();
        $document->getWebAssetManager()->lock();
        $form = new \Joomla\CMS\Form\Form('native.asset.failure', ['control' => 'jform']);
        $form->load('<form><fieldset><field name="title" type="text"/></fieldset></form>');
        $form->bind(['title' => 'Private local form value']);
        $this->assertFalse(
            $this->draftView()->configure(
                $application,
                $document,
                $this->createMock(\Joomla\CMS\User\User::class),
                $form,
                (object) [],
                [
                'context' => 'com_content.article', 'formId' => 'item-form', 'statusId' => 'draft-status', 'saveTasks' => ['article.save'],
                ]
            )
        );
        $this->assertSame('Private local form value', $form->getValue('title'));
        $this->assertSame([], $document->getScriptOptions('com_autosave.forms'));
    }

    public static function diagnosticLoggerFailures(): array
    {
        return [[false], [true]];
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

    public function testQuotaControlsAreOptionalAndCannotSubmitTheNativeForm(): void
    {
        $output = $this->output('feedback');
        $this->assertSame(1, $output->query('//*[@data-draft-quota]')->length);
        $this->assertTrue($output->query('//*[@data-draft-quota]')->item(0)->hasAttribute('hidden'));
        $button = $output->query('//*[@data-draft-quota-discard]')->item(0);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertStringContainsString('since this list was shown', $button->getAttribute('data-confirm-message'));
        $this->assertSame(0, $this->output('status')->query('//*[@data-draft-quota]')->length);
    }

    /**
     * @dataProvider exceptionTemplates
     */
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
        [, $toolbar]      = $this->toolbarView();
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
