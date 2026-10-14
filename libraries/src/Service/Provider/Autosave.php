<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Service\Provider;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Autosave\DraftStore;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Lazy shared draft services; registration performs no component or database reads.
 *
 * @since  __DEPLOY_VERSION__
 */
class Autosave implements ServiceProviderInterface
{
    /**
     * Register the shared draft services in the application container.
     *
     * @param   Container  $container  The application container.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function register(Container $container)
    {
        $container->share(DraftStore::class, static fn (Container $container): DraftStore => new DraftStore($container->get(DatabaseInterface::class)));
        $container->share(
            DraftService::class,
            static fn (Container $container): DraftService => new DraftService(
                new AutosaveContextResolver($container->get(AdministratorApplication::class)),
                $container->get(DraftStore::class)
            )
        );
    }
}
