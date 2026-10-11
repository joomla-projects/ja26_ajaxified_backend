<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Content\Extension;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Administrator\Autosave\ArticleAutosaveProvider;
use Joomla\Component\Content\Administrator\Extension\ContentComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the real com_content Autosave service composition.
 *
 * @since  __DEPLOY_VERSION__
 */
class ContentComponentAutosaveTest extends UnitTestCase
{
    /**
     * @testdox  The real com_content service composition explicitly exposes the Article provider
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testServiceCompositionRegistersArticleAutosaveProvider(): void
    {
        $component = $this->createConfiguredComponent();

        $this->assertInstanceOf(ContentComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame(['com_content.article' => true], $component->getAutosaveContexts());

        $provider = $component->getAutosaveProvider('com_content.article');

        $this->assertInstanceOf(ArticleAutosaveProvider::class, $provider);
        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertSame('com_content.article', $provider->getContext());
    }

    /**
     * @testdox  The configured component has no implicit or fallback Autosave provider
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testServiceCompositionRejectsUnknownAutosaveContext(): void
    {
        $component = $this->createConfiguredComponent();

        $this->expectException(\OutOfBoundsException::class);
        $component->getAutosaveProvider('com_content.category');
    }

    /**
     * Resolve ContentComponent through its actual service provider.
     *
     * @return  ComponentInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function createConfiguredComponent(): ComponentInterface
    {
        $container       = new Container();
        $serviceProvider = require JPATH_ADMINISTRATOR . '/components/com_content/services/provider.php';

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
