<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Templates\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Form\Form;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class StyleAutosaveSchemaFactory
{
    public function fromForm(Form $form): AutosaveDynamicSchema
    {
        $fields = [];

        foreach ($form->getXml()->xpath('//fields[@name="params"]//field') ?: [] as $field) {
            $name = (string) $field['name'];
            $type = strtolower((string) $field['type']);
            if ($name === '' || preg_match('/(?:password|passwd|secret|token|credential|api[_-]?key)/i', $name)) {
                continue;
            }
            $base = ['path' => ['params', $name], 'id' => (string) ($field['id'] ?: 'jform_params_' . $name)];
            if (\in_array($type, ['radio', 'list', 'folderlist'], true)) {
                $values = (string) $field['useglobal'] === 'true' ? [''] : [];
                foreach ($field->option as $option) {
                    $values[] = (string) $option['value'];
                }
                $values = array_values(array_unique($values));
                $invalidValues = array_filter(
                    $values,
                    static fn (string $value): bool => preg_match('//u', $value) !== 1 || \strlen($value) > 128
                );

                if (
                    $values !== []
                    && \count($values) <= AutosaveDynamicSchema::MAXIMUM_ENUM_VALUES
                    && $invalidValues === []
                ) {
                    $fields[] = $base + ['kind' => (string) $field['multiple'] === 'true' ? 'strings' : 'enum', 'values' => $values, 'maxItems' => 32];
                }
            } elseif ($type === 'checkbox') {
                $fields[] = $base + ['kind' => 'boolean'];
            } elseif (\in_array($type, ['text', 'textarea', 'url', 'number', 'integer', 'int', 'color', 'calendar', 'media'], true)) {
                $limit    = (int) ($field['maxlength'] ?? 0);
                $fields[] = $base + ['kind' => 'string', 'maxLength' => $limit > 0 ? min($limit, AutosaveDynamicSchema::MAXIMUM_STRING_SIZE) : AutosaveDynamicSchema::MAXIMUM_STRING_SIZE];
            }
            if (\count($fields) === AutosaveDynamicSchema::MAXIMUM_FIELDS) {
                break;
            }
        }

        return new AutosaveDynamicSchema($fields);
    }
}
