<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Content\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Controller\ArticleController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests authoritative Article controller Autosave finalization.
 *
 * @since  __DEPLOY_VERSION__
 */
class ArticleControllerAutosaveTest extends UnitTestCase
{
    /**
     * @testdox  Server task intent verification matches the Article client allow-list
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalTaskIntentAllowList(): void
    {
        $constant = new \ReflectionClassConstant(ArticleController::class, 'AUTOSAVE_TASK_INTENTS');

        $this->assertSame(
            [
                'apply'     => 'apply',
                'save'      => 'save-exit',
                'save2new'  => 'save-new',
                'save2copy' => 'save-copy',
            ],
            $constant->getValue()
        );
    }

    /**
     * @testdox  The post-save hook only captures identity and cannot retire before parent completion
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPostSaveHookDefersRetirement(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())
            ->method('getState')
            ->with('article.id')
            ->willReturn(84);
        $controller = $this->controller($user, $service, 'apply');

        $this->invokePostSaveHook($controller, $model);

        $property = new \ReflectionProperty(ArticleController::class, 'autosaveCanonicalResultId');
        $this->assertSame(84, $property->getValue($controller));
    }

    /**
     * @testdox  Success finalization waits for parent completion, uses the exact model and runs once
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
                'com_content.article',
                '42',
                'save-copy',
                '84',
                $this->callback(static fn ($now): bool => $now->getOffset() === 0)
            );
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())
            ->method('getState')
            ->with('article.id')
            ->willReturn(84);
        $controller = $this->controller($user, $service, 'save-copy');

        $this->invokePostSaveHook($controller, $model);
        $this->invokeFinalization($controller);
        $this->invokeFinalization($controller);
    }

    /**
     * @testdox  Retirement failure cannot repeat or roll back a successful canonical save
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPostSaveFinalizationFailureIsNonFatalAndNotRetried(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())
            ->method('finalizeCanonicalActionSuccess')
            ->willThrowException(new \RuntimeException('storage unavailable'));
        $model = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->willReturn(42);
        $controller = $this->controller($user, $service, 'apply');

        $this->invokePostSaveHook($controller, $model);
        $this->invokeFinalization($controller);
        $this->invokeFinalization($controller);

        $this->addToAssertionCount(1);
    }

    /**
     * Create a controller at the post-save seam without invoking unrelated MVC setup.
     */
    private function controller(
        User $user,
        AutosaveCanonicalActionServiceInterface $service,
        string $intent
    ): ArticleController {
        $reflection = new \ReflectionClass(ArticleController::class);
        /** @var ArticleController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());

        $this->setProperty(BaseController::class, $controller, 'app', $app);
        $this->setProperty(BaseController::class, $controller, 'input', new Input());
        $this->setProperty(BaseController::class, $controller, 'task', 'apply');
        $this->setProperty(
            ArticleController::class,
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
    private function invokePostSaveHook(ArticleController $controller, BaseDatabaseModel $model): void
    {
        $method = new \ReflectionMethod(ArticleController::class, 'postSaveHook');
        $method->invoke($controller, $model, []);
    }

    /**
     * Invoke finalization after the simulated parent save has completed.
     */
    private function invokeFinalization(ArticleController $controller): void
    {
        $method = new \ReflectionMethod(ArticleController::class, 'finalizeAutosaveCanonicalSuccess');
        $method->invoke($controller);
    }

    /**
     * Set one inherited or private controller property for a narrow seam test.
     */
    private function setProperty(string $class, object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($class, $name);
        $property->setValue($object, $value);
    }
}
