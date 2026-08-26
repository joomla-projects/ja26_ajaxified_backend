<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_contact
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Contact\Administrator\View\Contact;

use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\FormView;
use Joomla\CMS\Toolbar\ToolbarHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View to edit a contact.
 *
 * @since  1.6
 */
class HtmlView extends FormView
{
    public bool $autosaveEnabled = false;

    /**
     * Set to true, if saving to menu should be supported
     *
     * @var boolean
     */
    protected $supportSaveMenu = true;

    /**
     * Holds the extension for categories, if available
     *
     * @var string
     */
    protected $categorySection = 'com_contact';

    /**
     * Constructor
     *
     * @param   array  $config  An optional associative array of configuration settings.
     *
     * @since   6.0.0
     */
    public function __construct(array $config)
    {
        if (empty($config['option'])) {
            $config['option'] = 'com_contact';
        }

        $config['help_link']    = 'Contacts:_Edit';
        $config['toolbar_icon'] = 'address-book contact';

        parent::__construct($config);
    }

    /**
     * Prepare view data
     *
     * @return  void
     *
     * @since   6.0.0
     */
    protected function initializeView()
    {
        parent::initializeView();

        $this->canDo = ContentHelper::getActions('com_contact', 'category', $this->item->catid);

        if ($this->getLayout() === 'modalreturn') {
            return;
        }

        // If we are forcing a language in modal (used for associations).
        $forcedLanguage = Factory::getApplication()->getInput()->get('forcedLanguage', '', 'cmd');

        if ($this->getLayout() === 'modal' && $forcedLanguage) {
            // Set the language field to the forcedLanguage and disable changing it.
            $this->form->setValue('language', null, $forcedLanguage);
            $this->form->setFieldAttribute('language', 'readonly', 'true');

            // Only allow to select categories with All language or with the forced language.
            $this->form->setFieldAttribute('catid', 'language', '*,' . $forcedLanguage);

            // Only allow to select tags with All language or with the forced language.
            $this->form->setFieldAttribute('tags', 'language', '*,' . $forcedLanguage);
        }

        // Add form control fields
        $this->form
            ->addControlField('task')
            ->addControlField('forcedLanguage', $forcedLanguage);

        $this->prepareAutosave();
    }

    private function prepareAutosave(): void
    {
        $app          = Factory::getApplication();
        $configurator = new AutosaveViewConfigurator($app, $this->getDocument(), $app->getIdentity());
        $configurator->disable('com_contact.autosave.contact');

        if (!\in_array($this->getLayout(), ['edit', 'modal'], true) || (int) $this->item->id <= 0) {
            return;
        }

        try {
            $provider = $app->bootComponent('com_contact')->getAutosaveProvider('com_contact.contact');
            $target   = $provider->canonicalizeTargetId((string) (int) $this->item->id);

            if (!$provider->targetExists($target)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $names = [
            'name', 'alias', 'version_note', 'misc', 'image', 'con_position', 'email_to', 'address',
            'suburb', 'state', 'postcode', 'country', 'telephone', 'mobile', 'fax', 'webpage',
            'sortname1', 'sortname2', 'sortname3', 'publish_up', 'publish_down', 'metakey', 'metadesc',
        ];
        $ids = [];

        foreach ($names as $name) {
            $field = $this->form->getField($name);

            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            }

            $ids[$name] = $field->id;
        }

        $configurator->configure(
            $provider,
            $target,
            'com_contact.autosave.contact',
            'contact-form',
            $ids,
            'com_contact.contact-autosave'
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
        if ($this->getLayout() === 'modal') {
            $this->addModalToolbar();

            return;
        }

        parent::addToolbar();

        return;
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
        $user       = $this->getCurrentUser();
        $userId     = $user->id;
        $isNew      = ($this->item->id == 0);
        $toolbar    = $this->getDocument()->getToolbar();

        // Since we don't track these assets at the item level, use the category id.
        $canDo = ContentHelper::getActions('com_contact', 'category', $this->item->catid);

        ToolbarHelper::title($isNew ? Text::_('COM_CONTACT_MANAGER_CONTACT_NEW') : Text::_('COM_CONTACT_MANAGER_CONTACT_EDIT'), 'address-book contact');

        $canCreate = $isNew && (\count($user->getAuthorisedCategories('com_contact', 'core.create')) > 0);
        $canEdit   = $canDo->get('core.edit') || ($canDo->get('core.edit.own') && $this->item->created_by == $userId);

        // For new records, check the create permission.
        if ($canCreate || $canEdit) {
            $toolbar->apply('contact.apply');
            $toolbar->save('contact.save');
        }

        $toolbar->cancel('contact.cancel');
    }
}
