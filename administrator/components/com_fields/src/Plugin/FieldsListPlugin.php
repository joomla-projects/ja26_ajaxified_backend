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
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

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
    private const MAX_FILTER_VALUES       = 100;
    private const MAX_FILTER_VALUE_LENGTH = 1024;

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

    /**
     * Returns an administrator filter definition for a configured-selection field.
     *
     * @param   object  $field  The custom-field definition.
     * @param   string  $name   The coordinator-supplied Form field name.
     *
     * @return  \SimpleXMLElement  The filter field definition.
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function getSelectionFilterField(object $field, string $name): \SimpleXMLElement
    {
        $element = new \SimpleXMLElement('<field/>');
        $element->addAttribute('name', $name);
        $element->addAttribute('type', 'list');
        $element->addAttribute('label', (string) $field->label);
        $element->addAttribute('hint', (string) $field->label);
        $element->addAttribute('multiple', 'true');
        $element->addAttribute('strictselection', 'true');
        $element->addAttribute('layout', 'joomla.form.field.list-fancy-select');
        $element->addAttribute('class', 'js-select-submit-on-change');

        foreach ($this->getSelectionFilterOptions($field) as $value => $label) {
            $option = $element->addChild('option', htmlspecialchars((string) $label, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
            $option->addAttribute('value', (string) $value);
        }

        return $element;
    }

    /**
     * Normalises configured selections into canonical filter data.
     *
     * @param   object  $field  The custom-field definition.
     * @param   mixed   $value  The raw filter value.
     *
     * @return  array  The canonical selected values.
     *
     * @throws  \InvalidArgumentException  When an active value is invalid.
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function normaliseSelectionFilterValue(object $field, mixed $value): array
    {
        $values = \is_array($value) ? $value : [$value];

        if (\count($values) > self::MAX_FILTER_VALUES) {
            throw new \InvalidArgumentException('Too many custom field filter values.');
        }

        $options = [];

        foreach ($this->getSelectionFilterOptions($field) as $option => $label) {
            $options[(string) $option] = true;
        }

        $normalised = [];

        foreach ($values as $selected) {
            if (!\is_string($selected) && !\is_int($selected)) {
                throw new \InvalidArgumentException('Invalid custom field filter value.');
            }

            $selected = (string) $selected;

            if ($selected === '') {
                continue;
            }

            if (\strlen($selected) > self::MAX_FILTER_VALUE_LENGTH || !isset($options[$selected])) {
                throw new \InvalidArgumentException('Unknown custom field filter value.');
            }

            $normalised[$selected] = $selected;
        }

        $normalised = array_values($normalised);
        sort($normalised, SORT_STRING);

        return $normalised;
    }

    /**
     * Applies configured selections to the item query.
     *
     * @param   QueryInterface     $query             The host list query.
     * @param   DatabaseInterface  $database          The database connection.
     * @param   object             $field             The custom-field definition.
     * @param   array              $value             Canonical selected values.
     * @param   string             $itemIdExpression  Trusted coordinator-generated item-ID SQL expression.
     * @param   string             $bindPrefix        Unique generated bind-name prefix without a leading colon.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function applySelectionFilter(
        QueryInterface $query,
        DatabaseInterface $database,
        object $field,
        array $value,
        string $itemIdExpression,
        string $bindPrefix
    ): void {
        $fieldId  = (int) $field->id;
        $subQuery = $database->createQuery()
            ->select('1')
            ->from($database->quoteName('#__fields_values', 'fv'))
            ->where($database->quoteName('fv.field_id') . ' = :' . $bindPrefix . 'field');
        $query->bind(':' . $bindPrefix . 'field', $fieldId, ParameterType::INTEGER);

        $serverType = $database->getServerType();

        if ($serverType === 'mysql') {
            $subQuery->where('BINARY ' . $database->quoteName('fv.item_id') . ' = BINARY ' . $itemIdExpression);
        } elseif ($serverType === 'postgresql') {
            $subQuery->where(
                "convert_to(" . $database->quoteName('fv.item_id') . ", 'UTF8') = convert_to(" . $itemIdExpression . ", 'UTF8')"
            );
        } else {
            $subQuery->where($database->quoteName('fv.item_id') . ' = ' . $itemIdExpression);
        }

        $comparisons = [];
        $tokens      = array_values($value);

        foreach ($tokens as $index => &$token) {
            $placeholder = ':' . $bindPrefix . 'value' . $index;

            if ($serverType === 'mysql') {
                $comparisons[] = 'BINARY ' . $database->quoteName('fv.value') . ' = BINARY ' . $placeholder;
            } elseif ($serverType === 'postgresql') {
                $comparisons[] = "convert_to(" . $database->quoteName('fv.value') . ", 'UTF8') = convert_to(" . $placeholder . ", 'UTF8')";
            } else {
                $comparisons[] = $database->quoteName('fv.value') . ' = ' . $placeholder;
            }

            $query->bind($placeholder, $token, ParameterType::STRING);
        }

        unset($token);

        $subQuery->where('(' . implode(' OR ', $comparisons) . ')');
        $query->where('EXISTS (' . $subQuery . ')');
    }

    /**
     * Returns usable configured options for an administrator filter.
     *
     * @param   object  $field  The custom-field definition.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getSelectionFilterOptions(object $field): array
    {
        $options = [];

        foreach ($this->getOptionsFromField($field) as $value => $label) {
            $value = (string) $value;

            if ($value === '' || \strlen($value) > self::MAX_FILTER_VALUE_LENGTH) {
                continue;
            }

            $options[$value] = $label;
        }

        return $options;
    }
}
