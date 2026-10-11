<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveCompositeCanonicalIdentityInterface;
use Joomla\CMS\Autosave\AutosaveCreateCanonicalActionServiceInterface;
use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\Autosave\AutosaveTargetIdentity;
use Joomla\CMS\Language\Language;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\User\User;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Guards the AutosaveFormControllerTrait canonical-save identity gate: the
 * strict numeric path for numeric components and the optional composite path
 * for components that declare an authoritative non-numeric identity.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveFormControllerTraitGateTest extends UnitTestCase
{
    private const P1 = 'p1:abababababababababababababababababababababababababababababababab';

    private function language(): Language
    {
        $language = $this->createMock(Language::class);
        $language->method('load')->willReturn(true);

        return $language;
    }

    private function service(): AutosaveCreateCanonicalActionServiceInterface
    {
        return $this->createMock(AutosaveCreateCanonicalActionServiceInterface::class);
    }

    private function app(object $service, User $user): object
    {
        $language = $this->language();

        return new class ($service, $user, $language) {
            private object $service;
            private User $user;
            private Language $language;

            public function __construct(object $service, User $user, Language $language)
            {
                $this->service  = $service;
                $this->user     = $user;
                $this->language = $language;
            }

            public function bootComponent(string $name): object
            {
                return $this->service;
            }

            public function getIdentity(): User
            {
                return $this->user;
            }

            public function getLanguage(): Language
            {
                return $this->language;
            }
        };
    }

    private function numericController(object $app, Input $input): object
    {
        $controller = new class () extends FormController {
            use AutosaveFormControllerTrait;

            private const AUTOSAVE_CONTEXT      = 'com_test.numeric';
            private const AUTOSAVE_TASK_INTENTS = ['apply' => 'apply'];

            public function __construct()
            {
            }

            public function runSave(callable $nativeSave, ?string $urlVar = null, ?string $key = null): bool
            {
                return $this->executeAutosaveCanonicalSave($nativeSave, $urlVar, $key);
            }

            public function capture(BaseDatabaseModel $model, string $stateKey): void
            {
                $this->captureAutosaveCanonicalResult($model, $stateKey);
            }
        };
        $controller->setLogger(new NullLogger());
        (new \ReflectionProperty(BaseController::class, 'app'))->setValue($controller, $app);
        (new \ReflectionProperty(BaseController::class, 'input'))->setValue($controller, $input);

        return $controller;
    }

    private function compositeController(object $app, Input $input): object
    {
        $controller = new class () extends FormController implements AutosaveCompositeCanonicalIdentityInterface {
            use AutosaveFormControllerTrait;

            private const AUTOSAVE_CONTEXT      = 'com_test.composite';
            private const AUTOSAVE_TASK_INTENTS = ['apply' => 'apply'];

            public function __construct()
            {
            }

            public function runSave(callable $nativeSave, ?string $urlVar = null, ?string $key = null): bool
            {
                return $this->executeAutosaveCanonicalSave($nativeSave, $urlVar, $key);
            }

            public function captureTarget(string $targetId): void
            {
                $this->captureAutosaveCanonicalTarget($targetId);
            }

            protected function resolveAutosaveCanonicalTarget(?string $urlVar): string
            {
                $key = $this->input->getString($urlVar ?: 'id', '');

                if ($key === '') {
                    return '';
                }

                return AutosaveTargetIdentity::composite('languages.override', ['site', 'en-GB', $key]);
            }
        };
        $controller->setLogger(new NullLogger());
        (new \ReflectionProperty(BaseController::class, 'app'))->setValue($controller, $app);
        (new \ReflectionProperty(BaseController::class, 'input'))->setValue($controller, $input);

        return $controller;
    }

    public function testNumericCreatePathIsUnchangedAndFinalizesTheSavedId(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->service();
        $service->expects($this->once())->method('verifyCreateCanonicalAction')->willReturn(['target_id' => self::P1]);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation',
            'com_test.numeric',
            self::P1,
            'apply',
            '42',
            $this->anything()
        );

        $input = new Input();
        $input->set('id', '0');
        $input->set('task', 'numeric.apply');
        $input->post->set('autosave_operation_id', 'operation');
        $input->post->set('autosave_operation_intent', 'apply');

        $controller = $this->numericController($this->app($service, $user), $input);
        $saves      = 0;
        $model      = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->with('id')->willReturn(42);
        $result = $controller->runSave(function () use (&$saves, $model, $controller) {
            $saves += 1;
            $controller->capture($model, 'id');

            return true;
        }, 'id', 'id');

        $this->assertTrue($result);
        $this->assertSame(1, $saves);
    }

    public function testNumericExistingPathVerifiesThePositiveTarget(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->service();
        $service->expects($this->once())->method('verifyCanonicalAction')->with(
            $user,
            'operation',
            'com_test.numeric',
            '7',
            'apply',
            $this->anything()
        );
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess');

        $input = new Input();
        $input->set('id', '7');
        $input->set('task', 'numeric.apply');
        $input->post->set('autosave_operation_id', 'operation');
        $input->post->set('autosave_operation_intent', 'apply');
        $input->post->set('jform', ['id' => '7']);

        $controller = $this->numericController($this->app($service, $user), $input);
        $model      = $this->createMock(BaseDatabaseModel::class);
        $model->method('getState')->with('id')->willReturn(7);
        $result = $controller->runSave(function () use ($model, $controller) {
            $controller->capture($model, 'id');

            return true;
        }, 'id', 'id');

        $this->assertTrue($result);
    }

    public function testNumericMalformedRouteIdentityIsRejectedWithoutNativeSave(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->service();
        $service->expects($this->never())->method('verifyCreateCanonicalAction');
        $service->expects($this->never())->method('verifyCanonicalAction');

        $input = new Input();
        $input->set('id', 'not-numeric');
        $input->set('task', 'numeric.apply');
        $input->post->set('autosave_operation_id', 'operation');
        $input->post->set('autosave_operation_intent', 'apply');

        $controller = $this->numericController($this->app($service, $user), $input);
        $saves      = 0;
        $result     = $controller->runSave(function () use (&$saves) {
            $saves += 1;

            return true;
        }, 'id', 'id');

        $this->assertFalse($result);
        $this->assertSame(0, $saves);
    }

    public function testCompositeCreatePathVerifiesTheProvisionalTargetAndFinalizesTheComposite(): void
    {
        $user     = $this->createMock(User::class);
        $service  = $this->service();
        $final    = AutosaveTargetIdentity::composite('languages.override', ['site', 'en-GB', 'COM_NEW']);
        $service->expects($this->once())->method('verifyCreateCanonicalAction')->willReturn(['target_id' => self::P1]);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation',
            'com_test.composite',
            self::P1,
            'apply',
            $final,
            $this->anything()
        );

        $input = new Input();
        $input->set('task', 'composite.apply');
        $input->post->set('autosave_operation_id', 'operation');
        $input->post->set('autosave_operation_intent', 'apply');

        $controller = $this->compositeController($this->app($service, $user), $input);
        $saves      = 0;
        $result     = $controller->runSave(function () use (&$saves, $controller, $final) {
            $saves += 1;
            $controller->captureTarget($final);

            return true;
        }, 'id');

        $this->assertTrue($result);
        $this->assertSame(1, $saves);
    }

    public function testCompositeExistingPathVerifiesTheCompositeTarget(): void
    {
        $user     = $this->createMock(User::class);
        $service  = $this->service();
        $existing = AutosaveTargetIdentity::composite('languages.override', ['site', 'en-GB', 'COM_EXISTING']);
        $service->expects($this->once())->method('verifyCanonicalAction')->with(
            $user,
            'operation',
            'com_test.composite',
            $existing,
            'apply',
            $this->anything()
        );
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess');

        $input = new Input();
        $input->set('id', 'COM_EXISTING');
        $input->set('task', 'composite.apply');
        $input->post->set('autosave_operation_id', 'operation');
        $input->post->set('autosave_operation_intent', 'apply');

        $controller = $this->compositeController($this->app($service, $user), $input);
        $result     = $controller->runSave(function () use ($controller, $existing) {
            $controller->captureTarget($existing);

            return true;
        }, 'id');

        $this->assertTrue($result);
    }

    public function testFailedNativeSaveIsNeverReplayedAndFinalizesFailure(): void
    {
        $user    = $this->createMock(User::class);
        $service = $this->service();
        $service->expects($this->once())->method('verifyCreateCanonicalAction')->willReturn(['target_id' => self::P1]);
        $service->expects($this->once())->method('finalizeCanonicalActionFailure')->with(
            $user,
            'operation',
            'com_test.numeric',
            self::P1,
            'apply',
            'canonical_save_failed',
            $this->anything()
        );

        $input = new Input();
        $input->set('id', '0');
        $input->set('task', 'numeric.apply');
        $input->post->set('autosave_operation_id', 'operation');
        $input->post->set('autosave_operation_intent', 'apply');

        $controller = $this->numericController($this->app($service, $user), $input);
        $saves      = 0;
        $result     = $controller->runSave(function () use (&$saves) {
            $saves += 1;

            return false;
        }, 'id', 'id');

        $this->assertFalse($result);
        $this->assertSame(1, $saves);
    }
}
