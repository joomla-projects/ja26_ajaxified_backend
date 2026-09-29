<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
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
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveProvider;
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveSchemaFactory;
use Joomla\Component\Menus\Administrator\Autosave\MenuAutosaveProvider;
use Joomla\Component\Menus\Administrator\Extension\MenusComponent;
use Joomla\Component\Menus\Administrator\Helper\AssociationsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The menus service provider.
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

        $container->registerServiceProvider(new MVCFactory('\\Joomla\\Component\\Menus'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Joomla\\Component\\Menus'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new MenusComponent($container->get(ComponentDispatcherFactoryInterface::class));

                $component->setRegistry($container->get(Registry::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                $component->setAssociationExtension($container->get(AssociationExtensionInterface::class));
                $autosaveProvider = new MenuAutosaveProvider($container->get(DatabaseInterface::class));
                $component->setAutosaveProvider($autosaveProvider->getContext(), $autosaveProvider);
                $itemProvider = new ItemAutosaveProvider(
                    $container->get(DatabaseInterface::class),
                    function (object $record) use ($container) {
                        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_menus/forms');
                        $model = $container->get(MVCFactoryInterface::class)->createModel('Item', 'Administrator', ['ignore_request' => true]);
                        $model->setState('item.type', $record->type);
                        $model->setState('item.link', $record->link);
                        $model->setState('item.client_id', (int) $record->client_id);
                        $model->setState('item.menutype', $record->menutype);
                        $form = $model->getForm((array) $record, false);

                        if (!$form instanceof Form) {
                            throw new \RuntimeException('The authoritative Menu Item form is unavailable.');
                        }

                        return (new ItemAutosaveSchemaFactory())->fromForm($form);
                    }
                );
                $component->setAutosaveProvider($itemProvider->getContext(), $itemProvider);

                return $component;
            }
        );
    }
};
