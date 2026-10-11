<?php

namespace Joomla\Tests\Unit\Administrator\Components\Contact\Extension;

use Joomla\CMS\Association\AssociationExtensionInterface;
use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Contact\Administrator\Autosave\ContactAutosaveProvider;
use Joomla\Component\Contact\Administrator\Extension\ContactComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class ContactComponentAutosaveTest extends UnitTestCase
{
    public function testRealServiceProviderExposesExactContactProvider(): void
    {
        $container = new Container();
        $provider  = require JPATH_ADMINISTRATOR . '/components/com_contact/services/provider.php';
        $provider->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(CategoryFactoryInterface::class, $this->createMock(CategoryFactoryInterface::class));
        $container->set(RouterFactoryInterface::class, $this->createMock(RouterFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());
        $container->set(AssociationExtensionInterface::class, $this->createMock(AssociationExtensionInterface::class));
        $component = $container->get(ComponentInterface::class);

        $this->assertInstanceOf(ContactComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame(['com_contact.contact' => true], $component->getAutosaveContexts());
        $this->assertInstanceOf(ContactAutosaveProvider::class, $component->getAutosaveProvider('com_contact.contact'));
    }
}
