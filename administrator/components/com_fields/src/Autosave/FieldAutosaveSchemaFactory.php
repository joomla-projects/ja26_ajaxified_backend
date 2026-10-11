<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Form\Form;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class FieldAutosaveSchemaFactory
{
    private const SUPPORTED_FIELD_TYPES = [
        'calendar', 'checkboxes', 'editor', 'imagelist', 'integer', 'list', 'media', 'note', 'number', 'radio', 'text', 'textarea', 'url', 'usergrouplist',
    ];

    public function forType(string $type): AutosaveDynamicSchema
    {
        if (!\in_array($type, self::SUPPORTED_FIELD_TYPES, true)) {
            return new AutosaveDynamicSchema([], AutosaveDynamicSchema::SUPPORT_UNSUPPORTED, ['unsupported_field_type']);
        }

        $path = JPATH_PLUGINS . '/fields/' . $type . '/params/' . $type . '.xml';

        if (!is_file($path)) {
            return new AutosaveDynamicSchema([], AutosaveDynamicSchema::SUPPORT_UNSUPPORTED, ['descriptor_unavailable']);
        }

        // Match the native Field form control so browser-facing field IDs remain
        // identical when the provider regenerates the authoritative schema.
        $form = new Form('com_fields.field.autosave.' . $type, ['control' => 'jform']);
        $form->load(file_get_contents($path), true, '/form/*');

        return $this->fromForm($form, $type);
    }

    public function fromForm(Form $form, string $type): AutosaveDynamicSchema
    {
        if (!\in_array($type, self::SUPPORTED_FIELD_TYPES, true)) {
            return new AutosaveDynamicSchema([], AutosaveDynamicSchema::SUPPORT_UNSUPPORTED, ['unsupported_field_type']);
        }

        $descriptors = [];
        $candidates  = 0;
        $omitted     = false;

        foreach ($form->getFieldsets('fieldparams') as $fieldset) {
            foreach ($form->getFieldset($fieldset->name) as $field) {
                $candidates++;
                $descriptor = $this->descriptor($field, $type);
                if ($descriptor !== null) {
                    $descriptors[] = $descriptor;
                } else {
                    $omitted = true;
                }
            }
        }

        if ($candidates === 0) {
            return new AutosaveDynamicSchema([], AutosaveDynamicSchema::SUPPORT_SUPPORTED, ['parameterless'], true);
        }

        return new AutosaveDynamicSchema(
            $descriptors,
            $omitted
                ? ($descriptors === [] ? AutosaveDynamicSchema::SUPPORT_UNSUPPORTED : AutosaveDynamicSchema::SUPPORT_PARTIAL)
                : AutosaveDynamicSchema::SUPPORT_SUPPORTED,
            $omitted ? ['unsupported_control'] : []
        );
    }

    private function descriptor(object $field, string $type): ?array
    {
        $name      = (string) $field->fieldname;
        $fieldType = strtolower((string) $field->type);
        $base      = ['path' => ['fieldparams', $name], 'id' => (string) $field->id];

        if ($type === 'sql' || \in_array($fieldType, ['file', 'password', 'hidden', 'spacer', 'plugins', 'media', 'editor', 'subfields'], true)) {
            return null;
        }

        if ($fieldType === 'subform') {
            $columns = $this->rowColumns($type, $name);

            return $columns ? $base + ['kind' => 'rows', 'maxItems' => 50, 'columns' => $columns] : null;
        }

        if (\in_array($fieldType, ['radio', 'list', 'folderlist'], true)) {
            $values = [];
            foreach ((array) $field->options as $option) {
                $values[] = (string) $option->value;
            }
            $values = array_values(array_unique($values));
            if ($values === [] || \count($values) > AutosaveDynamicSchema::MAXIMUM_ENUM_VALUES) {
                return null;
            }

            return $base + ['kind' => $field->multiple ? 'strings' : 'enum', 'values' => $values, 'maxItems' => 32];
        }

        if ($fieldType === 'checkbox') {
            return $base + ['kind' => 'boolean'];
        }

        if (\in_array($fieldType, ['text', 'textarea', 'number', 'int'], true)) {
            $maximum = (int) ($field->element['maxlength'] ?? 0);

            return $base + ['kind' => 'string', 'maxLength' => $maximum > 0 ? min($maximum, AutosaveDynamicSchema::MAXIMUM_STRING_SIZE) : AutosaveDynamicSchema::MAXIMUM_STRING_SIZE];
        }

        return null;
    }

    /** @return array<string, int> */
    private function rowColumns(string $type, string $name): array
    {
        $xml = simplexml_load_file(JPATH_PLUGINS . '/fields/' . $type . '/params/' . $type . '.xml');
        if (!$xml) {
            return [];
        }

        $columns = [];
        foreach ($xml->xpath('//field[@name="' . $name . '"]/form/field') ?: [] as $child) {
            if (strtolower((string) $child['type']) !== 'text') {
                return [];
            }
            $columns[(string) $child['name']] = min(max((int) ($child['maxlength'] ?: 255), 1), AutosaveDynamicSchema::MAXIMUM_STRING_SIZE);
        }

        return $columns;
    }
}
