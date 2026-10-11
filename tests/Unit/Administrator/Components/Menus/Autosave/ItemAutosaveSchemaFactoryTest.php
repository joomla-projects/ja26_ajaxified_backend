<?php

namespace Joomla\Tests\Unit\Administrator\Components\Menus\Autosave;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveSchemaFactory;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

class ItemAutosaveSchemaFactoryTest extends UnitTestCase
{
    public function testRealRoutedFormsProduceIsolatedBoundedSchemas(): void
    {
        $previous            = Factory::$application;
        $application         = $this->createMock(CMSApplication::class);
        $application->method('getIdentity')->willReturn($this->createMock(User::class));
        $application->method('getConfig')->willReturn(new Registry());
        Factory::$application = $application;

        try {
            $factory   = new ItemAutosaveSchemaFactory();
            $url       = $this->form('item_url.xml');
            $separator = $this->form('item_separator.xml');
            $component = $this->form('item_component.xml');
            $article   = $this->componentForm(JPATH_SITE . '/components/com_content/tmpl/article/default.xml');

            $urlSchema       = $factory->fromForm($url);
            $separatorSchema = $factory->fromForm($separator);
            $componentSchema = $factory->fromForm($component);
            $articleSchema   = $factory->fromForm($article);
            $urlNames        = array_column(array_column($urlSchema->fields(), 'path'), 1);
            $articleFields   = array_column($articleSchema->fields(), null, 'id');

            $this->assertContains('menu-anchor_title', $urlNames);
            $this->assertContains('menu-anchor_rel', $urlNames);
            $this->assertNotContains('menu_image', $urlNames);
            $this->assertNotSame($urlSchema->fingerprint(), $separatorSchema->fingerprint());
            $this->assertLessThanOrEqual(32, \count($urlSchema->fields()));
            $this->assertNotSame([], $componentSchema->fields());
            $this->assertNotSame([], $articleSchema->fields());
            $this->assertContains('', $articleFields['jform_params_show_title']['values']);
        } finally {
            Factory::$application = $previous;
        }
    }

    public function testInvalidRoutedFieldsAreOmittedWithoutRemovingSafeSiblings(): void
    {
        $tooManyOptions = '';

        for ($i = 0; $i <= AutosaveDynamicSchema::MAXIMUM_ENUM_VALUES; $i++) {
            $tooManyOptions .= '<option value="value' . $i . '">Value</option>';
        }

        $form = new Form('com_menus.item', ['control' => 'jform']);
        $form->load(
            '<form><fields name="params"><fieldset name="basic">'
            . '<field name="safe_text" type="text" maxlength="20"/>'
            . '<field name="vendor_widget" type="VendorCustom"/>'
            . '<field name="private_key" type="text"/>'
            . '<field name="upload_reference" type="text"/>'
            . '<field name="nested" type="subform"/>'
            . '<field name="too_many" type="list">' . $tooManyOptions . '</field>'
            . '<field name="too_long" type="list"><option value="' . str_repeat('x', 129) . '">Long</option></field>'
            . '<field name="safe_enum" type="list"><option value="one">One</option><option value="two">Two</option></field>'
            . '</fieldset></fields></form>'
        );

        $schema = (new ItemAutosaveSchemaFactory())->fromForm($form);

        $this->assertSame(['safe_enum', 'safe_text'], array_column(array_column($schema->fields(), 'path'), 1));
        $this->assertSame(['status' => 'partial', 'reasons' => ['unsupported_control'], 'parameterless' => false], $schema->support());
    }

    public function testFieldLimitDoesNotProduceAnOrderDependentRoutedSubset(): void
    {
        $fields = '';

        for ($i = 0; $i <= AutosaveDynamicSchema::MAXIMUM_FIELDS; $i++) {
            $fields .= '<field name="safe' . $i . '" type="text"/>';
        }

        $form = new Form('com_menus.item', ['control' => 'jform']);
        $form->load('<form><fields name="params"><fieldset name="basic">' . $fields . '</fieldset></fields></form>');

        $schema = (new ItemAutosaveSchemaFactory())->fromForm($form);
        $this->assertSame([], $schema->fields());
        $this->assertSame(['status' => 'unsupported', 'reasons' => ['schema_too_large'], 'parameterless' => false], $schema->support());
    }

    public function testParameterlessRouteIsNotReportedAsUnsupported(): void
    {
        $form = new Form('com_menus.item', ['control' => 'jform']);
        $form->load('<form><fields name="params"><fieldset name="basic" /></fields></form>');

        $this->assertSame(
            ['status' => 'supported', 'reasons' => ['parameterless'], 'parameterless' => true],
            (new ItemAutosaveSchemaFactory())->fromForm($form)->support()
        );
    }

    private function form(string $file): Form
    {
        $form = new Form('com_menus.item', ['control' => 'jform']);
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_menus/forms/item.xml');
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_menus/forms/' . $file, true, false);

        return $form;
    }

    private function componentForm(string $metadata): Form
    {
        $form = new Form('com_menus.item', ['control' => 'jform']);
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_menus/forms/item.xml');
        $form->loadFile($metadata, true, '/metadata');
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_menus/forms/item_component.xml', true, false);

        return $form;
    }
}
