<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_redirect
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Redirect\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Redirect\Administrator\Autosave\LinkAutosaveProvider;
use Joomla\Component\Redirect\Administrator\Extension\RedirectComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the real com_redirect Autosave service composition.
 *
 * @since  __DEPLOY_VERSION__
 */
class RedirectComponentAutosaveTest extends UnitTestCase
{
    /**
     * @testdox  com_redirect explicitly exposes only its Link provider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testServiceCompositionRegistersLinkAutosaveProvider(): void
    {
        $component = $this->createConfiguredComponent();

        $this->assertInstanceOf(RedirectComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame(['com_redirect.link' => true], $component->getAutosaveContexts());
        $this->assertInstanceOf(
            LinkAutosaveProvider::class,
            $component->getAutosaveProvider('com_redirect.link')
        );

        $this->expectException(\OutOfBoundsException::class);
        $component->getAutosaveProvider('com_redirect.links');
    }

    /**
     * Resolve RedirectComponent through its actual service provider.
     *
     * @return  ComponentInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function createConfiguredComponent(): ComponentInterface
    {
        $container       = new Container();
        $serviceProvider = require JPATH_ADMINISTRATOR . '/components/com_redirect/services/provider.php';

        $this->assertInstanceOf(ServiceProviderInterface::class, $serviceProvider);
        $serviceProvider->register($container);
        $container->set(
            ComponentDispatcherFactoryInterface::class,
            $this->createMock(ComponentDispatcherFactoryInterface::class)
        );
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());

        return $container->get(ComponentInterface::class);
    }
}
