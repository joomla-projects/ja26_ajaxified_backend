<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\CustomFields\GetFilterOptionsEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Fields\FieldsServiceInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Joomla\Event\DispatcherInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Prepares and applies administrator Custom Field list filters.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FieldsFilterService
{
    public const MAX_FIELDS            = 32;
    public const MAX_VALUES_PER_FIELD  = 100;
    public const MAX_VALUES            = 256;
    public const MAX_TOKEN_BYTES       = 1024;
    public const MAX_TOKEN_BYTES_TOTAL = 65536;
    public const MAX_OPTIONS_PER_FIELD = 1000;

    public function __construct(
        private readonly MVCFactoryInterface $mvcFactory,
        private readonly DatabaseInterface $database,
        private readonly DispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Prepare eligible controls and canonical selections.
     *
     * @param   string  $context   Fields context.
     * @param   array   $filters   Complete flat filter map.
     * @param   User    $user      Current user.
     * @param   bool    $explicit  Whether the map came from an explicit request.
     *
     * @return  PreparedFieldsFilter
     *
     * @since  __DEPLOY_VERSION__
     */
    public function prepare(string $context, array $filters, User $user, bool $explicit): PreparedFieldsFilter
    {
        $candidates = [];
        $issues     = [];

        foreach ($filters as $name => $value) {
            if (!str_starts_with((string) $name, 'customfield_')) {
                continue;
            }

            if (
                !preg_match('/^customfield_([1-9][0-9]*)$/D', (string) $name, $match)
                || filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            ) {
                $issues[] = ['code' => 'invalid_field'];
                continue;
            }

            if (!\is_array($value)) {
                $issues[] = ['code' => 'invalid_shape', 'field_id' => (int) $match[1]];
                continue;
            }

            $candidates[(int) $match[1]] = $value;
        }

        if (\count($candidates) > self::MAX_FIELDS) {
            $issues[] = ['code' => 'too_many_fields'];
        }

        $parts            = FieldsHelper::extract($context);
        $component        = $parts ? Factory::getApplication()->bootComponent($parts[0]) : null;
        $supportedSection = $component instanceof FieldsServiceInterface
                    ? $component->validateSection($parts[1])
                    : null;

        if (
            $supportedSection === null
            || !ComponentHelper::getParams($parts[0])->get('custom_fields_enable', 1)
        ) {
            foreach ($candidates as $fieldId => $values) {
                if (array_filter($values, static fn ($value) => $value !== '') !== []) {
                    $issues[] = ['code' => 'ineligible_field', 'field_id' => $fieldId];
                }
            }

            return new PreparedFieldsFilter($context, [], [], $issues, $explicit && $issues !== []);
        }

        $model = $this->mvcFactory->createModel('Fields', 'Administrator', ['ignore_request' => true]);
        $model->setCurrentUser($user);
        $model->setState('filter.context', $context);
        $model->setState('filter.state', 1);
        $model->setState('filter.language', ['*']);
        $model->setState('filter.assigned_cat_ids', [0]);
        $model->setState('filter.only_use_in_subform', 0);
        $model->setState('list.limit', 0);

        PluginHelper::importPlugin('fields', null, true, $this->dispatcher);

        $controls = [];

        foreach ($model->getItems() ?: [] as $field) {
            if (!$field->params->get('show_in_admin_list_filter', 0)) {
                continue;
            }

            $event  = new GetFilterOptionsEvent('onCustomFieldsGetFilterOptions', ['subject' => $field]);
            $result = $this->dispatcher->dispatch('onCustomFieldsGetFilterOptions', $event)->getArgument('result', []);

            if (\count($result) !== 1 || !\is_array($result[0])) {
                continue;
            }

            $options = $this->normalizeOptions($result[0]['options'] ?? null);

            if ($options === null || $options === []) {
                continue;
            }

            $controls[(int) $field->id] = [
                'label'       => (string) ($field->label ?: $field->title),
                'description' => (string) $field->description,
                'options'     => $options,
            ];
        }

        $selections = [];
        $valueCount = 0;
        $byteCount  = 0;

        foreach ($candidates as $fieldId => $values) {
            if (!isset($controls[$fieldId])) {
                $issues[] = ['code' => 'ineligible_field'];
                continue;
            }

            if (\count($values) > self::MAX_VALUES_PER_FIELD) {
                $issues[] = ['code' => 'too_many_values', 'field_id' => $fieldId];
                continue;
            }

            $allowed = array_column($controls[$fieldId]['options'], 'value');
            $clean   = [];

            foreach ($values as $value) {
                if (\is_int($value)) {
                    $value = (string) $value;
                }

                if (!\is_string($value) || \strlen($value) > self::MAX_TOKEN_BYTES) {
                    $issues[] = ['code' => 'invalid_value', 'field_id' => $fieldId];
                    continue 2;
                }

                if ($value === '') {
                    continue;
                }

                if (!\in_array($value, $allowed, true)) {
                    $issues[] = ['code' => 'unknown_value', 'field_id' => $fieldId];
                    continue 2;
                }

                $clean[] = $value;
            }

            $clean = array_values(array_unique($clean, SORT_STRING));

            if ($clean !== []) {
                $selections[$fieldId] = $clean;
                $valueCount += \count($clean);
                $byteCount += array_sum(array_map('strlen', $clean));
            }
        }

        if ($valueCount > self::MAX_VALUES || $byteCount > self::MAX_TOKEN_BYTES_TOTAL) {
            $issues[] = ['code' => 'request_too_large'];
        }

        ksort($controls, SORT_NUMERIC);
        ksort($selections, SORT_NUMERIC);

        return new PreparedFieldsFilter($context, $controls, $selections, $issues, $explicit && $issues !== []);
    }

    public function augmentForm(Form $form, PreparedFieldsFilter $prepared): void
    {
        $language = Factory::getApplication()->getLanguage();
        $language->load('com_fields', JPATH_ADMINISTRATOR);

        foreach ($prepared->getControls() as $fieldId => $control) {
            $name = 'customfield_' . $fieldId;

            if ($form->getField($name, 'filter')) {
                throw new \RuntimeException('A Custom Field filter collides with an existing filter control.');
            }

            $xml         = new \SimpleXMLElement('<field />');
            $label       = $this->toDisplayText($control['label']);
            $description = $this->toDisplayText($control['description']);

            $xml->addAttribute('name', $name);
            $xml->addAttribute('type', 'list');
            // Form labels/descriptions are rendered as trusted XML strings; this
            // metadata originates in user-editable field definitions.
            $xml->addAttribute('label', htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
            $xml->addAttribute('description', htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
            $xml->addAttribute('multiple', 'true');
            $xml->addAttribute('strict', 'true');
            $xml->addAttribute('layout', 'joomla.form.field.list-fancy-select');
            $xml->addAttribute('class', 'js-select-submit-on-change');
            $xml->addAttribute('hint', \sprintf($language->_('COM_FIELDS_FILTER_SELECT_FIELD'), $label));

            foreach ($control['options'] as $option) {
                $node    = $xml->addChild('option');
                $node[0] = $this->toDisplayText($option['text']);
                $node->addAttribute('value', $option['value']);
            }

            $form->setField($xml, 'filter');
        }
    }

    public function bindForm(Form $form, PreparedFieldsFilter $prepared): void
    {
        foreach ($prepared->getControls() as $fieldId => $control) {
            $form->setValue('customfield_' . $fieldId, 'filter', $prepared->getSelections()[$fieldId] ?? []);
        }
    }

    public function applyToQuery(
        QueryInterface $query,
        PreparedFieldsFilter $prepared,
        string $itemKeyExpression,
        string $identityType = 'integer'
    ): void {
        if ($prepared->isRejected()) {
            $query->where('1 = 0');

            return;
        }

        if (!$prepared->isActive()) {
            return;
        }

        if (!\in_array($identityType, ['integer', 'string'], true)) {
            throw new \InvalidArgumentException('Unsupported Custom Field filter identity type.');
        }

        $itemKey = $identityType === 'string'
            ? $itemKeyExpression
            : $query->concatenate([$itemKeyExpression, $this->database->quote('')]);

        foreach ($prepared->getSelections() as $index => $values) {
            $fieldId         = (int) $index;
            $fieldParameter  = $query->bindArray([$fieldId], ParameterType::INTEGER)[0];
            $valueParameters = $query->bindArray($values, ParameterType::STRING);

            $alias    = 'cffv' . $index;
            $subquery = $this->database->createQuery()
                ->select('1')
                ->from($this->database->quoteName('#__fields_values', $alias))
                ->where($this->database->quoteName($alias . '.field_id') . ' = ' . $fieldParameter)
                ->where($this->database->quoteName($alias . '.item_id') . ' = ' . $itemKey)
                ->where($this->database->quoteName($alias . '.value') . ' IN (' . implode(',', $valueParameters) . ')');

            $query->where('EXISTS (' . $subquery . ')');
        }
    }

    private function normalizeOptions(mixed $options): ?array
    {
        if (!\is_array($options) || \count($options) > self::MAX_OPTIONS_PER_FIELD) {
            return null;
        }

        $normalized = [];
        $seen       = [];

        foreach ($options as $option) {
            if (
                !\is_array($option) || !isset($option['value'], $option['text'])
                || !\is_string($option['value']) || !\is_string($option['text'])
                || $option['value'] === '' || \strlen($option['value']) > self::MAX_TOKEN_BYTES
                || isset($seen[$option['value']])
            ) {
                return null;
            }

            $seen[$option['value']] = true;
            $normalized[]           = [
                'value' => $option['value'],
                'text'  => $option['text'],
            ];
        }

        return $normalized;
    }

    /**
     * Convert translated metadata to plain display text before XML construction.
     *
     * Literal markup is removed. Entity layers are decoded before the value is
     * escaped for its final sink, preventing both active markup and double escaping.
     */
    private function toDisplayText(string $value): string
    {
        $value = strip_tags($value);

        do {
            $previous = $value;
            $value    = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } while ($value !== $previous);

        return $value;
    }
}
