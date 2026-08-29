<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_modules
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Association\AssociationExtensionInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveProvider;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveSchemaFactory;
use Joomla\Component\Modules\Administrator\Extension\ModulesComponent;
use Joomla\Component\Modules\Administrator\Helper\AssociationsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The module service provider.
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
        $container->set(AssociationExtensionInterface::class, new AssociationsHelper());

        $container->registerServiceProvider(new MVCFactory('\\Joomla\\Component\\Modules'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Joomla\\Component\\Modules'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new ModulesComponent($container->get(ComponentDispatcherFactoryInterface::class));

                $component->setRegistry($container->get(Registry::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                $component->setAssociationExtension($container->get(AssociationExtensionInterface::class));
                $provider = new ModuleAutosaveProvider(
                    $container->get(DatabaseInterface::class),
                    function (object $record) use ($container) {
                        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_modules/forms');
                        $model = $container->get(MVCFactoryInterface::class)
                            ->createModel('Module', 'Administrator', ['ignore_request' => true]);
                        $form = $model->getForm((array) $record, false);

                        return (new ModuleAutosaveSchemaFactory())->fromForm($form);
                    }
                );
                $component->setAutosaveProvider($provider->getContext(), $provider);

                return $component;
            }
        );
    }
};
