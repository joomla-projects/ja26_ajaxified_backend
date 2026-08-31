<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave\Extension;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\Autosave\AutosaveStorageInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\Component\Autosave\Administrator\Dispatcher\Dispatcher;
use Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

// phpcs:disable PSR1.Files.SideEffects
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Controller/AutosaveController.php';
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Dispatcher/Dispatcher.php';
require_once JPATH_ADMINISTRATOR . '/components/com_autosave/src/Extension/AutosaveComponent.php';
// phpcs:enable PSR1.Files.SideEffects

/**
 * Tests the component's production service composition.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveServiceProviderTest extends UnitTestCase
{
    public function testBothDatabaseFamiliesContainIdempotentCoreRegistration(): void
    {
        foreach (['mysql', 'postgresql'] as $family) {
            $base   = file_get_contents(JPATH_INSTALLATION . '/sql/' . $family . '/base.sql');
            $update = file_get_contents(
                JPATH_ADMINISTRATOR . '/components/com_admin/sql/updates/' . $family . '/6.2.0-2026-07-31.sql'
            );

            $this->assertIsString($base);
            $this->assertIsString($update);
            $this->assertSame(1, substr_count($base, "'com_autosave', 'component', 'com_autosave'"));
            $this->assertCount(1, DatabaseDriver::splitSql($update));
            $this->assertStringContainsString('WHERE NOT EXISTS', $update);
            $this->assertStringNotContainsString('extension_id', $update);
        }
    }

    public function testCanonicalAdministratorApplicationAliasBuildsCompleteStack(): void
    {
        $application = $this->getMockBuilder(AdministratorApplication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInput', 'get'])
            ->getMock();
        $application->method('getInput')->willReturn(new Input());
        $application->method('get')->with('secret')->willReturn('site-secret');

        $parent = new Container();
        $parent->alias(AdministratorApplication::class, 'JApplicationAdministrator')
            ->share('JApplicationAdministrator', static fn (): AdministratorApplication => $application, true);
        $parent->set(DatabaseInterface::class, $this->createMock(DatabaseInterface::class));

        $container = $parent->createChild();
        $provider  = require JPATH_ADMINISTRATOR . '/components/com_autosave/services/provider.php';
        $provider->register($container);

        $this->assertFalse($container->has('app'));
        $this->assertInstanceOf(AutosaveStorageInterface::class, $container->get(AutosaveStorageInterface::class));

        $lifecycle = $container->get(AutosaveLifecycle::class);
        $component = $container->get(ComponentInterface::class);

        $this->assertInstanceOf(AutosaveLifecycle::class, $lifecycle);
        $this->assertInstanceOf(AutosaveComponent::class, $component);
        $this->assertInstanceOf(Dispatcher::class, $component->getDispatcher($application));

        $resolverProperty    = new \ReflectionProperty($lifecycle, 'resolver');
        $resolver            = $resolverProperty->getValue($lifecycle);
        $applicationProperty = new \ReflectionProperty(AutosaveContextResolver::class, 'application');

        $this->assertSame($application, $applicationProperty->getValue($resolver));
    }
}
