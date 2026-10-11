<?php

namespace Joomla\Tests\Unit\Administrator\Components\Workflow\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Workflow\Administrator\Autosave\StageAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\WorkflowAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Extension\WorkflowComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class WorkflowComponentAutosaveTest extends UnitTestCase
{
    public function testActualServiceProviderRegistersBothContexts(): void
    {
        $container = new Container();
        $provider  = require JPATH_ADMINISTRATOR . '/components/com_workflow/services/provider.php';
        $provider->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $component = $container->get(ComponentInterface::class);
        $this->assertInstanceOf(WorkflowComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertInstanceOf(WorkflowAutosaveProvider::class, $component->getAutosaveProvider('com_workflow.workflow'));
        $this->assertInstanceOf(StageAutosaveProvider::class, $component->getAutosaveProvider('com_workflow.stage'));
    }
}
