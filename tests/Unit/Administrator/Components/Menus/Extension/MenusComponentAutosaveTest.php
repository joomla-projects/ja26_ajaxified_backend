<?php

namespace Joomla\Tests\Unit\Administrator\Components\Menus\Extension;

use Joomla\CMS\Association\AssociationExtensionInterface;
use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Menus\Administrator\Autosave\MenuAutosaveProvider;
use Joomla\Component\Menus\Administrator\Extension\MenusComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class MenusComponentAutosaveTest extends UnitTestCase
{
    public function testActualServiceProviderRegistersMenuProvider(): void
    {
        $container = new Container();
        $provider  = require JPATH_ADMINISTRATOR . '/components/com_menus/services/provider.php';
        $provider->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(AssociationExtensionInterface::class, $this->createMock(AssociationExtensionInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());
        $component = $container->get(ComponentInterface::class);
        $this->assertInstanceOf(MenusComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame(['com_menus.menu' => true], $component->getAutosaveContexts());
        $this->assertInstanceOf(MenuAutosaveProvider::class, $component->getAutosaveProvider('com_menus.menu'));
    }
}
