<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_languages
 *
 * @copyright   (C) 2011 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Languages\Administrator\View\Override;

use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Languages\Administrator\Autosave\OverrideAutosaveProvider;
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
    public bool $autosaveEnabled = false;
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
            ->addControlField('id', $this->item->key);

        $this->addToolbar();
        $this->prepareAutosave();
        parent::display($tpl);
    }

    private function prepareAutosave(): void
    {
        $app          = Factory::getApplication();
        $configurator = new AutosaveViewConfigurator($app, $this->getDocument(), $app->getIdentity());
        $configurator->disable('com_languages.autosave.override');
        if ($this->getLayout() !== 'edit' || empty($this->item->key)) {
            return;
        }

        $client   = (string) $this->state->get('filter.client', 'site');
        $language = (string) $this->state->get('filter.language', 'en-GB');
        try {
            $provider = $app->bootComponent('com_languages')->getAutosaveProvider('com_languages.override');
            $target   = $provider->canonicalizeTargetId(OverrideAutosaveProvider::target($client, $language, (string) $this->item->key));
            if (!$provider->targetExists($target)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $fields = ['key' => $this->form->getField('key'), 'override' => $this->form->getField('override'), 'both' => $this->form->getField('both')];
        if (array_filter($fields, static fn ($field) => !$field || !\is_string($field->id) || $field->id === '')) {
            return;
        }

        $configurator->configure($provider, $target, 'com_languages.autosave.override', 'override-form', array_map(static fn ($field) => $field->id, $fields), 'com_languages.override-autosave');
        $this->autosaveEnabled = true;
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
