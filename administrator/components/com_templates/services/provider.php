<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Templates\Administrator\Autosave\StyleAutosaveProvider;
use Joomla\Component\Templates\Administrator\Autosave\StyleAutosaveSchemaFactory;
use Joomla\Component\Templates\Administrator\Extension\TemplatesComponent;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * The templates service provider.
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
        $container->registerServiceProvider(new MVCFactory('\\Joomla\\Component\\Templates'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Joomla\\Component\\Templates'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new TemplatesComponent($container->get(ComponentDispatcherFactoryInterface::class));

                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                $component->setRegistry($container->get(Registry::class));
                $provider = new StyleAutosaveProvider(
                    $container->get(DatabaseInterface::class),
                    function (object $record) use ($container) {
                        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_templates/forms');
                        $model = $container->get(MVCFactoryInterface::class)
                            ->createModel('Style', 'Administrator', ['ignore_request' => true]);
                        $form = $model->getForm((array) $record, false);

                        return (new StyleAutosaveSchemaFactory())->fromForm($form);
                    }
                );
                $component->setAutosaveProvider($provider->getContext(), $provider);

                return $component;
            }
        );
    }
};
