<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_banners
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Banners\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Component\Banners\Administrator\Controller\BannerController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

class BannerControllerAutosaveTest extends UnitTestCase
{
    public function testCanonicalTaskIntentAllowList(): void
    {
        $this->assertSame(
            ['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new', 'save2copy' => 'save-copy'],
            (new \ReflectionClassConstant(BannerController::class, 'AUTOSAVE_TASK_INTENTS'))->getValue()
        );
        $this->assertContains(AutosaveFormControllerTrait::class, class_uses(BannerController::class));
    }

    public function testPostSaveCapturesAuthoritativeBannerIdAndFinalizesOnce(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation-id',
            'com_banners.banner',
            '42',
            'save-copy',
            '84',
            $this->callback(static fn ($now): bool => $now->getOffset() === 0)
        );
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('banner.id')->willReturn(84);
        $controller = $this->controller($user, $service, 'save-copy');

        (new \ReflectionMethod(BannerController::class, 'postSaveHook'))->invoke($controller, $model, []);
        $this->finalize($controller);
        $this->finalize($controller);
    }

    public function testRetirementFailureAfterNativeSuccessIsNonFatalAndNotReplayed(): void
    {
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')
            ->willThrowException(new \RuntimeException('storage unavailable'));
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->with('banner.id')->willReturn(42);
        $controller = $this->controller($this->createMock(User::class), $service, 'apply');

        (new \ReflectionMethod(BannerController::class, 'postSaveHook'))->invoke($controller, $model, []);
        $this->finalize($controller);
        $this->finalize($controller);
        $this->addToAssertionCount(1);
    }

    private function controller(User $user, AutosaveCanonicalActionServiceInterface $service, string $intent): BannerController
    {
        $controller = (new \ReflectionClass(BannerController::class))->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());
        $this->setProperty(BaseController::class, $controller, 'app', $app);
        $this->setProperty(BaseController::class, $controller, 'input', new Input());
        $this->setProperty(BaseController::class, $controller, 'task', 'apply');
        $this->setProperty(BannerController::class, $controller, 'autosaveCanonicalAction', [
            'service' => $service, 'operationId' => 'operation-id', 'targetId' => 42, 'intent' => $intent,
        ]);

        return $controller;
    }

    private function finalize(BannerController $controller): void
    {
        (new \ReflectionMethod(BannerController::class, 'finalizeAutosaveCanonicalSuccess'))->invoke($controller);
    }

    private function setProperty(string $class, object $object, string $name, mixed $value): void
    {
        (new \ReflectionProperty($class, $name))->setValue($object, $value);
    }
}
