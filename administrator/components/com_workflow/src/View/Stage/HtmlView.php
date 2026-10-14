<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\View\Stage;

use Joomla\CMS\Autosave\DraftView;
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

    public string $autosaveStatusId = 'stage-autosave-status';
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
        if (!\in_array($this->getLayout(), ['edit', 'modal'], true)) {
            return;
        }
        $item                  = clone $this->item;
        $item->draftExtension  = (string) $this->state->get('filter.extension');
        if (empty($item->id)) {
            $item->workflow_id = Factory::getApplication()->getInput()->getInt('workflow_id');
        }
        $this->form->addControlField('workflow_id', (string) $item->workflow_id);
        $this->form->addControlField('extension', $item->draftExtension);
        $this->autosaveEnabled = DraftView::configure(Factory::getApplication(), $this->getDocument(), $this->getCurrentUser(), $this->form, $item, [
            'context'    => 'com_workflow.stage', 'formId' => 'workflow-form', 'statusId' => $this->autosaveStatusId,
            'optionsKey' => 'com_workflow.autosave.stage', 'script' => 'com_workflow.stage-autosave',
            'saveTasks'  => ['stage.apply', 'stage.save', 'stage.save2new', 'stage.save2copy'],
        ]);
        if ($this->autosaveEnabled) {
            DraftView::addStatus($this->getDocument()->getToolbar(), $this->autosaveStatusId);
        }
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
