<?php

namespace Joomla\Tests\Unit\Administrator\Components\Fields;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\Component\Fields\Administrator\Controller\FieldController;
use Joomla\Tests\Unit\UnitTestCase;

class FieldAutosaveWiringTest extends UnitTestCase
{
    public function testControllerViewAndTemplateUseExactContract(): void
    {
        $reflection = new \ReflectionClass(FieldController::class);
        $this->assertContains(AutosaveFormControllerTrait::class, $reflection->getTraitNames());
        $this->assertSame('com_fields.field', $reflection->getConstant('AUTOSAVE_CONTEXT'));
        $this->assertSame(['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new', 'save2copy' => 'save-copy'], $reflection->getConstant('AUTOSAVE_TASK_INTENTS'));
        $view = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_fields/src/View/Field/HtmlView.php');
        $this->assertStringContainsString("disable('com_fields.autosave.field')", $view);
        $this->assertStringContainsString("'item-form'", $view);
        $this->assertStringContainsString("['fields' => \$schema->fields(), 'fingerprint' => \$schema->fingerprint(), 'support' => \$schema->support()]", $view);
        $this->assertStringContainsString("'com_fields.field-autosave'", $view);
        $template = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_fields/tmpl/field/edit.php');
        $this->assertStringContainsString('joomla.autosave.status', $template);
        $this->assertStringContainsString('joomla.autosave.recovery', $template);
    }

    public function testFieldAutosaveLoadsAfterTheNativeSubformElement(): void
    {
        $manifest = json_decode(
            file_get_contents(JPATH_ROOT . '/media_source/com_fields/joomla.asset.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $assets   = array_column($manifest['assets'], null, 'name');

        $this->assertContains(
            'webcomponent.field-subform',
            $assets['com_fields.field-autosave']['dependencies'],
            'Option-row autosave must not reconcile before joomla-field-subform is upgraded.'
        );
    }
}
