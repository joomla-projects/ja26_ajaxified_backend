<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Autosave administrator component service provider.
 *
 * @since  __DEPLOY_VERSION__
 */
return new class () implements ServiceProviderInterface {
    /**
     * Register the component-local Autosave stack.
     *
     * @param   Container  $container  The component child container.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function register(Container $container)
    {
        $container->share(
            DraftService::class,
            static fn (Container $container): DraftService => new DraftService(
                new AutosaveContextResolver($container->get(AdministratorApplication::class)),
                new DraftStore($container->get(DatabaseInterface::class))
            )
        );
        $container->share(
            ComponentInterface::class,
            static fn (Container $container): ComponentInterface => new AutosaveComponent(
                $container->get(DraftService::class)
            )
        );
    }
};
