<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\Component\Autosave\Administrator\Extension\AutosaveComponent;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Autosave administrator component service provider.
 *
 * @since  __DEPLOY_VERSION__
 */
return new class () implements ServiceProviderInterface {
    /**
     * Inject the application's shared service into the endpoint component.
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
            ComponentInterface::class,
            static fn (Container $container): ComponentInterface => new AutosaveComponent(
                $container->get(DraftService::class)
            )
        );
    }
};
