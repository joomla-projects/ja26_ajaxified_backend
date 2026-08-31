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
use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\Autosave\AutosaveStorage;
use Joomla\CMS\Autosave\AutosaveStorageInterface;
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
            AutosaveStorageInterface::class,
            static fn (Container $container): AutosaveStorageInterface => new AutosaveStorage(
                $container->get(DatabaseInterface::class),
                AutosaveComponent::STORAGE_POLICY
            )
        );
        $container->share(
            AutosaveLifecycle::class,
            static fn (Container $container): AutosaveLifecycle => new AutosaveLifecycle(
                new AutosaveContextResolver($container->get(AdministratorApplication::class)),
                $container->get(AutosaveStorageInterface::class),
                (string) $container->get(AdministratorApplication::class)->get('secret')
            )
        );
        $container->share(
            ComponentInterface::class,
            static fn (Container $container): ComponentInterface => new AutosaveComponent(
                $container->get(AutosaveLifecycle::class)
            )
        );
    }
};
