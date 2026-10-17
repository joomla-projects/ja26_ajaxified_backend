<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_banners
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Banners\Administrator\View\Banner;

use Joomla\CMS\Autosave\DraftView;
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
    public string $autosaveStatusId = 'banner-autosave-status';

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
                'context'   => 'com_banners.banner', 'formId' => 'banner-form', 'statusId' => $this->autosaveStatusId,
                'saveTasks' => ['banner.apply', 'banner.save', 'banner.save2new', 'banner.save2copy'],
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
