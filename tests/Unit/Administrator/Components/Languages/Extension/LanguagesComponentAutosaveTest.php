<?php

namespace Joomla\Tests\Unit\Administrator\Components\Languages\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Languages\Administrator\Autosave\LanguageAutosaveProvider;
use Joomla\Component\Languages\Administrator\Autosave\OverrideAutosaveProvider;
use Joomla\Component\Languages\Administrator\Extension\LanguagesComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

class LanguagesComponentAutosaveTest extends UnitTestCase
{
    public function testActualServiceProviderRegistersLanguageContext(): void
    {
        $container = new Container();
        $provider  = require JPATH_ADMINISTRATOR . '/components/com_languages/services/provider.php';
        $provider->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());
        $component = $container->get(ComponentInterface::class);
        $this->assertInstanceOf(LanguagesComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertInstanceOf(LanguageAutosaveProvider::class, $component->getAutosaveProvider('com_languages.language'));
        $this->assertInstanceOf(OverrideAutosaveProvider::class, $component->getAutosaveProvider('com_languages.override'));
    }
}
