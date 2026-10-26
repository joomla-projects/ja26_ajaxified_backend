<?php

/**
 * @package   Joomla.UnitTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Autosave\Administrator;

use Joomla\CMS\Association\AssociationExtensionInterface;
use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Autosave\DraftProviderInterface;
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

class ComponentRegistrationTest extends UnitTestCase
{
    /**
     * @dataProvider components
     */
    public function testActualServicesRegisterOnlyTheirDeclaredProviders(string $name, array $entities): void
    {
        $option    = 'com_' . strtolower($name);
        $prefix    = 'Joomla\\Component\\' . $name . '\\Administrator\\';
        $container = new Container();
        $services  = include JPATH_ADMINISTRATOR . '/components/' . $option . '/services/provider.php';
        $this->assertInstanceOf(ServiceProviderInterface::class, $services);
        $services->register($container);
        foreach (
            [ComponentDispatcherFactoryInterface::class, MVCFactoryInterface::class, DatabaseInterface::class,
                CategoryFactoryInterface::class, RouterFactoryInterface::class, AssociationExtensionInterface::class] as $dependency
        ) {
            $container->set($dependency, $this->createMock($dependency));
        }
        $container->set(Registry::class, new Registry());
        $component = $container->get(ComponentInterface::class);
        $this->assertInstanceOf($prefix . 'Extension\\' . $name . 'Component', $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $contexts = array_map(static fn ($entity) => $option . '.' . strtolower($entity), $entities);
        $this->assertSame(array_fill_keys($contexts, true), $component->getAutosaveContexts());
        foreach ($entities as $index => $entity) {
            $provider = $component->getAutosaveProvider($contexts[$index]);
            $this->assertInstanceOf($prefix . 'Autosave\\' . $entity . 'AutosaveProvider', $provider);
            $this->assertInstanceOf(DraftProviderInterface::class, $provider);
            $this->assertSame($contexts[$index], $provider->getContext());
        }
        $this->expectException(\OutOfBoundsException::class);
        $component->getAutosaveProvider($option . '.unregistered');
    }

    public static function components(): array
    {
        return [
            'Banners' => ['Banners', ['Client', 'Banner']],
            'Categories' => ['Categories', ['Category']],
            'Contact' => ['Contact', ['Contact']],
            'Content' => ['Content', ['Article']],
            'Fields' => ['Fields', ['Group', 'Field']],
            'Finder' => ['Finder', ['Filter']],
            'Guidedtours' => ['Guidedtours', ['Tour', 'Step']],
            'Languages' => ['Languages', ['Language', 'Override']],
            'Menus' => ['Menus', ['Menu', 'Item']],
            'Modules' => ['Modules', ['Module']],
            'Newsfeeds' => ['Newsfeeds', ['Newsfeed']],
            'Redirect' => ['Redirect', ['Link']],
            'Tags' => ['Tags', ['Tag']],
            'Templates' => ['Templates', ['Style']],
            'Users' => ['Users', ['Group', 'Level', 'Note']],
            'Workflow' => ['Workflow', ['Workflow', 'Stage', 'Transition']],
        ];
    }
}
