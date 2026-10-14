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
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\Component\Autosave\Administrator\Dispatcher\Dispatcher;
use Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

// phpcs:disable PSR1.Files.SideEffects
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
    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;

    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousApplication = \Joomla\CMS\Factory::$application;
    }

    protected function tearDown(): void
    {
        \Joomla\CMS\Factory::$application = $this->previousApplication;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    public function testCanonicalAdministratorApplicationAliasBuildsCompleteStack(): void
    {
        $application = $this->getMockBuilder(AdministratorApplication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInput', 'get'])
            ->getMock();
        $application->method('getInput')->willReturn(new Input());
        \Joomla\CMS\Factory::$application = $application;

        $parent = new Container();
        $parent->alias(AdministratorApplication::class, 'JApplicationAdministrator')
            ->share('JApplicationAdministrator', static fn (): AdministratorApplication => $application, true);
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects($this->never())->method('getTableList');
        $database->expects($this->never())->method('createQuery');
        $parent->set(DatabaseInterface::class, $database);

        $container = $parent->createChild();
        $provider  = require JPATH_ADMINISTRATOR . '/components/com_autosave/services/provider.php';
        $provider->register($container);

        $this->assertFalse($container->has('app'));
        $service   = $container->get(DraftService::class);
        $component = $container->get(ComponentInterface::class);

        $this->assertInstanceOf(AutosaveComponent::class, $component);
        $this->assertSame($service, $component->getDraftService());
        $this->assertInstanceOf(Dispatcher::class, $component->getDispatcher($application));
    }
}
