<?php

namespace Joomla\Tests\Unit\Administrator\Components\Users\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Users\Administrator\Autosave\GroupAutosaveProvider;
use Joomla\Component\Users\Administrator\Autosave\LevelAutosaveProvider;
use Joomla\Component\Users\Administrator\Autosave\NoteAutosaveProvider;
use Joomla\Component\Users\Administrator\Extension\UsersComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class UsersComponentAutosaveTest extends UnitTestCase
{
    public function testActualServiceProviderRegistersThreeExactContexts(): void
    {
        $container = new Container();
        $provider  = require JPATH_ADMINISTRATOR . '/components/com_users/services/provider.php';
        $provider->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(RouterFactoryInterface::class, $this->createMock(RouterFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());
        $component = $container->get(ComponentInterface::class);
        $this->assertInstanceOf(UsersComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertInstanceOf(GroupAutosaveProvider::class, $component->getAutosaveProvider('com_users.group'));
        $this->assertInstanceOf(LevelAutosaveProvider::class, $component->getAutosaveProvider('com_users.level'));
        $this->assertInstanceOf(NoteAutosaveProvider::class, $component->getAutosaveProvider('com_users.note'));
    }
}
