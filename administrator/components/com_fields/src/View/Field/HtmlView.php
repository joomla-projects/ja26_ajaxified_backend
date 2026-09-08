<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2016 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\View\Field;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveViewConfigurator;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Fields\Administrator\Autosave\FieldAutosaveProvider;
use Joomla\Component\Fields\Administrator\Model\FieldModel;
use Joomla\Filesystem\Path;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Field View
 *
 * @since  3.7.0
 */
class HtmlView extends BaseHtmlView
{
    public bool $autosaveEnabled = false;

    /**
     * @var     \Joomla\CMS\Form\Form
     *
     * @since   3.7.0
     */
    protected $form;

    /**
     * @var     \stdClass
     *
     * @since   3.7.0
     */
    protected $item;

    /**
     * @var     \Joomla\Registry\Registry
     *
     * @since   3.7.0
     */
    protected $state;

    public bool $autosavePartial = false;

    /**
     * Execute and display a template script.
     *
     * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
     *
     * @return  void
     *
     * @see     HtmlView::loadTemplate()
     *
     * @since   3.7.0
     */
    public function display($tpl = null)
    {
        /** @var FieldModel $model */
        $model = $this->getModel();
        $model->setUseExceptions(true);

        $this->form  = $model->getForm();
        $this->item  = $model->getItem();
        $this->state = $model->getState();

        $this->canDo = ContentHelper::getActions($this->state->get('field.component'), 'field', $this->item->id);

        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $this->addToolbar();

        // Add form control fields
        $this->form
            ->addControlField('task');

        $this->prepareAutosave();

        parent::display($tpl);
    }

    private function prepareAutosave(): void
    {
        $app          = Factory::getApplication();
        $configurator = new AutosaveViewConfigurator($app, $this->getDocument(), $app->getIdentity());
        $configurator->disable('com_fields.autosave.field');

        if ($this->getLayout() !== 'edit') {
            return;
        }

        $target       = null;
        $createScope  = null;
        $schema       = null;
        $itemId       = (int) $this->item->id;

        try {
            $provider = $app->bootComponent('com_fields')->getAutosaveProvider('com_fields.field');
            if (!$provider instanceof FieldAutosaveProvider) {
                return;
            }

            if ($itemId > 0) {
                $target = $provider->canonicalizeTargetId((string) $itemId);
                if (!$provider->targetExists($target)) {
                    return;
                }

                $schema                = $provider->getDynamicSchemaForForm($target, $this->form);
                $this->autosavePartial = !$provider->fullyRepresentsDynamicForm((string) $this->item->type, $this->form);
            } elseif ($itemId !== 0 || !$provider instanceof AutosaveCreateProviderInterface) {
                return;
            } elseif ($provider instanceof AutosaveDynamicCreateDescriptorProviderInterface) {
                // The candidate context and field type are server-owned form values.
                // They are canonicalized and authorized once at initialization; the
                // browser never resubmits authoritative creation state afterwards.
                $type    = (string) $this->form->getValue('type');
                $context = (string) $this->state->get('field.context', '') ?: (string) $this->form->getValue('context');

                if ($type === '' || $context === '') {
                    return;
                }

                $createScope = $provider->canonicalizeStaticCreateScope($context . '|' . $type);
                $provider->authorizeStaticCreateScope($app->getIdentity(), $createScope, AutosaveOperation::InitializeCreate, null);
                $schema                = $provider->getDynamicSchemaForType($type);
                $this->autosavePartial = !$provider->fullyRepresentsDynamicForm($type, $this->form);
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
        foreach (['title', 'name', 'label', 'description', 'required', 'only_use_in_subform', 'default_value', 'note'] as $name) {
            $field = $this->form->getField($name);
            if (!$field || !\is_string($field->id) || $field->id === '') {
                return;
            }
            $ids[$name] = $field->id;
        }

        $configurator->configure(
            $provider,
            $target,
            'com_fields.autosave.field',
            'item-form',
            $ids,
            'com_fields.field-autosave',
            ['fields' => $schema->fields(), 'fingerprint' => $schema->fingerprint(), 'support' => $schema->support()],
            $createScope
        );
        $this->autosaveEnabled = true;
    }

    /**
     * Adds the toolbar.
     *
     * @return  void
     *
     * @since   3.7.0
     */
    protected function addToolbar()
    {
        $component = $this->state->get('field.component');
        $section   = $this->state->get('field.section');
        $userId    = $this->getCurrentUser()->id;
        $canDo     = $this->canDo;
        $toolbar   = $this->getDocument()->getToolbar();

        $isNew      = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        // Avoid nonsense situation.
        if ($component == 'com_fields') {
            return;
        }

        // Load component language file
        $lang = $this->getLanguage();
        $lang->load($component, JPATH_ADMINISTRATOR)
        || $lang->load($component, Path::clean(JPATH_ADMINISTRATOR . '/components/' . $component));

        $title = Text::sprintf('COM_FIELDS_VIEW_FIELD_' . ($isNew ? 'ADD' : 'EDIT') . '_TITLE', Text::_(strtoupper($component)));

        // Prepare the toolbar.
        ToolbarHelper::title(
            $title,
            'puzzle field-' . ($isNew ? 'add' : 'edit') . ' ' . substr($component, 4) . ($section ? "-$section" : '') . '-field-' .
            ($isNew ? 'add' : 'edit')
        );

        // For new records, check the create permission.
        if ($isNew) {
            $toolbar->apply('field.apply');
            $saveGroup = $toolbar->dropdownButton('save-group');
            $saveGroup->configure(
                function (Toolbar $childBar) {
                    $childBar->save('field.save');
                    $childBar->save2new('field.save2new');
                }
            );
            $toolbar->cancel('field.cancel', 'JTOOLBAR_CANCEL');
        } else {
            // Since it's an existing record, check the edit permission, or fall back to edit own if the owner.
            $itemEditable = $canDo->get('core.edit') || ($canDo->get('core.edit.own') && $this->item->created_by == $userId);

            // Can't save the record if it's checked out and editable
            if (!$checkedOut && $itemEditable) {
                $toolbar->apply('field.apply');
            }

            $saveGroup = $toolbar->dropdownButton('save-group');
            $saveGroup->configure(
                function (Toolbar $childBar) use ($checkedOut, $itemEditable, $canDo) {
                    if (!$checkedOut && $itemEditable) {
                        $childBar->save('field.save');

                        // We can save this record, but check the create permission to see if we can return to make a new one.
                        if ($canDo->get('core.create')) {
                            $childBar->save2new('field.save2new');
                        }
                    }

                    // If an existing item, can save to a copy.
                    if ($canDo->get('core.create')) {
                        $childBar->save2copy('field.save2copy');
                    }
                }
            );

            $toolbar->cancel('field.cancel');
        }

        $toolbar->inlinehelp();
        $toolbar->help('Fields:_Edit');
    }
}
