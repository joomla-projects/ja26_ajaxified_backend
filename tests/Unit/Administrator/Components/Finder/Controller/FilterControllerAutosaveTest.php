<?php

namespace Joomla\Tests\Unit\Administrator\Components\Finder\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Component\Finder\Administrator\Controller\FilterController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

class FilterControllerAutosaveTest extends UnitTestCase
{
    public function testCustomNativeSaveUsesExactCanonicalTasksAndSharedDelegate(): void
    {
        $this->assertSame([
            'apply'     => 'apply',
            'save'      => 'save-exit',
            'save2new'  => 'save-new',
            'save2copy' => 'save-copy',
        ], (new \ReflectionClassConstant(FilterController::class, 'AUTOSAVE_TASK_INTENTS'))->getValue());
        $this->assertContains(AutosaveFormControllerTrait::class, class_uses(FilterController::class));

        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_finder/src/Controller/FilterController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('executeAutosaveCanonicalSave(', $source);
        $this->assertStringContainsString("captureAutosaveCanonicalResult(\$model, 'filter.id')", $source);
    }

    public function testAuthoritativeSavedIdentityFinalizesOnlyOnce(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation',
            'com_finder.filter',
            '42',
            'save-copy',
            '84',
            $this->anything()
        );
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('filter.id')->willReturn(84);

        $controller = (new \ReflectionClass(FilterController::class))->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());
        (new \ReflectionProperty(BaseController::class, 'app'))->setValue($controller, $app);
        (new \ReflectionProperty(BaseController::class, 'input'))->setValue($controller, new Input());
        (new \ReflectionProperty(FilterController::class, 'autosaveCanonicalAction'))->setValue($controller, [
            'service' => $service, 'operationId' => 'operation', 'targetId' => 42, 'intent' => 'save-copy',
        ]);

        (new \ReflectionMethod(FilterController::class, 'captureAutosaveCanonicalResult'))->invoke($controller, $model, 'filter.id');
        $finalize = new \ReflectionMethod(FilterController::class, 'finalizeAutosaveCanonicalSuccess');
        $finalize->invoke($controller);
        $finalize->invoke($controller);
    }
}
