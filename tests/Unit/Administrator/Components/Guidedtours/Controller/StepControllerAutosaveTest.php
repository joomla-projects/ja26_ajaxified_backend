<?php

namespace Joomla\Tests\Unit\Administrator\Components\Guidedtours\Controller;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\Component\Guidedtours\Administrator\Controller\StepController;
use Joomla\Tests\Unit\UnitTestCase;

class StepControllerAutosaveTest extends UnitTestCase
{
    public function testStandardTraitWiringAndTasks(): void
    {
        $reflection = new \ReflectionClass(StepController::class);
        $this->assertContains(AutosaveFormControllerTrait::class, class_uses(StepController::class));
        $this->assertSame(['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new', 'save2copy' => 'save-copy'], $reflection->getConstant('AUTOSAVE_TASK_INTENTS'));
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_guidedtours/src/Controller/StepController.php');
        $this->assertStringContainsString("captureAutosaveCanonicalResult(\$model, 'step.id')", $source);
    }
}
