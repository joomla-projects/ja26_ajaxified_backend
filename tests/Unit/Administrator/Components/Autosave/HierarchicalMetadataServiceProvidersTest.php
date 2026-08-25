<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Tests\Unit\UnitTestCase;

class HierarchicalMetadataServiceProvidersTest extends UnitTestCase
{
    /**
     * @dataProvider componentProvider
     */
    public function testActualServiceProviderRegistersExactContext(string $componentName, string $context, string $providerClass): void
    {
        $container       = new Container();
        $serviceProvider = require JPATH_ADMINISTRATOR . '/components/' . $componentName . '/services/provider.php';

        $this->assertInstanceOf(ServiceProviderInterface::class, $serviceProvider);
        $serviceProvider->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(CategoryFactoryInterface::class, $this->createMock(CategoryFactoryInterface::class));
        $container->set(RouterFactoryInterface::class, $this->createMock(RouterFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());

        $component = $container->get(ComponentInterface::class);

        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame([$context => true], $component->getAutosaveContexts());
        $this->assertInstanceOf($providerClass, $component->getAutosaveProvider($context));
    }

    public static function componentProvider(): array
    {
        return [
            'category' => ['com_categories', 'com_categories.category', 'Joomla\Component\Categories\Administrator\Autosave\CategoryAutosaveProvider'],
            'tag'      => ['com_tags', 'com_tags.tag', 'Joomla\Component\Tags\Administrator\Autosave\TagAutosaveProvider'],
            'group'    => ['com_fields', 'com_fields.group', 'Joomla\Component\Fields\Administrator\Autosave\GroupAutosaveProvider'],
        ];
    }
}
