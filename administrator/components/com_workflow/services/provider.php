<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Workflow\Administrator\Autosave\StageAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\WorkflowAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Extension\WorkflowComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The workflow service provider.
 *
 * @since  4.0.0
 */
return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function register(Container $container)
    {
        $container->registerServiceProvider(new MVCFactory('\\Joomla\\Component\\Workflow'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Joomla\\Component\\Workflow'));
        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new WorkflowComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                $workflow = new WorkflowAutosaveProvider($container->get(DatabaseInterface::class));
                $stage    = new StageAutosaveProvider($container->get(DatabaseInterface::class));
                $component->setAutosaveProvider($workflow->getContext(), $workflow);
                $component->setAutosaveProvider($stage->getContext(), $stage);
                return $component;
            }
        );
    }
};
