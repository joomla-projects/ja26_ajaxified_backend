<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\AutosaveFormControllerTraitCustomSaveHarness;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\AutosaveFormControllerTraitHarness;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\AutosaveTraitTestApplication;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub\AutosaveTraitTestInput;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the shared FormController Autosave reconciliation contract.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveFormControllerTraitTest extends UnitTestCase
{
    /**
     * @testdox  The public save API remains compatible with FormController
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPublicSaveSignatureRemainsCompatible(): void
    {
        $method     = new \ReflectionMethod(AutosaveFormControllerTraitHarness::class, 'save');
        $parameters = $method->getParameters();

        $this->assertTrue($method->isPublic());
        $this->assertSame(['key', 'urlVar'], array_map(static fn ($parameter) => $parameter->getName(), $parameters));
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertNull($parameters[0]->getDefaultValue());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertNull($parameters[1]->getDefaultValue());
    }

    /**
     * @testdox  Ordinary requests retain the parent controller save path
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOrdinaryRequestDelegatesOnceWithoutAutosaveWork(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->controller($service);

        $service->expects($this->never())->method('verifyCanonicalAction');
        $this->assertTrue($controller->save('custom-key', 'custom-id'));
        $this->assertSame(1, $controller->parentSaveCalls);
        $this->assertSame([['custom-key', 'custom-id']], $controller->parentSaveArguments);
    }

    /**
     * @testdox  Invalid action metadata is rejected before canonical persistence
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInvalidMetadataCannotReachParentSave(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->controller(
            $service,
            'item.apply',
            ['autosave_operation_id' => 'operation-id', 'autosave_operation_intent' => 'save-exit', 'jform' => ['id' => 42]]
        );

        $service->expects($this->never())->method('verifyCanonicalAction');
        $this->assertFalse($controller->save());
        $this->assertSame(0, $controller->parentSaveCalls);
        $this->assertSame('item/42', $controller->redirect);
    }

    /**
     * @testdox  Stale or foreign prepared operations are rejected without canonical persistence
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testUnverifiedOperationCannotReachParentSave(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->controller($service, 'item.apply', $this->preparedPost());

        $service->expects($this->once())
            ->method('verifyCanonicalAction')
            ->willThrowException(new \RuntimeException('unverified operation'));
        $service->expects($this->once())
            ->method('finalizeCanonicalActionFailure')
            ->willThrowException(new \RuntimeException('foreign operation remains private'));
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');

        $this->assertFalse($controller->save());
        $this->assertSame(0, $controller->parentSaveCalls);
        $this->assertSame('item/42', $controller->redirect);
    }

    /**
     * @testdox  A definitive parent save failure records failure without retirement
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDefinitiveSaveFailureDoesNotRetireDraft(): void
    {
        $user                         = $this->createMock(User::class);
        $service                      = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller                   = $this->controller($service, 'item.apply', $this->preparedPost(), $user);
        $controller->parentSaveResult = false;

        $service->expects($this->once())->method('verifyCanonicalAction')->willReturn([]);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $service->expects($this->once())
            ->method('finalizeCanonicalActionFailure')
            ->with(
                $user,
                'operation-id',
                'com_example.item',
                '42',
                'apply',
                'canonical_save_failed',
                $this->anything()
            )
            ->willReturn([]);

        $this->assertFalse($controller->save());
        $this->assertSame(1, $controller->parentSaveCalls);
    }

    /**
     * @testdox  An exceptional parent outcome is never replayed or finalized speculatively
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExceptionalSaveOutcomeRemainsUnknownAndIsNotReplayed(): void
    {
        $service                         = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller                      = $this->controller($service, 'item.apply', $this->preparedPost());
        $failure                         = new \RuntimeException('unknown parent outcome');
        $controller->parentSaveException = $failure;

        $service->expects($this->once())->method('verifyCanonicalAction')->willReturn([]);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $service->expects($this->never())->method('finalizeCanonicalActionFailure');

        try {
            $controller->save();
            $this->fail('The canonical save exception was not propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $controller->parentSaveCalls);
    }

    /**
     * @testdox  Confirmed persistence retires the exact generation once after model identity capture
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testConfirmedSuccessRetiresExactGenerationOnce(): void
    {
        $user       = $this->createMock(User::class);
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->controller($service, 'item.save2new', $this->preparedPost('save-new'), $user);
        $model      = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('item.id')->willReturn(84);
        $controller->duringParentSave = static fn () => $controller->captureSavedModel($model);

        $service->expects($this->once())->method('verifyCanonicalAction')->willReturn([]);
        $service->expects($this->never())->method('finalizeCanonicalActionFailure');
        $service->expects($this->once())
            ->method('finalizeCanonicalActionSuccess')
            ->with(
                $user,
                'operation-id',
                'com_example.item',
                '42',
                'save-new',
                '84',
                $this->anything()
            )
            ->willReturn([]);

        $this->assertTrue($controller->save());
        $this->assertSame(1, $controller->parentSaveCalls);
    }

    /**
     * @testdox  Canonical actions bind to the native route identity when the form omits jform[id]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalActionUsesRouteIdentityWithoutPostedFormId(): void
    {
        $user       = $this->createMock(User::class);
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $post       = $this->preparedPost();
        unset($post['jform']['id']);
        $controller = $this->controller($service, 'item.apply', $post, $user, 84);

        $service->expects($this->once())
            ->method('verifyCanonicalAction')
            ->with($user, 'operation-id', 'com_example.item', '84', 'apply', $this->anything())
            ->willReturn([]);
        $service->expects($this->once())
            ->method('finalizeCanonicalActionSuccess')
            ->with($user, 'operation-id', 'com_example.item', '84', 'apply', '84', $this->anything())
            ->willReturn([]);

        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('item.id')->willReturn(84);
        $controller->duringParentSave = static fn () => $controller->captureSavedModel($model);

        $this->assertTrue($controller->save());
    }

    /**
     * @testdox  Posted form identity cannot override the native route identity
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalActionDoesNotTrustPostedFormId(): void
    {
        $user                = $this->createMock(User::class);
        $service             = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $post                = $this->preparedPost();
        $post['jform']['id'] = 999;
        $controller          = $this->controller($service, 'item.apply', $post, $user, 42);

        $service->expects($this->once())
            ->method('verifyCanonicalAction')
            ->with($user, 'operation-id', 'com_example.item', '42', 'apply', $this->anything())
            ->willReturn([]);
        $service->expects($this->once())
            ->method('finalizeCanonicalActionSuccess')
            ->with($user, 'operation-id', 'com_example.item', '42', 'apply', '42', $this->anything())
            ->willReturn([]);

        $model = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('item.id')->willReturn(42);
        $controller->duringParentSave = static fn () => $controller->captureSavedModel($model);

        $this->assertTrue($controller->save());
    }

    /**
     * @testdox  Unsupported canonical tasks retain the inherited native path
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testUnsupportedTaskDelegatesOnceWithoutAutosaveOutcome(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->controller($service, 'item.cancel', $this->preparedPost('cancel'));

        $service->expects($this->never())->method('verifyCanonicalAction');
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $service->expects($this->never())->method('finalizeCanonicalActionFailure');

        $this->assertTrue($controller->save('id', 'record'));
        $this->assertSame(1, $controller->parentSaveCalls);
        $this->assertSame([['id', 'record']], $controller->parentSaveArguments);
    }

    /**
     * @testdox  A controller-owned save delegates its complete native workflow exactly once
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomSaveOwnsNativeWorkflowWithoutAutosave(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->customController($service);

        $service->expects($this->never())->method('verifyCanonicalAction');

        $this->assertTrue($controller->save('custom-key', 'custom-id'));
        $this->assertSame(1, $controller->nativeSaveCalls);
        $this->assertSame([['custom-key', 'custom-id']], $controller->nativeSaveArguments);
        $this->assertSame('native/redirect', $controller->nativeRedirect);
        $this->assertSame(['native message'], $controller->nativeMessages);
        $this->assertSame(['saved' => true], $controller->nativeSession);
        $this->assertSame(1, $controller->componentSideEffects);
    }

    /**
     * @testdox  Unsupported tasks remain native and do not manufacture canonical outcomes
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomSaveUnsupportedTaskUsesNativeFallbackOnce(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->customController($service, 'item.custom', $this->preparedPost());

        $service->expects($this->never())->method('verifyCanonicalAction');
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');

        $this->assertTrue($controller->save());
        $this->assertSame(1, $controller->nativeSaveCalls);
    }

    /**
     * @testdox  A prepared custom save finalizes after native authoritative identity capture
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomSaveFinalizesAfterNativeSuccessWithAuthoritativeIdentity(): void
    {
        $user                    = $this->createMock(User::class);
        $service                 = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller              = $this->customController($service, 'item.apply', $this->preparedPost(), $user);
        $controller->input->post = new class ($this->preparedPost()) {
            public function __construct(private array $data)
            {
                $this->data['jform']['id'] = 999;
            }

            public function getString(string $key, string $default = ''): string
            {
                return isset($this->data[$key]) ? (string) $this->data[$key] : $default;
            }
        };
        $model      = $this->createMock(BaseDatabaseModel::class);
        $model->expects($this->once())->method('getState')->with('item.id')->willReturn(84);
        $controller->savedModel = $model;

        $service->expects($this->once())->method('verifyCanonicalAction')->willReturnCallback(
            function () use ($controller): array {
                $controller->operationOrder[] = 'verify';

                return [];
            }
        );
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation-id',
            'com_example.item',
            '42',
            'apply',
            '84',
            $this->anything()
        )->willReturnCallback(function () use ($controller): array {
            $controller->operationOrder[] = 'finalize';

            return [];
        });

        $this->assertTrue($controller->save());
        $this->assertSame(['verify', 'native', 'finalize'], $controller->operationOrder);
        $this->assertSame(1, $controller->nativeSaveCalls);
    }

    /**
     * @testdox  Custom native failure remains definitive and leaves the generation recoverable
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomSaveFailurePreservesNativeStateAndDoesNotRetire(): void
    {
        $service                      = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller                   = $this->customController($service, 'item.apply', $this->preparedPost());
        $controller->nativeSaveResult = false;

        $service->expects($this->once())->method('verifyCanonicalAction')->willReturn([]);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $service->expects($this->once())->method('finalizeCanonicalActionFailure')->willReturn([]);

        $this->assertFalse($controller->save());
        $this->assertSame(1, $controller->nativeSaveCalls);
        $this->assertSame('native/redirect', $controller->nativeRedirect);
        $this->assertSame(['native message'], $controller->nativeMessages);
        $this->assertSame(['saved' => true], $controller->nativeSession);
    }

    /**
     * @testdox  Custom native exceptions propagate without finalization or replay
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomSaveExceptionPropagatesWithoutFinalization(): void
    {
        $service                         = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller                      = $this->customController($service, 'item.apply', $this->preparedPost());
        $failure                         = new \RuntimeException('native failure');
        $controller->nativeSaveException = $failure;

        $service->expects($this->once())->method('verifyCanonicalAction')->willReturn([]);
        $service->expects($this->never())->method('finalizeCanonicalActionSuccess');
        $service->expects($this->never())->method('finalizeCanonicalActionFailure');

        try {
            $controller->save();
            $this->fail('The native exception was not propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $controller->nativeSaveCalls);
        $this->assertSame(1, $controller->componentSideEffects);
    }

    /**
     * @testdox  Retirement failure is non-fatal and cannot replay a custom save
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomSaveRetirementFailureIsNonFatalAndNotReplayed(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->customController($service, 'item.apply', $this->preparedPost());
        $model      = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->willReturn(84);
        $controller->savedModel = $model;

        $service->expects($this->once())->method('verifyCanonicalAction')->willReturn([]);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')
            ->willThrowException(new \RuntimeException('retirement unavailable'));

        $this->assertTrue($controller->save());
        $this->assertSame(1, $controller->nativeSaveCalls);
        $this->assertSame(1, $controller->componentSideEffects);
    }

    /**
     * @testdox  Sequential custom actions do not reuse stale prepared state
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomSaveSequentialActionsDoNotReusePreparedState(): void
    {
        $service    = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $controller = $this->customController($service, 'item.apply', $this->preparedPost());
        $model      = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->willReturn(84);
        $controller->savedModel = $model;

        $service->expects($this->exactly(2))->method('verifyCanonicalAction')->willReturn([]);
        $service->expects($this->exactly(2))->method('finalizeCanonicalActionSuccess')->willReturn([]);

        $this->assertTrue($controller->save());
        $this->assertTrue($controller->save());
        $this->assertSame(2, $controller->nativeSaveCalls);
    }

    /**
     * Create the controller harness.
     *
     * @param   AutosaveCanonicalActionServiceInterface  $service  Autosave service.
     * @param   string                                   $task     Joomla task.
     * @param   array                                    $post     POST data.
     * @param   User|null                                $user     Current identity.
     *
     * @return  AutosaveFormControllerTraitHarness
     *
     * @since   __DEPLOY_VERSION__
     */
    private function controller(
        AutosaveCanonicalActionServiceInterface $service,
        string $task = 'item.save',
        array $post = [],
        ?User $user = null,
        int $recordId = 42
    ): AutosaveFormControllerTraitHarness {
        return new AutosaveFormControllerTraitHarness(
            new AutosaveTraitTestInput($task, $post, $recordId),
            new AutosaveTraitTestApplication($service, $user ?? $this->createMock(User::class))
        );
    }

    private function customController(
        AutosaveCanonicalActionServiceInterface $service,
        string $task = 'item.save',
        array $post = [],
        ?User $user = null,
        int $recordId = 42
    ): AutosaveFormControllerTraitCustomSaveHarness {
        return new AutosaveFormControllerTraitCustomSaveHarness(
            new AutosaveTraitTestInput($task, $post, $recordId),
            new AutosaveTraitTestApplication($service, $user ?? $this->createMock(User::class))
        );
    }

    /**
     * Return valid prepared operation metadata.
     *
     * @param   string  $intent  Canonical intent.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function preparedPost(string $intent = 'apply'): array
    {
        return [
            'autosave_operation_id'     => 'operation-id',
            'autosave_operation_intent' => $intent,
            'jform'                     => ['id' => 42],
        ];
    }
}
