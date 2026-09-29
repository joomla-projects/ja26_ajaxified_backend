<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_guidedtours
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Guidedtours\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Guidedtours\Administrator\Autosave\StepAutosaveProvider;
use Joomla\Component\Guidedtours\Administrator\Autosave\TourAutosaveProvider;
use Joomla\Component\Guidedtours\Administrator\Extension\GuidedtoursComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Tests\Unit\UnitTestCase;

class GuidedtoursComponentAutosaveTest extends UnitTestCase
{
    public function testActualServiceProviderRegistersExactTourProvider(): void
    {
        $container       = new Container();
        $serviceProvider = require JPATH_ADMINISTRATOR . '/components/com_guidedtours/services/provider.php';

        $this->assertInstanceOf(ServiceProviderInterface::class, $serviceProvider);
        $serviceProvider->register($container);
        $container->set(ComponentDispatcherFactoryInterface::class, $this->createMock(ComponentDispatcherFactoryInterface::class));
        $container->set(MVCFactoryInterface::class, $this->createMock(MVCFactoryInterface::class));
        $container->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));
        $container->set(Registry::class, new Registry());

        $component = $container->get(ComponentInterface::class);

        $this->assertInstanceOf(GuidedtoursComponent::class, $component);
        $this->assertInstanceOf(AutosaveServiceInterface::class, $component);
        $this->assertSame(['com_guidedtours.tour' => true, 'com_guidedtours.step' => true], $component->getAutosaveContexts());
        $this->assertInstanceOf(TourAutosaveProvider::class, $component->getAutosaveProvider('com_guidedtours.tour'));
        $this->assertInstanceOf(StepAutosaveProvider::class, $component->getAutosaveProvider('com_guidedtours.step'));

        try {
            $component->getAutosaveProvider('com_guidedtours.unknown');
            $this->fail('An unrelated Guided Tours context must not resolve a provider.');
        } catch (\OutOfBoundsException) {
            $this->addToAssertionCount(1);
        }
    }
}
