<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_users
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Users\Administrator\View\Group;

use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Users\Administrator\Model\GroupModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View to edit a user group.
 *
 * @since  1.6
 */
class HtmlView extends BaseHtmlView
{
    public bool $autosaveEnabled = false;
    /**
     * The Form object
     *
     * @var  \Joomla\CMS\Form\Form
     */
    protected $form;

    /**
     * The item data.
     *
     * @var   object
     * @since 1.6
     */
    protected $item;

    /**
     * The model state.
     *
     * @var   \Joomla\Registry\Registry
     * @since 1.6
     */
    protected $state;

    /**
     * Array of fieldsets not to display
     *
     * @var    string[]
     *
     * @since  5.2.0
     */
    public $ignore_fieldsets = [];

    /**
     * Display the view
     *
     * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        /** @var GroupModel $model */
        $model = $this->getModel();

        $this->state = $model->getState();
        $this->item  = $model->getItem();
        $this->form  = $model->getForm();

        // Check for errors.
        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        // Add form control fields
        $this->form
            ->addControlField('task');

        $this->addToolbar();
        $this->prepareAutosave();
        parent::display($tpl);
    }

    private function prepareAutosave(): void
    {
        $app          = Factory::getApplication();
        $key          = 'com_users.autosave.group';
        $configurator = new AutosaveViewConfigurator($app, $this->getDocument(), $app->getIdentity());
        $configurator->disable($key);
        if ($this->getLayout() !== 'edit' || (int) $this->item->id <= 0) {
            return;
        }
        try {
            $provider = $app->bootComponent('com_users')->getAutosaveProvider('com_users.group');
            $target   = $provider->canonicalizeTargetId((string) (int) $this->item->id);
            if (!$provider->targetExists($target)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        $ids = [];
        foreach (['title'] as $fieldName) {
            $field = $this->form->getField($fieldName);
            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            } $ids[$fieldName] = $field->id;
        }
        $configurator->configure($provider, $target, $key, 'group-form', $ids, 'com_users.group-autosave');
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
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $isNew   = ($this->item->id == 0);
        $canDo   = ContentHelper::getActions('com_users');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_($isNew ? 'COM_USERS_VIEW_NEW_GROUP_TITLE' : 'COM_USERS_VIEW_EDIT_GROUP_TITLE'), 'users-cog groups-add');

        if ($canDo->get('core.edit') || $canDo->get('core.create')) {
            $toolbar->apply('group.apply');
        }

        $saveGroup = $toolbar->dropdownButton('save-group');
        $saveGroup->configure(
            function (Toolbar $childBar) use ($canDo, $isNew) {
                if ($canDo->get('core.edit') || $canDo->get('core.create')) {
                    $childBar->save('group.save');
                }

                if ($canDo->get('core.create')) {
                    $childBar->save2new('group.save2new');
                }

                // If an existing item, can save to a copy.
                if (!$isNew && $canDo->get('core.create')) {
                    $childBar->save2copy('group.save2copy');
                }
            }
        );

        if (empty($this->item->id)) {
            $toolbar->cancel('group.cancel', 'JTOOLBAR_CANCEL');
        } else {
            $toolbar->cancel('group.cancel');
        }

        $toolbar->divider();
        $toolbar->help('Users:_New_or_Edit_Group');
    }
}
