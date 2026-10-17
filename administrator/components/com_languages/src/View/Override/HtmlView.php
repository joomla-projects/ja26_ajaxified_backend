<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_languages
 *
 * @copyright   (C) 2011 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Languages\Administrator\View\Override;

use Joomla\CMS\Autosave\DraftView;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Languages\Administrator\Model\OverrideModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View to edit a language override
 *
 * @since  2.5
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
    public string $autosaveStatusId = 'override-autosave-status';

    /**
     * The form to use for the view.
     *
     * @var     object
     * @since   2.5
     */
    protected $form;

    /**
     * The item to edit.
     *
     * @var     object
     * @since   2.5
     */
    protected $item;

    /**
     * The model state.
     *
     * @var     object
     * @since   2.5
     */
    protected $state;

    /**
     * Displays the view.
     *
     * @param   string  $tpl  The name of the template file to parse
     *
     * @return  void
     *
     * @since   2.5
     */
    public function display($tpl = null)
    {
        /** @var OverrideModel $model */
        $model = $this->getModel();
        $model->setUseExceptions(true);

        $this->form  = $model->getForm();
        $this->item  = $model->getItem();
        $this->state = $model->getState();

        $app = Factory::getApplication();

        $languageClient = $app->getUserStateFromRequest('com_languages.overrides.language_client', 'language_client');

        if ($languageClient == null) {
            $app->enqueueMessage(Text::_('COM_LANGUAGES_OVERRIDE_FIRST_SELECT_MESSAGE'), 'warning');

            $app->redirect('index.php?option=com_languages&view=overrides');
        }

        // Check whether the cache has to be refreshed.
        $cached_time = Factory::getApplication()->getUserState(
            'com_languages.overrides.cachedtime.' . $this->state->get('filter.client') . '.' . $this->state->get('filter.language'),
            0
        );

        if (time() - $cached_time > 60 * 5) {
            $this->state->set('cache_expired', true);
        }

        // Add strings for translations in \Javascript.
        Text::script('COM_LANGUAGES_VIEW_OVERRIDE_NO_RESULTS');
        Text::script('COM_LANGUAGES_VIEW_OVERRIDE_REQUEST_ERROR');

        // Add form control fields
        $this->form
            ->addControlField('task')
            ->addControlField('id', $this->item->key)
            ->addControlField('filter_client', $this->state->get('filter.client') === 'administrator' ? 1 : 0)
            ->addControlField('filter_language', $this->state->get('filter.language'));

        $this->prepareAutosave();

        $this->addToolbar();
        if ($this->autosaveEnabled) {
            DraftView::addStatus($this->getDocument()->getToolbar(), $this->autosaveStatusId);
        }
        parent::display($tpl);
    }

    /**
     * Prepare draft controls for this form.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function prepareAutosave(): void
    {
        if (!\in_array($this->getLayout(), ['edit', 'modal'], true)) {
            return;
        }
        $item                  = clone $this->item;
        $item->client          = (string) $this->state->get('filter.client');
        $item->language        = (string) $this->state->get('filter.language');
        try {
            $this->autosaveEnabled = Factory::getContainer()->get(DraftView::class)->configure(
                Factory::getApplication(),
                $this->getDocument(),
                $this->getCurrentUser(),
                $this->form,
                $item,
                [
                    'context'   => 'com_languages.override',
                    'formId'    => 'override-form',
                    'statusId'  => $this->autosaveStatusId,
                    'saveTasks' => ['override.apply', 'override.save', 'override.save2new'],
                ]
            );
        } catch (\Throwable) {
            // Draft setup is optional; keep the form and its save actions available.
            $this->autosaveEnabled = false;
        }
    }


    /**
     * Adds the page title and toolbar.
     *
     * @return void
     *
     * @since   2.5
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $canDo   = ContentHelper::getActions('com_languages');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_LANGUAGES_VIEW_OVERRIDE_EDIT_TITLE'), 'comments langmanager');

        if ($canDo->get('core.edit')) {
            $toolbar->apply('override.apply');
        }

        $saveGroup = $toolbar->dropdownButton('save-group');

        $saveGroup->configure(
            function (Toolbar $childBar) use ($canDo) {
                if ($canDo->get('core.edit')) {
                    $childBar->save('override.save');
                }

                // This component does not support Save as Copy.
                if ($canDo->get('core.edit') && $canDo->get('core.create')) {
                    $childBar->save2new('override.save2new');
                }
            }
        );

        if (empty($this->item->key)) {
            $toolbar->cancel('override.cancel', 'JTOOLBAR_CANCEL');
        } else {
            $toolbar->cancel('override.cancel');
        }

        $toolbar->divider();
        $toolbar->help('Languages:_Edit_Override');
    }
}
