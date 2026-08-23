<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_content
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Content\Administrator\View\Article;

use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\FormView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Content\Site\Helper\RouteHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View to edit an article.
 *
 * @since  1.6
 */
class HtmlView extends FormView
{
    /**
     * Whether the generic Autosave interface should be rendered.
     *
     * @var boolean
     *
     * @since __DEPLOY_VERSION__
     */
    public bool $autosaveEnabled = false;

    /**
     * Pagebreak TOC alias
     *
     * @var  string
     */
    protected $eName;

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
    protected $categorySection = 'com_content';

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
            $config['option'] = 'com_content';
        }

        $config['help_link']      = 'Articles:_Edit';
        $config['toolbar_icon']   = 'pencil-alt article-add';

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
        if ($this->getLayout() == 'pagebreak') {
            return;
        }

        parent::initializeView();

        $this->canDo = ContentHelper::getActions('com_content', 'article', $this->item->id);
        $this->prepareAutosave();

        $url = RouteHelper::getArticleRoute($this->item->id . ':' . $this->item->alias, $this->item->catid, $this->item->language);

        $this->previewLink = $url;
        $this->jooa11yLink = $url . '&jooa11y=1';

        if ($this->getLayout() === 'modalreturn') {
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
            $this->form->setFieldAttribute('catid', 'language', '*,' . $forcedLanguage);

            // Only allow to select tags with All language or with the forced language.
            $this->form->setFieldAttribute('tags', 'language', '*,' . $forcedLanguage);
        }

        // Add form control fields
        $this->form
            ->addControlField('task')
            ->addControlField('return', $input->getBase64('return', ''))
            ->addControlField('forcedLanguage', $forcedLanguage);
    }

    /**
     * Configure the headless Autosave integration for one canonical Article.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function prepareAutosave(): void
    {
        $document              = $this->getDocument();
        $this->autosaveEnabled = false;
        $application           = Factory::getApplication();
        $configurator          = new AutosaveViewConfigurator(
            $application,
            $document,
            $this->getCurrentUser()
        );
        $configurator->disable('com_content.autosave.article');

        if ($this->getLayout() !== 'edit' || (int) $this->item->id <= 0) {
            return;
        }

        try {
            $provider = Factory::getApplication()
                ->bootComponent('com_content')
                ->getAutosaveProvider('com_content.article');
            $targetId = $provider->canonicalizeTargetId((string) (int) $this->item->id);

            if (!$provider->targetExists($targetId)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $fieldIds = [];

        foreach (['title', 'alias', 'articletext', 'catid'] as $fieldName) {
            $field = $this->form->getField($fieldName);

            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            }

            $fieldIds[$fieldName] = $field->id;
        }

        $configurator->configure(
            $provider,
            $targetId,
            'com_content.autosave.article',
            'item-form',
            $fieldIds,
            'com_content.article-autosave'
        );
        $this->autosaveEnabled = true;
    }

    /**
     * Add the page title and toolbar.
     *
     * @return  void
     *
     * @since   1.6
     *
     * @throws  \Exception
     */
    protected function addToolbar()
    {
        if ($this->getLayout() === 'pagebreak') {
            return;
        }
        if ($this->getLayout() === 'modal') {
            $this->addModalToolbar();

            return;
        }

        $user       = $this->getCurrentUser();
        $userId     = $user->id;
        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        $this->toolbarTitle = Text::_('COM_CONTENT_PAGE_' . ($checkedOut ? 'VIEW_ARTICLE' : ($isNew ? 'ADD_ARTICLE' : 'EDIT_ARTICLE')));

        parent::addToolbar();
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
        $userId     = $user->id;
        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);
        $toolbar    = $this->getDocument()->getToolbar();

        // Build the actions for new and existing records.
        $canDo = $this->canDo;

        ToolbarHelper::title(
            Text::_('COM_CONTENT_PAGE_' . ($checkedOut ? 'VIEW_ARTICLE' : ($isNew ? 'ADD_ARTICLE' : 'EDIT_ARTICLE'))),
            'pencil-alt article-add'
        );

        $canCreate = $isNew && (\count($user->getAuthorisedCategories('com_content', 'core.create')) > 0);
        $canEdit   = $canDo->get('core.edit') || ($canDo->get('core.edit.own') && $this->item->created_by == $userId);

        // For new records, check the create permission.
        if ($canCreate || $canEdit) {
            $toolbar->apply('article.apply');
            $toolbar->save('article.save');
        }

        $toolbar->cancel('article.cancel');
    }
}
