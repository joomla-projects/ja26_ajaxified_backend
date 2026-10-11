<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Autosave\AutosaveStorage;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\Task\Autosave\Extension\Autosave;

return new class () implements ServiceProviderInterface {
    /**
     * Register the Autosave cleanup task plugin.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(Autosave::class, function (Container $container) {
                $plugin = new Autosave(
                    (array) PluginHelper::getPlugin('task', 'autosave'),
                    new AutosaveStorage(
                        $container->get(DatabaseInterface::class),
                        AutosaveComponent::STORAGE_POLICY
                    )
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
