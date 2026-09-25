<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.list
 *
 * @copyright   (C) 2017 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Fields\ListField\Extension;

use Joomla\CMS\Event\CustomFields\BeforePrepareFieldEvent;
use Joomla\CMS\Fields\CustomFieldFilterProviderInterface;
use Joomla\Component\Fields\Administrator\Plugin\FieldsListPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fields List Plugin
 *
 * @since  3.7.0
 */
final class ListPlugin extends FieldsListPlugin implements CustomFieldFilterProviderInterface, SubscriberInterface
{
    private const MAX_FILTER_VALUES       = 100;
    private const MAX_FILTER_VALUE_LENGTH = 1024;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   5.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onCustomFieldsBeforePrepareField' => 'beforePrepareField',
        ]);
    }

    /**
     * Returns the List field's administrator filter definition.
     *
     * @param   object  $field  The custom-field definition.
     * @param   string  $name   The coordinator-supplied Form field name.
     *
     * @return  \SimpleXMLElement  The filter field definition.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getFilterField(object $field, string $name): \SimpleXMLElement
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

        foreach ($this->getFilterOptions($field) as $value => $label) {
            $option = $element->addChild('option', htmlspecialchars((string) $label, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
            $option->addAttribute('value', (string) $value);
        }

        return $element;
    }

    /**
     * Normalises selected List options into canonical filter data.
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
    public function normaliseValue(object $field, mixed $value): array
    {
        $values = \is_array($value) ? $value : [$value];

        if (\count($values) > self::MAX_FILTER_VALUES) {
            throw new \InvalidArgumentException('Too many custom field filter values.');
        }

        $options = [];

        foreach ($this->getFilterOptions($field) as $option => $label) {
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
     * Returns the supported options from the authoritative field configuration.
     *
     * @param   object  $field  The custom-field definition.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getFilterOptions(object $field): array
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

    /**
     * Applies canonical List selections to the item query.
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
    public function applyFilter(
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

        $tokens = array_values($value);

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
     * Before prepares the field value.
     *
     * @param   BeforePrepareFieldEvent $event    The event instance.
     *
     * @return  void
     *
     * @since   3.7.0
     */
    public function beforePrepareField(BeforePrepareFieldEvent $event): void
    {
        if (!$this->getApplication()->isClient('api')) {
            return;
        }

        $field = $event->getField();

        if (!$this->isTypeSupported($field->type)) {
            return;
        }

        $options         = $this->getOptionsFromField($field);
        $field->apivalue = [];

        if (\is_array($field->value)) {
            foreach ($field->value as $value) {
                $field->apivalue[$value] = $options[$value];
            }
        } elseif (!empty($field->value)) {
            $field->apivalue[$field->value] = $options[$field->value];
        }
    }

    /**
     * Prepares the field
     *
     * @param   string     $context  The context.
     * @param   \stdclass  $item     The item.
     * @param   \stdclass  $field    The field.
     *
     * @return  ?string
     *
     * @since   3.9.2
     */
    public function onCustomFieldsPrepareField($context, $item, $field)
    {
        // Check if the field should be processed
        if (!$this->isTypeSupported($field->type)) {
            return '';
        }

        // The field's rawvalue should be an array
        if (!\is_array($field->rawvalue)) {
            $field->rawvalue = (array) $field->rawvalue;
        }

        return parent::onCustomFieldsPrepareField($context, $item, $field);
    }
}
