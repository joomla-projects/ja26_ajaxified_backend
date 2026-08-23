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
use Joomla\Component\Banners\Administrator\Controller\ClientController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests authoritative Banner Client controller Autosave finalization.
 *
 * @since  __DEPLOY_VERSION__
 */
class ClientControllerAutosaveTest extends UnitTestCase
{
    /**
     * @testdox  Server task intent verification matches the Banner Client allow-list
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalTaskIntentAllowList(): void
    {
        $constant = new \ReflectionClassConstant(ClientController::class, 'AUTOSAVE_TASK_INTENTS');

        $this->assertSame(
            [
                'apply'     => 'apply',
                'save'      => 'save-exit',
                'save2new'  => 'save-new',
                'save2copy' => 'save-copy',
            ],
            $constant->getValue()
        );
        $this->assertContains(AutosaveFormControllerTrait::class, class_uses(ClientController::class));
    }

    /**
     * @testdox  The post-save hook captures authoritative client.id without early retirement
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPostSaveHookDefersRetirement(): void
    {
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('client.id')->willReturn(84);
        $controller = $this->controller($this->createMock(User::class), $service, 'apply');

        $this->invokePostSaveHook($controller, $model);

        $property = new \ReflectionProperty(ClientController::class, 'autosaveCanonicalResultId');
        $this->assertSame(84, $property->getValue($controller));
    }

    /**
     * @testdox  Successful retirement uses the exact Banner context and authoritative result once
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testFinalizationUsesCapturedAuthoritativeModelOnce(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())
            ->method('finalizeCanonicalActionSuccess')
            ->with(
                $user,
                'operation-id',
                'com_banners.client',
                '42',
                'save-copy',
                '84',
                $this->callback(static fn ($now): bool => $now->getOffset() === 0)
            );
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('client.id')->willReturn(84);
        $controller = $this->controller($user, $service, 'save-copy');

        $this->invokePostSaveHook($controller, $model);
        $this->invokeFinalization($controller);
        $this->invokeFinalization($controller);
    }

    /**
     * @testdox  Retirement failure remains non-fatal and cannot be replayed
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRetirementFailureIsNonFatalAndNotRetried(): void
    {
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())
            ->method('finalizeCanonicalActionSuccess')
            ->willThrowException(new \RuntimeException('storage unavailable'));
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->willReturn(42);
        $controller = $this->controller($this->createMock(User::class), $service, 'apply');

        $this->invokePostSaveHook($controller, $model);
        $this->invokeFinalization($controller);
        $this->invokeFinalization($controller);

        $this->addToAssertionCount(1);
    }

    private function controller(
        User $user,
        AutosaveCanonicalActionServiceInterface $service,
        string $intent
    ): ClientController {
        $reflection = new \ReflectionClass(ClientController::class);
        /** @var ClientController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());

        $this->setProperty(BaseController::class, $controller, 'app', $app);
        $this->setProperty(BaseController::class, $controller, 'input', new Input());
        $this->setProperty(BaseController::class, $controller, 'task', 'apply');
        $this->setProperty(
            ClientController::class,
            $controller,
            'autosaveCanonicalAction',
            [
                'service'     => $service,
                'operationId' => 'operation-id',
                'targetId'    => 42,
                'intent'      => $intent,
            ]
        );

        return $controller;
    }

    private function invokePostSaveHook(ClientController $controller, BaseDatabaseModel $model): void
    {
        $method = new \ReflectionMethod(ClientController::class, 'postSaveHook');
        $method->invoke($controller, $model, []);
    }

    private function invokeFinalization(ClientController $controller): void
    {
        $method = new \ReflectionMethod(ClientController::class, 'finalizeAutosaveCanonicalSuccess');
        $method->invoke($controller);
    }

    private function setProperty(string $class, object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($class, $name);
        $property->setValue($object, $value);
    }
}
