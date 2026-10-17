<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_tags
 *
 * @copyright   (C) 2013 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Tags\Administrator\View\Tag;

use Joomla\CMS\Autosave\DraftView;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Tags\Administrator\Model\TagModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * HTML View class for the Tags component
 *
 * @since  3.1
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
    public string $autosaveStatusId = 'tag-autosave-status';
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
     * The model state
     *
     * @var  \Joomla\Registry\Registry
     */
    protected $state;

    /**
     * Flag if an association exists
     *
     * @var  boolean
     */
    protected $assoc;

    /**
     * The actions the user is authorised to perform
     *
     * @var    \Joomla\Registry\Registry
     *
     * @since  4.0.0
     */
    protected $canDo;

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
        /** @var TagModel $model */
        $model = $this->getModel();
        $model->setUseExceptions(true);

        $this->form  = $model->getForm();
        $this->item  = $model->getItem();
        $this->state = $model->getState();

        // Add form control fields
        $this->form
            ->addControlField('task');
        $this->addToolbar();
        $this->prepareAutosave();

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
        try {
            $this->autosaveEnabled = Factory::getContainer()->get(DraftView::class)->configure(Factory::getApplication(), $this->getDocument(), $this->getCurrentUser(), $this->form, $this->item, [
                'context'   => 'com_tags.tag', 'formId' => 'item-form', 'statusId' => $this->autosaveStatusId,
                'saveTasks' => ['tag.apply', 'tag.save', 'tag.save2new', 'tag.save2copy'],
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
     * @since  3.1
     *
     * @return void
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user       = $this->getCurrentUser();
        $userId     = $user->id;
        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);
        $toolbar    = $this->getDocument()->getToolbar();

        $canDo = ContentHelper::getActions('com_tags');

        ToolbarHelper::title($isNew ? Text::_('COM_TAGS_MANAGER_TAG_NEW') : Text::_('COM_TAGS_MANAGER_TAG_EDIT'), 'tag');

        // Build the actions for new and existing records.
        if ($isNew) {
            $toolbar->apply('tag.apply');
            $saveGroup = $toolbar->dropdownButton('save-group');

            $saveGroup->configure(
                function (Toolbar $childBar) {
                    $childBar->save('tag.save');
                    $childBar->save2new('tag.save2new');
                }
            );

            $toolbar->cancel('tag.cancel', 'JTOOLBAR_CANCEL');
        } else {
            // Since it's an existing record, check the edit permission, or fall back to edit own if the owner.
            $itemEditable = $canDo->get('core.edit') || ($canDo->get('core.edit.own') && $this->item->created_user_id == $userId);

            // Can't save the record if it's checked out and editable
            if (!$checkedOut && $itemEditable) {
                $toolbar->apply('tag.apply');
            }

            $saveGroup = $toolbar->dropdownButton('save-group');

            $saveGroup->configure(
                function (Toolbar $childBar) use ($checkedOut, $itemEditable, $canDo) {
                    // Can't save the record if it's checked out and editable
                    if (!$checkedOut && $itemEditable) {
                        $childBar->save('tag.save');

                        // We can save this record, but check the create permission to see if we can return to make a new one.
                        if ($canDo->get('core.create')) {
                            $childBar->save2new('tag.save2new');
                        }
                    }

                    // If checked out, we can still save
                    if ($canDo->get('core.create')) {
                        $childBar->save2copy('tag.save2copy');
                    }
                }
            );

            $toolbar->cancel('tag.cancel');

            if (ComponentHelper::isEnabled('com_contenthistory') && $this->state->get('params')->get('save_history', 0) && $itemEditable) {
                $toolbar->versions('com_tags.tag', $this->item->id);
            }
        }

        $toolbar->divider();
        $toolbar->help('Tags:_New_or_Edit');
    }
}
