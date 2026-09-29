<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_banners
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Banners\Administrator\View\Banner;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Banners\Administrator\Model\BannerModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View to edit a banner.
 *
 * @since  1.5
 */
class HtmlView extends BaseHtmlView
{
    public bool $autosaveEnabled = false;

    /**
     * The Form object
     *
     * @var    Form
     * @since  1.5
     */
    protected $form;

    /**
     * The active item
     *
     * @var    object
     * @since  1.5
     */
    protected $item;

    /**
     * The model state
     *
     * @var    object
     * @since  1.5
     */
    protected $state;

    /**
     * Display the view
     *
     * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
     *
     * @return  void
     *
     * @since   1.5
     *
     * @throws  \Exception
     */
    public function display($tpl = null): void
    {
        /** @var BannerModel $model */
        $model       = $this->getModel();
        $this->form  = $model->getForm();
        $this->item  = $model->getItem();
        $this->state = $model->getState();

        // Check for errors.
        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        $this->addToolbar();

        // Add form control fields
        $this->form
            ->addControlField('task');

        $this->prepareAutosave();

        parent::display($tpl);
    }

    private function prepareAutosave(): void
    {
        $app          = Factory::getApplication();
        $configurator = new AutosaveViewConfigurator($app, $this->getDocument(), $app->getIdentity());
        $configurator->disable('com_banners.autosave.banner');

        if ($this->getLayout() !== 'edit') {
            return;
        }

        try {
            $provider = $app->bootComponent('com_banners')->getAutosaveProvider('com_banners.banner');
            $itemId   = (int) $this->item->id;
            $target   = null;

            if ($itemId > 0) {
                $target = $provider->canonicalizeTargetId((string) $itemId);
            } elseif ($itemId !== 0 || !$provider instanceof AutosaveCreateProviderInterface) {
                return;
            } else {
                $provider->authorizeCreate($app->getIdentity(), AutosaveOperation::InitializeCreate, null);
            }
            if ($target !== null && !$provider->targetExists($target)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $fieldGroups = [
            'name'             => null,
            'catid'            => null,
            'cid'              => null,
            'alias'            => null,
            'description'      => null,
            'type'             => null,
            'custombannercode' => null,
            'clickurl'         => null,
            'version_note'     => null,
            'publish_up'       => null,
            'publish_down'     => null,
            'imageurl'         => 'params',
            'width'            => 'params',
            'height'           => 'params',
            'alt'              => 'params',
            'metakey'          => null,
            'metakey_prefix'   => null,
            'own_prefix'       => null,
        ];
        $ids = [];

        foreach ($fieldGroups as $name => $group) {
            $field = $this->form->getField($name, $group);

            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            }

            $ids[$name] = $field->id;
        }

        $configurator->configure(
            $provider,
            $target,
            'com_banners.autosave.banner',
            'banner-form',
            $ids,
            'com_banners.banner-autosave'
        );
        $this->autosaveEnabled = true;
    }

    /**
     * Add the page title and toolbar.
     *
     * @return  void
     *
     * @since   1.6
     * @throws  \Exception
     */
    protected function addToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user       = $this->getCurrentUser();
        $userId     = $user->id;
        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);
        $toolbar    = $this->getDocument()->getToolbar();

        // Since we don't track these assets at the item level, use the category id.
        $canDo = ContentHelper::getActions('com_banners', 'category', $this->item->catid);

        ToolbarHelper::title($isNew ? Text::_('COM_BANNERS_MANAGER_BANNER_NEW') : Text::_('COM_BANNERS_MANAGER_BANNER_EDIT'), 'bookmark banners');

        // If not checked out, can save the item.
        if (!$checkedOut && ($canDo->get('core.edit') || \count($user->getAuthorisedCategories('com_banners', 'core.create')) > 0)) {
            $toolbar->apply('banner.apply');
        }

        $saveGroup = $toolbar->dropdownButton('save-group');

        $saveGroup->configure(
            function (Toolbar $childBar) use ($checkedOut, $canDo, $user, $isNew) {
                // If not checked out, can save the item.
                if (!$checkedOut && ($canDo->get('core.edit') || \count($user->getAuthorisedCategories('com_banners', 'core.create')) > 0)) {
                    $childBar->save('banner.save');

                    if ($canDo->get('core.create')) {
                        $childBar->save2new('banner.save2new');
                    }
                }

                // If an existing item, can save to a copy.
                if (!$isNew && $canDo->get('core.create')) {
                    $childBar->save2copy('banner.save2copy');
                }
            }
        );

        if (empty($this->item->id)) {
            $toolbar->cancel('banner.cancel', 'JTOOLBAR_CANCEL');
        } else {
            $toolbar->cancel('banner.cancel');

            if (ComponentHelper::isEnabled('com_contenthistory') && $this->state->get('params')->get('save_history', 0) && $canDo->get('core.edit')) {
                $toolbar->versions('com_banners.banner', $this->item->id);
            }
        }

        $toolbar->divider();
        $toolbar->help('Banners:_Edit');
    }
}
