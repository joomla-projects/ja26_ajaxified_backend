<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.autosave
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\System\Autosave\Extension\Autosave;

return new class () implements ServiceProviderInterface {
    /** @since  __DEPLOY_VERSION__ */
    public function register(Container $container)
    {
        $container->set(PluginInterface::class, static function (Container $container): PluginInterface {
            $plugin = new Autosave(
                (array) PluginHelper::getPlugin('system', 'autosave'),
                static fn (): DraftService => $container->get(DraftService::class)
            );
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
