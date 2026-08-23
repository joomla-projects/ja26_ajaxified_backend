<?php

namespace Joomla\Tests\Unit\Administrator\Components\Menus\Controller;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\Component\Menus\Administrator\Controller\MenuController;
use Joomla\Tests\Unit\UnitTestCase;

class MenuControllerAutosaveTest extends UnitTestCase
{
    public function testCustomSaveDelegationAndExactTasks(): void
    {
        $reflection = new \ReflectionClass(MenuController::class);
        $this->assertContains(AutosaveFormControllerTrait::class, class_uses(MenuController::class));
        $this->assertSame(['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new'], $reflection->getConstant('AUTOSAVE_TASK_INTENTS'));
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/src/Controller/MenuController.php');
        $this->assertStringContainsString('executeAutosaveCanonicalSave(', $source);
        $this->assertStringContainsString('executeMenuSave($key, $urlVar)', $source);
        $this->assertStringContainsString("captureAutosaveCanonicalResult(\$model, 'menu.id')", $source);
        $this->assertStringContainsString('MenusHelper::installPreset', $source);
        $this->assertStringNotContainsString("'save2copy' =>", $source);
    }
}
