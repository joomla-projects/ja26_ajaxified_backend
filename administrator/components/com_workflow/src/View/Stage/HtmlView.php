<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\View\Stage;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Workflow\Administrator\Helper\StageHelper;
use Joomla\Component\Workflow\Administrator\Model\StageModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View class to add or edit a stage of a workflow
 *
 * @since  4.0.0
 */
class HtmlView extends BaseHtmlView
{
    public bool $autosaveEnabled = false;
    /**
     * The model state
     *
     * @var     object
     * @since   4.0.0
     */
    protected $state;

    /**
     * From object to generate fields
     *
     * @var    \Joomla\CMS\Form\Form
     *
     * @since  4.0.0
     */
    protected $form;

    /**
     * Items array
     *
     * @var    object
     * @since  4.0.0
     */
    protected $item;

    /**
     * The name of current extension
     *
     * @var     string
     * @since   4.0.0
     */
    protected $extension;

    /**
     * The section of the current extension
     *
     * @var    string
     * @since  4.0.0
     */
    protected $section;

    /**
     * Display item view
     *
     * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
     *
     * @return  void
     *
     * @since  4.0.0
     */
    public function display($tpl = null)
    {
        /** @var StageModel $model */
        $model = $this->getModel();
        $model->setUseExceptions(true);

        // Get the Data
        $this->state = $model->getState();
        $this->form  = $model->getForm();
        $this->item  = $model->getItem();

        $extension = $this->state->get('filter.extension');

        $parts = explode('.', $extension);

        $this->extension = array_shift($parts);

        if (!empty($parts)) {
            $this->section = array_shift($parts);
        }

        // Add form control fields
        $this->form
            ->addControlField('task', 'stage.edit');

        // Set the toolbar
        $this->addToolbar();
        $this->prepareAutosave();
        // Display the template
        parent::display($tpl);
    }

    private function prepareAutosave(): void
    {
        $app          = Factory::getApplication();
        $configurator = new AutosaveViewConfigurator($app, $this->getDocument(), $app->getIdentity());
        $configurator->disable('com_workflow.autosave.stage');
        if ($this->getLayout() !== 'edit') {
            return;
        }
        try {
            $provider = $app->bootComponent('com_workflow')->getAutosaveProvider('com_workflow.stage');
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
        $ids = [];
        foreach (['title', 'description'] as $name) {
            $field = $this->form->getField($name);
            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            } $ids[$name] = $field->id;
        }
        $configurator->configure($provider, $target, 'com_workflow.autosave.stage', 'workflow-form', $ids, 'com_workflow.stage-autosave');
        $this->autosaveEnabled = true;
    }
    /**
     * Add the page title and toolbar.
     *
     * @return  void
     *
     * @since  4.0.0
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user       = $this->getCurrentUser();
        $userId     = $user->id;
        $isNew      = empty($this->item->id);
        $toolbar    = $this->getDocument()->getToolbar();

        $canDo = StageHelper::getActions($this->extension, 'stage', $this->item->id);

        ToolbarHelper::title(empty($this->item->id) ? Text::_('COM_WORKFLOW_STAGE_ADD') : Text::_('COM_WORKFLOW_STAGE_EDIT'), 'address');

        if ($isNew) {
            // For new records, check the create permission.
            if ($canDo->get('core.create')) {
                $toolbar->apply('stage.apply');
            }

            $saveGroup = $toolbar->dropdownButton('save-group');
            $saveGroup->configure(
                function (Toolbar $childBar) use ($canDo) {
                    // For new records, check the create permission.
                    if ($canDo->get('core.create')) {
                        $childBar->save('stage.save');
                        $childBar->save2new('stage.save2new');
                    }
                }
            );

            $toolbar->cancel('stage.cancel', 'JTOOLBAR_CANCEL');
        } else {
            // Since it's an existing record, check the edit permission, or fall back to edit own if the owner.
            $itemEditable = $canDo->get('core.edit') || ($canDo->get('core.edit.own') && $this->item->created_by == $userId);

            if ($itemEditable) {
                $toolbar->apply('stage.apply');

                $saveGroup = $toolbar->dropdownButton('save-group');
                $saveGroup->configure(
                    function (Toolbar $childBar) use ($canDo) {
                        $childBar->save('stage.save');

                        // We can save this record, but check the create permission to see if we can return to make a new one.
                        if ($canDo->get('core.create')) {
                            $childBar->save2new('stage.save2new');
                            $childBar->save2copy('stage.save2copy');
                        }
                    }
                );
            }

            $toolbar->cancel('stage.cancel');
        }

        $toolbar->divider();
    }
}
