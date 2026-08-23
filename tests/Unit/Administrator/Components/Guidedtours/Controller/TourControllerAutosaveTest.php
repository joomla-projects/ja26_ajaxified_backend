<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_guidedtours
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Guidedtours\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Component\Guidedtours\Administrator\Controller\TourController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

class TourControllerAutosaveTest extends UnitTestCase
{
    public function testCanonicalTaskIntentAllowListAndTrait(): void
    {
        $constant = new \ReflectionClassConstant(TourController::class, 'AUTOSAVE_TASK_INTENTS');

        $this->assertSame([
            'apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new', 'save2copy' => 'save-copy',
        ], $constant->getValue());
        $this->assertContains(AutosaveFormControllerTrait::class, class_uses(TourController::class));
    }

    public function testPostSaveHookCapturesAuthoritativeTourId(): void
    {
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('tour.id')->willReturn(84);
        $controller = $this->controller($this->createMock(User::class), $service, 'apply');

        (new \ReflectionMethod(TourController::class, 'postSaveHook'))->invoke($controller, $model, ['id' => 999]);

        $property = new \ReflectionProperty(TourController::class, 'autosaveCanonicalResultId');
        $this->assertSame(84, $property->getValue($controller));
    }

    public function testFinalizationUsesExactContextAndCannotReplay(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation-id',
            'com_guidedtours.tour',
            '42',
            'save-copy',
            '84',
            $this->anything()
        );
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->with('tour.id')->willReturn(84);
        $controller = $this->controller($user, $service, 'save-copy');
        (new \ReflectionMethod(TourController::class, 'postSaveHook'))->invoke($controller, $model, []);
        $finalize = new \ReflectionMethod(TourController::class, 'finalizeAutosaveCanonicalSuccess');

        $finalize->invoke($controller);
        $finalize->invoke($controller);
    }

    public function testRetirementFailureIsNonFatalAndNotRetried(): void
    {
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')
            ->willThrowException(new \RuntimeException('unavailable'));
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->willReturn(42);
        $controller = $this->controller($this->createMock(User::class), $service, 'apply');
        (new \ReflectionMethod(TourController::class, 'postSaveHook'))->invoke($controller, $model, []);
        $finalize = new \ReflectionMethod(TourController::class, 'finalizeAutosaveCanonicalSuccess');

        $finalize->invoke($controller);
        $finalize->invoke($controller);
        $this->addToAssertionCount(1);
    }

    private function controller(User $user, AutosaveCanonicalActionServiceInterface $service, string $intent): TourController
    {
        $controller = (new \ReflectionClass(TourController::class))->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());
        $this->setProperty(BaseController::class, $controller, 'app', $app);
        $this->setProperty(BaseController::class, $controller, 'input', new Input());
        $this->setProperty(BaseController::class, $controller, 'task', 'apply');
        $this->setProperty(TourController::class, $controller, 'autosaveCanonicalAction', [
            'service' => $service, 'operationId' => 'operation-id', 'targetId' => 42, 'intent' => $intent,
        ]);

        return $controller;
    }

    private function setProperty(string $class, object $object, string $name, mixed $value): void
    {
        (new \ReflectionProperty($class, $name))->setValue($object, $value);
    }
}
