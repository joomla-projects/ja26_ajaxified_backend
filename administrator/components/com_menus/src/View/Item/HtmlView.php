<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Menus\Administrator\View\Item;

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
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveProvider;
use Joomla\Component\Menus\Administrator\Model\ItemModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The HTML Menus Menu Item View.
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
     * @var   \stdClass
     */
    protected $item;

    /**
     * @var  mixed
     */
    protected $modules;

    /**
     * The model state
     *
     * @var   \Joomla\Registry\Registry
     */
    protected $state;

    /**
     * The actions the user is authorised to perform
     *
     * @var    \Joomla\Registry\Registry
     * @since  3.7.0
     */
    protected $canDo;

    /**
     * A list of view levels containing the id and title of the view level
     *
     * @var    \stdClass[]
     * @since  4.0.0
     */
    protected $levels;

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
     *
     * @since   1.6
     */
    public function display($tpl = null)
    {
        /** @var ItemModel $model */
        $model = $this->getModel();
        $model->setUseExceptions(true);

        $this->state   = $model->getState();
        $this->form    = $model->getForm();
        $this->item    = $model->getItem();
        $this->modules = $model->getModules();
        $this->levels  = $model->getViewLevels();
        $this->canDo   = ContentHelper::getActions('com_menus', 'menu', (int) $this->state->get('item.menutypeid'));

        // Check if we're allowed to edit this item
        // No need to check for create, because then the moduletype select is empty
        if (!empty($this->item->id) && !$this->canDo->get('core.edit')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

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
            ->addControlField('forcedLanguage', $forcedLanguage)
            ->addControlField('menutype', $input->get('menutype', ''))
            ->addControlField('fieldtype', '', ['id' => 'fieldtype']);

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
        $configurator->disable('com_menus.autosave.item');

        if (!\in_array($this->getLayout(), ['edit', 'default'], true)) {
            return;
        }

        $target      = null;
        $createScope = null;
        $schema      = null;
        $itemId      = (int) $this->item->id;

        try {
            $provider = $app->bootComponent('com_menus')->getAutosaveProvider('com_menus.item');

            if (!$provider instanceof ItemAutosaveProvider) {
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
                // A new Menu Item only gains create mode once a genuine type has been
                // chosen. The native type selection binds the routed link, client and
                // target menu into the editor through the session form data that
                // loadFormData() merges into the form, and getItem() echoes them onto
                // the model state/item. None of those carriers alone is guaranteed to
                // be re-seeded on every create-mode render, so the candidate falls
                // back from the model state to the loaded item and finally to the
                // bound form values (mirroring com_modules and com_fields). It is
                // canonicalized and authorized once at render; the browser never
                // resubmits authoritative creation state afterwards.
                $candidate = $provider->createScopeCandidate(
                    $this->candidateClientId(),
                    $this->candidateString('item.menutype', 'menutype'),
                    // The loaded item cannot speak for the type: getItem() coerces an
                    // unchosen type to 'component', which would shadow a genuine
                    // special type bound in the form data.
                    $this->candidateString('item.type', 'type', false),
                    $this->candidateString('item.link', 'link')
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

        foreach (['title', 'alias', 'note', 'browserNav'] as $name) {
            $field = $this->form->getField($name);

            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            }

            $ids[$name] = $field->id;
        }

        $configurator->configure(
            $provider,
            $target,
            'com_menus.autosave.item',
            'item-form',
            $ids,
            'com_menus.item-autosave',
            ['fields' => $schema->fields(), 'fingerprint' => $schema->fingerprint()],
            $createScope
        );
        $this->autosaveEnabled = true;
    }

    /**
     * Resolve the create-editor client id from the model state, the loaded item
     * and finally the bound form values.
     *
     * @return  int
     *
     * @since   __DEPLOY_VERSION__
     */
    private function candidateClientId(): int
    {
        $clientId = $this->state->get('item.client_id', null);

        if ($clientId === null && isset($this->item->client_id)) {
            $clientId = $this->item->client_id;
        }

        if ($clientId === null) {
            $clientId = $this->form->getValue('client_id');
        }

        return (int) $clientId;
    }

    /**
     * Resolve one create-editor candidate value from the model state, optionally
     * the loaded item, and finally the bound form values.
     *
     * @param   string   $stateKey    The model state key.
     * @param   string   $property    The item property and form field name.
     * @param   boolean  $fromItem    Consult the loaded item before the form.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function candidateString(string $stateKey, string $property, bool $fromItem = true): string
    {
        $value = (string) $this->state->get($stateKey, '');

        if ($value === '' && $fromItem && isset($this->item->{$property}) && $this->item->{$property} !== null) {
            $value = (string) $this->item->{$property};
        }

        if ($value === '') {
            $value = (string) $this->form->getValue($property, null, '');
        }

        return $value;
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
        $input = Factory::getApplication()->getInput();
        $input->set('hidemainmenu', true);

        $user       = $this->getCurrentUser();
        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $user->id);
        $canDo      = $this->canDo;
        $clientId   = $this->state->get('item.client_id', 0);
        $toolbar    = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_($isNew ? 'COM_MENUS_VIEW_NEW_ITEM_TITLE' : 'COM_MENUS_VIEW_EDIT_ITEM_TITLE'), 'list menu-add');

        // If a new item, can save the item.  Allow users with edit permissions to apply changes to prevent returning to grid.
        if ($isNew && $canDo->get('core.create')) {
            if ($canDo->get('core.edit')) {
                $toolbar->apply('item.apply');
            }
        }

        // If not checked out, can save the item.
        if (!$isNew && !$checkedOut && $canDo->get('core.edit')) {
            $toolbar->apply('item.apply');
        }

        $saveGroup = $toolbar->dropdownButton('save-group');

        $saveGroup->configure(
            function (Toolbar $childBar) use ($isNew, $checkedOut, $canDo) {
                // If a new item, can save the item.  Allow users with edit permissions to apply changes to prevent returning to grid.
                if ($isNew && $canDo->get('core.create')) {
                    $childBar->save('item.save');
                }

                // If not checked out, can save the item.
                if (!$isNew && !$checkedOut && $canDo->get('core.edit')) {
                    $childBar->save('item.save');
                }

                // If the user can create new items, allow them to see Save & New
                if ($canDo->get('core.create')) {
                    $childBar->save2new('item.save2new');
                }

                // If an existing item, can save to a copy only if we have create rights.
                if (!$isNew && $canDo->get('core.create')) {
                    $childBar->save2copy('item.save2copy');
                }
            }
        );

        if (!$isNew && Associations::isEnabled() && ComponentHelper::isEnabled('com_associations') && $clientId != 1) {
            $toolbar->standardButton('associations', 'JTOOLBAR_ASSOCIATIONS', 'item.editAssociations')
                ->icon('icon-contract')
                ->listCheck(false);
        }

        if ($isNew) {
            $toolbar->cancel('item.cancel', 'JTOOLBAR_CANCEL');
        } else {
            $toolbar->cancel('item.cancel');
        }

        $toolbar->divider();

        // Get the help information for the menu item.
        $lang = $this->getLanguage();

        /** @var ItemModel $model */
        $model = $this->getModel();
        $help  = $model->getHelp();

        if ($lang->hasKey($help->url)) {
            $debug = $lang->setDebug(false);
            $url   = Text::_($help->url);
            $lang->setDebug($debug);
        } else {
            $url = $help->url;
        }

        $toolbar->inlinehelp();
        $toolbar->help($help->key, $help->local, $url);
    }

    /**
     * Add the modal toolbar.
     *
     * @return  void
     *
     * @since   5.0.0
     *
     * @throws  \Exception
     */
    protected function addModalToolbar()
    {
        $user       = $this->getCurrentUser();
        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $user->id);
        $canDo      = $this->canDo;
        $toolbar    = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_($isNew ? 'COM_MENUS_VIEW_NEW_ITEM_TITLE' : 'COM_MENUS_VIEW_EDIT_ITEM_TITLE'), 'list menu-add');

        $canSave = !$checkedOut && ($isNew && $canDo->get('core.create') || $canDo->get('core.edit'));

        // For new records, check the create permission.
        if ($canSave) {
            $toolbar->apply('item.apply');
            $toolbar->save('item.save');
        }

        $toolbar->cancel('item.cancel');
    }
}
