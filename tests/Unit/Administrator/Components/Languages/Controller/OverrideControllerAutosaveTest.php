<?php

namespace Joomla\Tests\Unit\Administrator\Components\Languages\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\User\User;
use Joomla\Component\Languages\Administrator\Controller\OverrideController;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\NullLogger;

class OverrideControllerAutosaveTest extends UnitTestCase
{
    public function testCanonicalIntegrationUsesExactContextAndNativeTasks(): void
    {
        $reflection = new \ReflectionClass(OverrideController::class);

        $this->assertContains(AutosaveFormControllerTrait::class, $reflection->getTraitNames());
        $this->assertSame('com_languages.override', $reflection->getConstant('AUTOSAVE_CONTEXT'));
        $this->assertSame(
            ['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new'],
            $reflection->getConstant('AUTOSAVE_TASK_INTENTS')
        );
        $this->assertTrue($reflection->hasMethod('resolveAutosaveCanonicalTarget'));
        $this->assertTrue($reflection->hasMethod('captureAutosaveCanonicalTarget'));
    }

    public function testAuthoritativeCompositeIdentityFinalizesOnlyOnce(): void
    {
        $original = 'c1|18:languages.override|4:site|5:en-GB|12:COM_ORIGINAL';
        $result   = 'c1|18:languages.override|4:site|5:en-GB|11:COM_RENAMED';
        $user     = $this->createMock(User::class);
        $service  = $this->createMock(AutosaveCanonicalActionServiceInterface::class);
        $service->expects($this->once())->method('finalizeCanonicalActionSuccess')->with(
            $user,
            'operation',
            'com_languages.override',
            $original,
            'apply',
            $result,
            $this->anything()
        );

        $controller = (new \ReflectionClass(OverrideController::class))->newInstanceWithoutConstructor();
        $app        = $this->createMock(CMSApplicationInterface::class);
        $app->method('getIdentity')->willReturn($user);
        $controller->setLogger(new NullLogger());
        (new \ReflectionProperty(BaseController::class, 'app'))->setValue($controller, $app);
        (new \ReflectionProperty(BaseController::class, 'input'))->setValue($controller, new Input());
        (new \ReflectionProperty(OverrideController::class, 'autosaveCanonicalAction'))->setValue($controller, [
            'service' => $service, 'operationId' => 'operation', 'targetId' => $original, 'intent' => 'apply',
        ]);

        (new \ReflectionMethod(OverrideController::class, 'captureAutosaveCanonicalTarget'))->invoke($controller, $result);
        $finalize = new \ReflectionMethod(OverrideController::class, 'finalizeAutosaveCanonicalSuccess');
        $finalize->invoke($controller);
        $finalize->invoke($controller);
    }
}
