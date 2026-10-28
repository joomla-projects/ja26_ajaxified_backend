<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\System\Autosave\Extension;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Plugin\System\Autosave\Extension\Autosave;
use Joomla\Tests\Unit\UnitTestCase;

/** Native plugin composition must remain lazy on unrelated application requests. */
class AutosaveServiceProviderTest extends UnitTestCase
{
    public function testNativePluginBootDoesNotResolveDraftServicesOrQueryDatabase(): void
    {
        $previousApplication = Factory::$application;
        $plugins             = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $previousPlugins     = $plugins->getValue();
        try {
            $plugins->setValue(null, [(object) ['type' => 'system', 'name' => 'autosave', 'params' => '{"interval":45}', 'id' => 1]]);
            $application          = $this->createMock(AdministratorApplication::class);
            Factory::$application = $application;
            $container            = new Container();
            $database             = $this->createMock(DatabaseInterface::class);
            $database->expects($this->never())->method('createQuery');
            $database->expects($this->never())->method('getTableList');
            $container->set(DatabaseInterface::class, $database);
            $container->registerServiceProvider(new \Joomla\CMS\Service\Provider\Autosave());
            $container->share(DraftService::class, static function (): void {
                throw new \LogicException('Unrelated requests must not resolve the draft service.');
            });
            $child    = $container->createChild();
            $provider = require JPATH_PLUGINS . '/system/autosave/services/provider.php';
            $provider->register($child);
            $this->assertInstanceOf(Autosave::class, $child->get(PluginInterface::class));
            $this->assertFalse($child->has('app'));
            $this->assertSame(['onAjaxAutosave' => 'onAjaxAutosave'], Autosave::getSubscribedEvents());
        } finally {
            Factory::$application = $previousApplication;
            $plugins->setValue(null, $previousPlugins);
        }
    }
}
