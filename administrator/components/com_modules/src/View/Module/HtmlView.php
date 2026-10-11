<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_modules
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Modules\Administrator\View\Module;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveProvider;
use Joomla\Component\Modules\Administrator\Model\ModuleModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View to edit a module.
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
        /** @var ModuleModel $model */
        $model = $this->getModel();
        $model->setUseExceptions(true);

        $this->state = $model->getState();

        // Have to stop it earlier, because on cancel task for a new module we do not have an ID, and Model doing redirect on getItem()
        if ($this->getLayout() === 'modalreturn' && !$this->state->get('module.id')) {
            parent::display($tpl);

            return;
        }

        $this->form  = $model->getForm();
        $this->item  = $model->getItem();
        $this->canDo = ContentHelper::getActions('com_modules', 'module', $this->item->id);

        if ($this->getLayout() === 'modalreturn') {
            parent::display($tpl);

            return;
        }

        $input          = Factory::getApplication()->getInput();
        $forcedLanguage = $input->get('forcedLanguage', '', 'cmd');

        // If we are forcing a language in modal (used for associations).
        if ($this->getLayout() === 'modal' && $forcedLanguage) {
            // Set the language field to the forcedLanguage and disable changing it.
            $this->form->setValue('language', null, $forcedLanguage);
            $this->form->setFieldAttribute('language', 'readonly', 'true');

            // Only allow to select categories with All language or with the forced language.
            $this->form->setFieldAttribute('parent_id', 'language', '*,' . $forcedLanguage);
        }

        // Add form control fields
        $this->form
            ->addControlField('task')
            ->addControlField('return', Factory::getApplication()->getInput()->getBase64('return', ''));

        $this->prepareAutosave();

        if ($this->getLayout() !== 'modal') {
            $this->addToolbar();
        } else {
            $this->addModalToolbar();
        }

        parent::display($tpl);
    }

    private function prepareAutosave(): void
    {
        $app          = Factory::getApplication();
        $configurator = new AutosaveViewConfigurator($app, $this->getDocument(), $app->getIdentity());
        $configurator->disable('com_modules.autosave.module');

        if (!\in_array($this->getLayout(), ['edit', 'default'], true)) {
            return;
        }

        $target      = null;
        $createScope = null;
        $schema      = null;
        $itemId      = (int) $this->item->id;

        try {
            $provider = $app->bootComponent('com_modules')->getAutosaveProvider('com_modules.module');

            if (!$provider instanceof ModuleAutosaveProvider) {
                return;
            }

            if ($itemId > 0) {
                $target = $provider->canonicalizeTargetId((string) $itemId);

                if (!$provider->targetExists($target)) {
                    return;
                }

                $schema = $provider->getDynamicSchemaForForm($target, $this->form);
            } elseif ($itemId !== 0 || !$provider instanceof AutosaveCreateProviderInterface) {
                return;
            } elseif ($provider instanceof AutosaveDynamicCreateDescriptorProviderInterface) {
                // A new Module only gains create mode once the module-type chooser has
                // bound a genuine extension: the model then carries the module element
                // and client as server state. The candidate is canonicalized and
                // authorized once at render; the browser never resubmits authoritative
                // creation state afterwards.
                $candidate = $provider->createScopeCandidate(
                    (int) $this->item->client_id,
                    (string) $this->item->module
                );

                if ($candidate === null) {
                    return;
                }

                $createScope = $provider->canonicalizeStaticCreateScope($candidate);
                $provider->authorizeStaticCreateScope($app->getIdentity(), $createScope, AutosaveOperation::InitializeCreate, null);
                $schema = $provider->getDynamicSchemaForScope($createScope);
            } else {
                $provider->authorizeCreate($app->getIdentity(), AutosaveOperation::InitializeCreate, null);
            }
        } catch (\Throwable) {
            return;
        }

        if ($schema === null) {
            return;
        }

        $ids = [];

        foreach (['title', 'note', 'version_note', 'showtitle', 'position', 'content'] as $name) {
            $field = $this->form->getField($name);

            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            }

            $ids[$name] = $field->id;
        }

        $configurator->configure(
            $provider,
            $target,
            'com_modules.autosave.module',
            'module-form',
            $ids,
            'com_modules.module-autosave',
            ['fields' => $schema->fields(), 'fingerprint' => $schema->fingerprint(), 'support' => $schema->support()],
            $createScope
        );
        $this->autosaveEnabled = true;
    }

    /**
     * Add the page title and toolbar.
     *
     * @return  void
     *
     * @since   1.6
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user       = $this->getCurrentUser();
        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $user->id);
        $canDo      = $this->canDo;
        $toolbar    = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::sprintf('COM_MODULES_MANAGER_MODULE', Text::_($this->item->module)), 'cube module');

        // For new records, check the create permission.
        if ($isNew && $canDo->get('core.create')) {
            $toolbar->apply('module.apply');

            $saveGroup = $toolbar->dropdownButton('save-group');

            $saveGroup->configure(
                function (Toolbar $childBar) {
                    $childBar->save('module.save');
                    $childBar->save2new('module.save2new');
                }
            );

            $toolbar->cancel('module.cancel', 'JTOOLBAR_CANCEL');
        } else {
            // Can't save the record if it's checked out.
            if (!$checkedOut && $canDo->get('core.edit')) {
                $toolbar->apply('module.apply');
            }

            $saveGroup = $toolbar->dropdownButton('save-group');

            $saveGroup->configure(
                function (Toolbar $childBar) use ($checkedOut, $canDo) {
                    // Can't save the record if it's checked out. Since it's an existing record, check the edit permission.
                    if (!$checkedOut && $canDo->get('core.edit')) {
                        $childBar->save('module.save');

                        // We can save this record, but check the create permission to see if we can return to make a new one.
                        if ($canDo->get('core.create')) {
                            $childBar->save2new('module.save2new');
                        }
                    }

                    // If checked out, we can still save
                    if ($canDo->get('core.create')) {
                        $childBar->save2copy('module.save2copy');
                    }
                }
            );

            $toolbar->cancel('module.cancel');

            if (ComponentHelper::isEnabled('com_contenthistory') && $this->state->get('params')->get('save_history', 0) && $canDo->get('core.edit')) {
                $toolbar->versions('com_modules.module', $this->item->id);
            }

            if (Associations::isEnabled() && ComponentHelper::isEnabled('com_associations') && $this->item->client_id === 0) {
                $toolbar->standardButton('associations', 'JTOOLBAR_ASSOCIATIONS', 'module.editAssociations')
                    ->icon('icon-contract')
                    ->listCheck(false);
            }
        }

        // Get the help information for the menu item.
        $lang = $this->getLanguage();

        /** @var ModuleModel $model */
        $model = $this->getModel();
        $help  = $model->getHelp();

        if ($lang->hasKey($help->url)) {
            $debug = $lang->setDebug(false);
            $url   = Text::_($help->url);
            $lang->setDebug($debug);
        } else {
            $url = null;
        }

        $toolbar->inlinehelp();
        $toolbar->help($help->key, false, $url);
    }

    /**
     * Add the modal toolbar.
     *
     * @return  void
     *
     * @since   5.1.0
     *
     * @throws  \Exception
     */
    protected function addModalToolbar()
    {
        $isNew   = ($this->item->id == 0);
        $toolbar = $this->getDocument()->getToolbar();
        $canDo   = $this->canDo;

        ToolbarHelper::title(Text::sprintf('COM_MODULES_MANAGER_MODULE', Text::_($this->item->module)), 'cube module');

        $canCreate = $isNew && $canDo->get('core.create');
        $canEdit   = $canDo->get('core.edit');

        // For new records, check the create permission.
        if ($canCreate || $canEdit) {
            $toolbar->apply('module.apply');
            $toolbar->save('module.save');
        }

        $toolbar->cancel('module.cancel');

        $toolbar->inlinehelp();
    }
}
