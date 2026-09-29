<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_redirect
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Redirect\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Component\Redirect\Administrator\Controller\LinkController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests authoritative Redirect controller Autosave finalization.
 *
 * @since  __DEPLOY_VERSION__
 */
class LinkControllerAutosaveTest extends UnitTestCase
{
    /**
     * @testdox  Redirect canonical tasks omit Save as Copy
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalTaskIntentAllowList(): void
    {
        $constant = new \ReflectionClassConstant(LinkController::class, 'AUTOSAVE_TASK_INTENTS');

        $this->assertSame(
            [
                'apply'    => 'apply',
                'save'     => 'save-exit',
                'save2new' => 'save-new',
            ],
            $constant->getValue()
        );
    }

    /**
     * @testdox  The Redirect post-save seam captures the authoritative Link model identity
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPostSaveHookCapturesLinkIdentityWithoutEarlyRetirement(): void
    {
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('link.id')->willReturn(42);
        $controller = $this->controller($this->createMock(User::class), $service, 'apply');

        $this->invokePostSaveHook($controller, $model);

        $property = new \ReflectionProperty(LinkController::class, 'autosaveCanonicalResultId');
        $this->assertSame(42, $property->getValue($controller));
    }

    /**
     * @testdox  Redirect retirement uses the exact context, target and saved result once
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testFinalizationUsesRedirectIdentityOnce(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())
            ->method('finalizeCanonicalActionSuccess')
            ->with(
                $user,
                'operation-id',
                'com_redirect.link',
                '42',
                'save-new',
                '84',
                $this->callback(static fn ($now): bool => $now->getOffset() === 0)
            );
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('link.id')->willReturn(84);
        $controller = $this->controller($user, $service, 'save-new');

        $this->invokePostSaveHook($controller, $model);
        $this->invokeFinalization($controller);
        $this->invokeFinalization($controller);
    }

    /**
     * Create a controller at the post-save seam.
     */
    private function controller(
        User $user,
        AutosaveCanonicalActionServiceInterface $service,
        string $intent
    ): LinkController {
        $reflection = new \ReflectionClass(LinkController::class);
        /** @var LinkController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());

        $this->setProperty(BaseController::class, $controller, 'app', $app);
        $this->setProperty(BaseController::class, $controller, 'input', new Input());
        $this->setProperty(BaseController::class, $controller, 'task', 'apply');
        $this->setProperty(
            LinkController::class,
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

    /**
     * Invoke the protected model-identity capture seam.
     */
    private function invokePostSaveHook(LinkController $controller, BaseDatabaseModel $model): void
    {
        $method = new \ReflectionMethod(LinkController::class, 'postSaveHook');
        $method->invoke($controller, $model, []);
    }

    /**
     * Invoke finalization after simulated parent completion.
     */
    private function invokeFinalization(LinkController $controller): void
    {
        $method = new \ReflectionMethod(LinkController::class, 'finalizeAutosaveCanonicalSuccess');
        $method->invoke($controller);
    }

    /**
     * Set one inherited or trait-provided property.
     */
    private function setProperty(string $class, object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($class, $name);
        $property->setValue($object, $value);
    }
}
