<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveProvider;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveSchemaFactory;
use Joomla\Component\Modules\Administrator\Controller\ModuleController;
use Joomla\Component\Templates\Administrator\Autosave\StyleAutosaveProvider;
use Joomla\Component\Templates\Administrator\Autosave\StyleAutosaveSchemaFactory;
use Joomla\Component\Templates\Administrator\Controller\StyleController;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class ExtensionGeneratedConfigurationTest extends UnitTestCase
{
    /**
     * @dataProvider invalidTargetCases
     */
    public function testProvidersRejectNoncanonicalExistingRecordTargets(string $target): void
    {
        $schema = new AutosaveDynamicSchema([]);
        $db     = $this->databaseReturning((object) ['id' => 1, 'checked_out' => 0]);

        $providers = [
            new ModuleAutosaveProvider($db, static fn () => $schema),
            new StyleAutosaveProvider($db, static fn () => $schema),
        ];

        foreach ($providers as $provider) {
            try {
                $provider->canonicalizeTargetId($target);
                $this->fail('A noncanonical target was accepted.');
            } catch (\Joomla\CMS\Autosave\AutosaveException $exception) {
                $this->assertSame('invalid_target', $exception->getErrorCode());
            }
        }
    }

    public static function invalidTargetCases(): array
    {
        return [
            'new record'   => ['0'],
            'negative'     => ['-1'],
            'leading zero' => ['01'],
            'whitespace'   => [' 1'],
            'decimal'      => ['1.0'],
            'overflow'     => ['2147483648'],
        ];
    }

    public function testFactoriesAreBoundedAndExcludeSensitiveExtensionFields(): void
    {
        $form = new Form('extension', ['control' => 'jform']);
        $form->load('<form><fields name="params"><fieldset name="basic"><field name="colour" type="text" maxlength="20"/><field name="mode" type="list"><option value="a">A</option><option value="b">B</option></field><field name="api_token" type="text"/><field name="upload" type="file"/><field name="nested" type="subform"/></fieldset></fields></form>');
        $module = (new ModuleAutosaveSchemaFactory())->fromForm($form);
        $style  = (new StyleAutosaveSchemaFactory())->fromForm($form);
        $this->assertSame(['colour', 'mode'], array_column(array_column($module->fields(), 'path'), 1));
        $this->assertSame($module->fields(), $style->fields());
        $this->assertSame($module->fingerprint(), $style->fingerprint());
    }

    public function testProvidersRejectCrossExtensionFingerprintsAndUnknownParams(): void
    {
        $schemaA = new AutosaveDynamicSchema([['path' => ['params', 'colour'], 'id' => 'jform_params_colour', 'kind' => 'string', 'maxLength' => 20]]);
        $schemaB = new AutosaveDynamicSchema([['path' => ['params', 'layout'], 'id' => 'jform_params_layout', 'kind' => 'string', 'maxLength' => 20]]);
        $module  = new ModuleAutosaveProvider(
            $this->databaseReturning((object) ['id' => 7, 'checked_out' => 0]),
            static fn () => $schemaA
        );
        $payload = [
            'title'             => 'Title',
            'note'              => '',
            'version_note'      => '',
            'showtitle'         => '1',
            'position'          => 'sidebar',
            'content'           => '',
            'schemaFingerprint' => $schemaA->fingerprint(),
            'params'            => ['colour' => 'blue'],
        ];
        $this->assertSame($payload, $module->normalizePayloadForTarget('7', $payload, 1));
        $this->expectException(\Joomla\CMS\Autosave\AutosaveException::class);
        $module->normalizePayloadForTarget(
            '7',
            array_replace(
                $payload,
                ['schemaFingerprint' => $schemaB->fingerprint(), 'params' => ['layout' => 'x']]
            ),
            1
        );
    }

    public function testTemplateStyleProviderAcceptsOnlyItsExactCurrentSchema(): void
    {
        $schema = new AutosaveDynamicSchema([
            [
                'path'      => ['params', 'colour'],
                'id'        => 'jform_params_colour',
                'kind'      => 'string',
                'maxLength' => 20,
            ],
        ]);
        $provider = new StyleAutosaveProvider(
            $this->databaseReturning((object) ['id' => 11]),
            static fn () => $schema
        );
        $payload = [
            'title'             => 'Cassiopeia',
            'schemaFingerprint' => $schema->fingerprint(),
            'params'            => ['colour' => 'blue'],
        ];

        $this->assertSame($payload, $provider->normalizePayloadForTarget('11', $payload, 1));

        $this->expectException(\Joomla\CMS\Autosave\AutosaveException::class);
        $provider->normalizePayloadForTarget('11', $payload + ['template' => 'other'], 1);
    }

    /**
     * @dataProvider serviceProviderCases
     */
    public function testActualComponentServicesExposeTheirExactProvider(
        string $component,
        string $context,
        string $providerClass
    ): void {
        $container = new Container();
        (require JPATH_ADMINISTRATOR . '/components/' . $component . '/services/provider.php')->register($container);
        $container->set(
            ComponentDispatcherFactoryInterface::class,
            $this->createMock(ComponentDispatcherFactoryInterface::class)
        );
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());

        $extension = $container->get(ComponentInterface::class);

        $this->assertInstanceOf(AutosaveServiceInterface::class, $extension);
        $this->assertSame([$context => true], $extension->getAutosaveContexts());
        $this->assertInstanceOf($providerClass, $extension->getAutosaveProvider($context));
    }

    public static function serviceProviderCases(): array
    {
        return [
            'module' => ['com_modules', 'com_modules.module', ModuleAutosaveProvider::class],
            'style'  => ['com_templates', 'com_templates.style', StyleAutosaveProvider::class],
        ];
    }

    public function testControllersUseCanonicalTraitAndProductionWiringIsLiteral(): void
    {
        $moduleController = new \ReflectionClass(ModuleController::class);
        $styleController  = new \ReflectionClass(StyleController::class);
        $this->assertContains(AutosaveFormControllerTrait::class, $moduleController->getTraitNames());
        $this->assertContains(AutosaveFormControllerTrait::class, $styleController->getTraitNames());
        $this->assertSame('com_modules.module', $moduleController->getConstant('AUTOSAVE_CONTEXT'));
        $this->assertSame(
            ['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new', 'save2copy' => 'save-copy'],
            $moduleController->getConstant('AUTOSAVE_TASK_INTENTS')
        );
        $this->assertSame('com_templates.style', $styleController->getConstant('AUTOSAVE_CONTEXT'));
        $this->assertSame(
            ['apply' => 'apply', 'save' => 'save-exit', 'save2copy' => 'save-copy'],
            $styleController->getConstant('AUTOSAVE_TASK_INTENTS')
        );
        $moduleView = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_modules/src/View/Module/HtmlView.php');
        $styleView  = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_templates/src/View/Style/HtmlView.php');
        $this->assertStringContainsString("'com_modules.module-autosave'", $moduleView);
        $this->assertStringContainsString("'com_templates.style-autosave'", $styleView);
    }

    public function testModulePreserveResolverUsesTheAuthoritativeNativeFormDirectory(): void
    {
        $service = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_modules/services/provider.php');

        $this->assertStringContainsString(
            "JPATH_ADMINISTRATOR . '/components/com_modules/forms'",
            $service
        );
        $this->assertStringNotContainsString('/components/com_modules/models/forms', $service);
        $this->assertFileExists(JPATH_ADMINISTRATOR . '/components/com_modules/forms/module.xml');
        $this->assertFileExists(JPATH_ADMINISTRATOR . '/components/com_modules/forms/moduleadmin.xml');
    }

    public function testTemplateStyleProviderUsesOnlyColumnsInTheAuthoritativeTable(): void
    {
        $provider = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/com_templates/src/Autosave/StyleAutosaveProvider.php'
        );
        $mysql = file_get_contents(JPATH_INSTALLATION . '/sql/mysql/base.sql');
        $table = substr(
            $mysql,
            strpos($mysql, 'CREATE TABLE IF NOT EXISTS `#__template_styles`'),
            strpos($mysql, ') ENGINE=', strpos($mysql, 'CREATE TABLE IF NOT EXISTS `#__template_styles`'))
                - strpos($mysql, 'CREATE TABLE IF NOT EXISTS `#__template_styles`')
        );

        $this->assertStringNotContainsString("'checked_out'", $provider);
        $this->assertStringNotContainsString('`checked_out`', $table);
        $this->assertStringContainsString("'params'", $provider);
    }

    /**
     * @dataProvider siteTemplateStyleForms
     */
    public function testSiteTemplateStyleFormsProduceAnAutosaveSchema(string $template): void
    {
        $form = new Form('style', ['control' => 'jform']);
        $this->assertTrue(
            $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_templates/forms/style.xml')
        );
        $this->assertTrue(
            $form->loadFile(JPATH_SITE . '/templates/' . $template . '/templateDetails.xml', false, '//config')
        );

        $schema = (new StyleAutosaveSchemaFactory())->fromForm($form);

        $names = array_column(array_column($schema->fields(), 'path'), 1);

        $this->assertNotSame('', $schema->fingerprint());
        $this->assertContains('brand', $names);
        $this->assertNotContains('systemFontBody', $names);
    }

    public static function siteTemplateStyleForms(): array
    {
        return [
            'Cassiopeia'          => ['cassiopeia'],
            'Cassiopeia Extended' => ['cassiopeia_extended'],
        ];
    }

    private function databaseReturning(object $record): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($record);

        return $db;
    }
}
