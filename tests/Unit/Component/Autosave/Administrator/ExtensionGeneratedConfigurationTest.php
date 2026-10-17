<?php

namespace Joomla\Tests\Unit\Component\Autosave\Administrator;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveProvider;
use Joomla\Component\Templates\Administrator\Autosave\StyleAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class ExtensionGeneratedConfigurationTest extends UnitTestCase
{
    public function testPreparedParametersProtectOnlyDeclaredSupportedControls(): void
    {
        $form = new Form('extension', ['control' => 'jform']);
        $form->setCurrentUser(new User());
        $form->load('<form><fields name="params"><fieldset>'
            . '<field name="safe_text" type="text" autosave="true"/>'
            . '<field name="safe_boolean" type="checkbox" autosave="true"/>'
            . '<field name="safe_collection" type="list" multiple="true" autosave="true"><option value="a">A</option></field>'
            . '<field name="secret" type="text" sensitive="true" autosave="true"/>'
            . '<field name="undeclared" type="text"/>'
            . '<field name="upload" type="file" autosave="true"/>'
            . '<field name="nested" type="subform" autosave="true"/>'
            . '</fieldset></fields></form>');
        $fields = DraftProjection::describe($form, DraftProjection::groupKeys($form, 'params'));
        $this->assertSame(['params.safe_text', 'params.safe_boolean', 'params.safe_collection'], array_keys($fields));
        $this->assertSame(['text', 'checkbox', 'multiple'], array_column($fields, 'control'));
        $this->assertSame(
            ['params.safe_text' => '', 'params.safe_boolean' => '1', 'params.safe_collection' => ['a']],
            DraftProjection::normalize(['params.safe_text' => '', 'params.safe_boolean' => true, 'params.safe_collection' => ['a']], $fields)
        );
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




    /**
     * @dataProvider siteTemplateStyleForms
     */
    public function testSiteTemplateStyleFormsRespectExplicitParameterCoverage(string $template, bool $brandProtected): void
    {
        $form = new Form('style', ['control' => 'jform']);
        $form->setCurrentUser(new User());
        $this->assertTrue(
            $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_templates/forms/style.xml')
        );
        $this->assertTrue(
            $form->loadFile(JPATH_SITE . '/templates/' . $template . '/templateDetails.xml', false, '//config')
        );

        $fields = DraftProjection::describe($form, DraftProjection::groupKeys($form, 'params'));

        $this->assertSame($brandProtected, \array_key_exists('params.brand', $fields));
        $this->assertSame($brandProtected, \array_key_exists('params.systemFontBody', $fields));
    }

    public static function siteTemplateStyleForms(): array
    {
        return [
            'Cassiopeia'          => ['cassiopeia', true],
            'Cassiopeia Extended' => ['cassiopeia_extended', false],
        ];
    }
}
