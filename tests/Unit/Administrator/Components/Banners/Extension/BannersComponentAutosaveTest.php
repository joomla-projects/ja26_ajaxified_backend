<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_banners
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Banners\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Banners\Administrator\Autosave\ClientAutosaveProvider;
use Joomla\Component\Banners\Administrator\Extension\BannersComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Tests\Unit\UnitTestCase;

class BannersComponentAutosaveTest extends UnitTestCase
{
    public function testServiceCompositionRegistersClientAutosaveProvider(): void
    {
        $component = $this->createConfiguredComponent();

        $this->assertInstanceOf(BannersComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame(['com_banners.client' => true], $component->getAutosaveContexts());
        $this->assertInstanceOf(
            ClientAutosaveProvider::class,
            $component->getAutosaveProvider('com_banners.client')
        );
    }

    private function createConfiguredComponent(): ComponentInterface
    {
        $container       = new Container();
        $serviceProvider = require JPATH_ADMINISTRATOR . '/components/com_banners/services/provider.php';

        $this->assertInstanceOf(ServiceProviderInterface::class, $serviceProvider);
        $serviceProvider->register($container);
        $container->set(
            ComponentDispatcherFactoryInterface::class,
            $this->createMock(ComponentDispatcherFactoryInterface::class)
        );
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(CategoryFactoryInterface::class, $this->createMock(CategoryFactoryInterface::class));
        $container->set(RouterFactoryInterface::class, $this->createMock(RouterFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());

        return $container->get(ComponentInterface::class);
    }
}
