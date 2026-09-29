<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Association\AssociationExtensionInterface;
use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Newsfeeds\Administrator\Autosave\NewsfeedAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\TransitionAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class CanonicalRelationsServiceProvidersTest extends UnitTestCase
{
    public function testNewsfeedProviderResolvesThroughActualComponentService(): void
    {
        $container = $this->container();
        (require JPATH_ADMINISTRATOR . '/components/com_newsfeeds/services/provider.php')->register($container);
        $this->registerComponentDependencies($container);
        $container->set(CategoryFactoryInterface::class, $this->createMock(CategoryFactoryInterface::class));
        $container->set(RouterFactoryInterface::class, $this->createMock(RouterFactoryInterface::class));
        $container->set(AssociationExtensionInterface::class, $this->createMock(AssociationExtensionInterface::class));
        $container->set(Registry::class, new Registry());
        $component = $container->get(ComponentInterface::class);

        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertInstanceOf(NewsfeedAutosaveProvider::class, $component->getAutosaveProvider('com_newsfeeds.newsfeed'));
    }

    public function testTransitionCoexistsWithWorkflowAndStageProviders(): void
    {
        $container = $this->container();
        (require JPATH_ADMINISTRATOR . '/components/com_workflow/services/provider.php')->register($container);
        $this->registerComponentDependencies($container);
        $component = $container->get(ComponentInterface::class);

        $this->assertSame(['com_workflow.workflow' => true, 'com_workflow.stage' => true, 'com_workflow.transition' => true], $component->getAutosaveContexts());
        $this->assertInstanceOf(TransitionAutosaveProvider::class, $component->getAutosaveProvider('com_workflow.transition'));
        $this->assertSame('com_workflow.workflow', $component->getAutosaveProvider('com_workflow.workflow')->getContext());
        $this->assertSame('com_workflow.stage', $component->getAutosaveProvider('com_workflow.stage')->getContext());
    }

    private function container(): Container
    {
        $container = new Container();
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));

        return $container;
    }

    private function registerComponentDependencies(Container $container): void
    {
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
    }
}
