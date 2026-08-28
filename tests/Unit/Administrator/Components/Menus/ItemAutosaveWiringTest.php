<?php

namespace Joomla\Tests\Unit\Administrator\Components\Menus;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\Component\Menus\Administrator\Controller\ItemController;
use Joomla\Tests\Unit\UnitTestCase;

class ItemAutosaveWiringTest extends UnitTestCase
{
    public function testControllerViewTemplateAndServiceUseExactContract(): void
    {
        $reflection = new \ReflectionClass(ItemController::class);
        $this->assertContains(AutosaveFormControllerTrait::class, $reflection->getTraitNames());
        $this->assertSame('com_menus.item', $reflection->getConstant('AUTOSAVE_CONTEXT'));
        $this->assertSame(['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new', 'save2copy' => 'save-copy'], $reflection->getConstant('AUTOSAVE_TASK_INTENTS'));

        $view = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/src/View/Item/HtmlView.php');
        $this->assertStringContainsString("disable('com_menus.autosave.item')", $view);
        $this->assertStringContainsString("'item-form'", $view);
        $this->assertStringContainsString("'com_menus.item-autosave'", $view);
        $this->assertStringContainsString("'fingerprint' => \$schema->fingerprint()", $view);

        $template = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/tmpl/item/edit.php');
        $this->assertStringContainsString('joomla.autosave.status', $template);
        $this->assertStringContainsString('joomla.autosave.recovery', $template);

        $service = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/services/provider.php');
        $this->assertStringContainsString('ItemAutosaveProvider', $service);
        $this->assertStringContainsString("Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_menus/forms')", $service);
    }
}
