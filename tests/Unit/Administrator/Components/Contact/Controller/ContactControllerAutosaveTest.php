<?php

namespace Joomla\Tests\Unit\Administrator\Components\Contact\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Component\Contact\Administrator\Controller\ContactController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

class ContactControllerAutosaveTest extends UnitTestCase
{
    public function testExactCanonicalTasksAndTrait(): void
    {
        $this->assertSame([
            'apply'     => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new',
            'save2copy' => 'save-copy', 'save2menu' => 'save-exit',
        ], (new \ReflectionClassConstant(ContactController::class, 'AUTOSAVE_TASK_INTENTS'))->getValue());
        $this->assertContains(AutosaveFormControllerTrait::class, class_uses(ContactController::class));
    }

    public function testUsesAuthoritativeSavedContactIdentityAndFinalizesOnce(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation',
            'com_contact.contact',
            '42',
            'save-copy',
            '84',
            $this->anything()
        );
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('contact.id')->willReturn(84);
        $controller = $this->controller($user, $service);
        (new \ReflectionMethod(ContactController::class, 'postSaveHook'))->invoke($controller, $model, []);
        $method = new \ReflectionMethod(ContactController::class, 'finalizeAutosaveCanonicalSuccess');
        $method->invoke($controller);
        $method->invoke($controller);
    }

    private function controller(User $user, AutosaveCanonicalActionServiceInterface $service): ContactController
    {
        $controller = (new \ReflectionClass(ContactController::class))->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());
        (new \ReflectionProperty(BaseController::class, 'app'))->setValue($controller, $app);
        (new \ReflectionProperty(BaseController::class, 'input'))->setValue($controller, new Input());
        (new \ReflectionProperty(BaseController::class, 'task'))->setValue($controller, 'apply');
        (new \ReflectionProperty(ContactController::class, 'autosaveCanonicalAction'))->setValue($controller, [
            'service' => $service, 'operationId' => 'operation', 'targetId' => 42, 'intent' => 'save-copy',
        ]);
        return $controller;
    }
}
