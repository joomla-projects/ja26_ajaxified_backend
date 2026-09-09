<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2017 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Plugin;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Base plugin for all list based plugins
 *
 * @since  3.7.0
 */
class FieldsListPlugin extends FieldsPlugin
{
    /**
     * Build the declarative option result used by administrator list filters.
     *
     * @param   object  $field  Field definition.
     *
     * @return  array
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function getListFilterOptions(object $field): array
    {
        $options = [];
        $params  = clone $this->params;
        $params->merge($field->fieldparams);

        // Keep the declared rows intact. The display helper intentionally keys
        // options by value, which would hide duplicate tokens before the filter
        // service can reject an ambiguous declaration.
        foreach ($params->get('options', []) as $option) {
            $option    = (object) $option;
            $options[] = ['value' => (string) $option->value, 'text' => Text::_((string) $option->name)];
        }

        return ['options' => $options];
    }

    /**
     * Transforms the field into a DOM XML element and appends it as a child on the given parent.
     *
     * @param   \stdClass    $field   The field.
     * @param   \DOMElement  $parent  The field node parent.
     * @param   Form         $form    The form.
     *
     * @return  ?\DOMElement
     *
     * @since   3.7.0
     */
    public function onCustomFieldsPrepareDom($field, \DOMElement $parent, Form $form)
    {
        $fieldNode = parent::onCustomFieldsPrepareDom($field, $parent, $form);

        if (!$fieldNode) {
            return $fieldNode;
        }

        $fieldNode->setAttribute('validate', 'options');

        foreach ($this->getOptionsFromField($field) as $value => $name) {
            $option              = new \DOMElement('option', htmlspecialchars($value, ENT_COMPAT, 'UTF-8'));
            $option->textContent = htmlspecialchars(Text::_($name), ENT_COMPAT, 'UTF-8');

            $element = $fieldNode->appendChild($option);
            $element->setAttribute('value', $value);
        }

        return $fieldNode;
    }

    /**
     * Returns an array of key values to put in a list from the given field.
     *
     * @param   \stdClass  $field  The field.
     *
     * @return  array
     *
     * @since   3.7.0
     */
    public function getOptionsFromField($field)
    {
        $data = [];

        // Fetch the options from the plugin
        $params = clone $this->params;
        $params->merge($field->fieldparams);

        foreach ($params->get('options', []) as $option) {
            $op               = (object) $option;
            $data[$op->value] = $op->name;
        }

        return $data;
    }
}
