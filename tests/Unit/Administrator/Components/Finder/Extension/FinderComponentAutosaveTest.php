<?php

namespace Joomla\Tests\Unit\Administrator\Components\Finder\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Finder\Administrator\Autosave\FilterAutosaveProvider;
use Joomla\Component\Finder\Administrator\Extension\FinderComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class FinderComponentAutosaveTest extends UnitTestCase
{
    public function testRealServiceProviderExposesExactFilterProvider(): void
    {
        $container = new Container();
        (require JPATH_ADMINISTRATOR . '/components/com_finder/services/provider.php')->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(RouterFactoryInterface::class, $this->createMock(RouterFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());
        $component = $container->get(ComponentInterface::class);
        $this->assertInstanceOf(FinderComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame(['com_finder.filter' => true], $component->getAutosaveContexts());
        $this->assertInstanceOf(FilterAutosaveProvider::class, $component->getAutosaveProvider('com_finder.filter'));
    }
}
