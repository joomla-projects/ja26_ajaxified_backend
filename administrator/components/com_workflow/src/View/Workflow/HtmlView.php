<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\View\Workflow;

use Joomla\CMS\Autosave\DraftView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Workflow\Administrator\Helper\WorkflowHelper;
use Joomla\Component\Workflow\Administrator\Model\WorkflowModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View class to add or edit a workflow
 *
 * @since  4.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Whether draft controls are shown for this form.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $autosaveEnabled = false;

    /**
     * Layout ID for the draft status.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    public string $autosaveStatusId = 'workflow-autosave-status';
    /**
     * The model state
     *
     * @var    object
     * @since  4.0.0
     */
    protected $state;

    /**
     * The Form object
     *
     * @var  \Joomla\CMS\Form\Form
     */
    protected $form;

    /**
     * The active item
     *
     * @var  object
     */
    protected $item;

    /**
     * The ID of current workflow
     *
     * @var    integer
     * @since  4.0.0
     */
    protected $workflowID;

    /**
     * The name of current extension
     *
     * @var    string
     * @since  4.0.0
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
        /** @var WorkflowModel $model */
        $model = $this->getModel();
        $model->setUseExceptions(true);

        $this->state      = $model->getState();
        $this->form       = $model->getForm();
        $this->item       = $model->getItem();

        $extension = $this->state->get('filter.extension');

        $parts = explode('.', $extension);

        $this->extension = array_shift($parts);

        if (!empty($parts)) {
            $this->section = array_shift($parts);
        }

        // Add form control fields
        $this->form
            ->addControlField('task', 'workflow.edit');

        // Set the toolbar
        $this->addToolbar();
        $this->prepareAutosave();
        // Display the template
        parent::display($tpl);
    }

    /**
     * Configure optional drafts only for supported native edit layouts.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function prepareAutosave(): void
    {
        if (!\in_array($this->getLayout(), ['edit', 'modal'], true)) {
            return;
        }
        $item                  = clone $this->item;
        $item->draftExtension  = (string) $this->state->get('filter.extension');
        if (empty($item->id)) {
            $item->extension = $item->draftExtension;
        }
        $this->form->addControlField('extension', $item->draftExtension);
        try {
            $this->autosaveEnabled = Factory::getContainer()->get(DraftView::class)->configure(Factory::getApplication(), $this->getDocument(), $this->getCurrentUser(), $this->form, $item, [
                'context'   => 'com_workflow.workflow', 'formId' => 'workflow-form', 'statusId' => $this->autosaveStatusId,
                'saveTasks' => ['workflow.apply', 'workflow.save', 'workflow.save2new', 'workflow.save2copy'],
            ]);
        } catch (\Throwable) {
            // Draft setup is optional; keep the form and its save actions available.
            $this->autosaveEnabled = false;
        }
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
        $canDo      = WorkflowHelper::getActions($this->extension, 'workflow', $this->item->id);

        ToolbarHelper::title(empty($this->item->id) ? Text::_('COM_WORKFLOW_WORKFLOWS_ADD') : Text::_('COM_WORKFLOW_WORKFLOWS_EDIT'), 'address');

        if ($isNew) {
            // For new records, check the create permission.
            if ($canDo->get('core.create')) {
                $toolbar->apply('workflow.apply');

                $saveGroup = $toolbar->dropdownButton('save-group');

                $saveGroup->configure(
                    function (Toolbar $childBar) {
                        $childBar->save('workflow.save');
                        $childBar->save2new('workflow.save2new');
                    }
                );
            }

            $toolbar->cancel('workflow.cancel', 'JTOOLBAR_CANCEL');
        } else {
            // Since it's an existing record, check the edit permission, or fall back to edit own if the owner.
            $itemEditable = $canDo->get('core.edit') || ($canDo->get('core.edit.own') && $this->item->created_by == $userId);

            if ($itemEditable) {
                $toolbar->apply('workflow.apply');

                $saveGroup = $toolbar->dropdownButton('save-group');

                $saveGroup->configure(
                    function (Toolbar $childBar) use ($canDo) {
                        $childBar->save('workflow.save');

                        // We can save this record, but check the create permission to see if we can return to make a new one.
                        if ($canDo->get('core.create')) {
                            $childBar->save2new('workflow.save2new');
                            $childBar->save2copy('workflow.save2copy');
                        }
                    }
                );
            }

            $toolbar->cancel('workflow.cancel');
        }
    }
}
