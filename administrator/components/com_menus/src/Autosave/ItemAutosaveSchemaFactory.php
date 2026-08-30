<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Menus\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Form\Form;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class ItemAutosaveSchemaFactory
{
    public function fromForm(Form $form): AutosaveDynamicSchema
    {
        $descriptors = [];

        // Inspect the finalized authoritative XML before constructing FormField
        // objects. Routed forms can contain unsupported plugin-backed widgets;
        // instantiating those merely for discovery can abort Autosave activation.
        foreach ($form->getXml()->xpath('//fields[@name="params"]//field') ?: [] as $field) {
            $descriptor = $this->descriptor($field);

            if ($descriptor === null) {
                continue;
            }

            try {
                new AutosaveDynamicSchema([$descriptor]);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $descriptors[] = $descriptor;

            if (\count($descriptors) > AutosaveDynamicSchema::MAXIMUM_FIELDS) {
                return new AutosaveDynamicSchema([]);
            }
        }

        return new AutosaveDynamicSchema($descriptors);
    }

    private function descriptor(\SimpleXMLElement $field): ?array
    {
        $name = (string) $field['name'];
        $type = strtolower((string) $field['type']);
        $id   = (string) ($field['id'] ?: 'jform_params_' . $name);
        $base = ['path' => ['params', $name], 'id' => $id];

        if (
            $name === ''
            || preg_match('/(?:password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|(?:^|[_-])(?:file|upload)(?:$|[_-]))/i', $name)
            || \in_array($type, ['hidden', 'spacer', 'file', 'password', 'rules', 'subform', 'sql', 'plugins'], true)
        ) {
            return null;
        }

        if (\in_array($type, ['radio', 'list', 'folderlist'], true)) {
            $values = (string) $field['useglobal'] === 'true' ? [''] : [];

            foreach ($field->option as $option) {
                $values[] = (string) $option['value'];
            }

            $values = array_values(array_unique($values));

            return $base + ['kind' => ((string) $field['multiple'] === 'true') ? 'strings' : 'enum', 'values' => $values, 'maxItems' => 32];
        }

        if ($type === 'checkbox') {
            return $base + ['kind' => 'boolean'];
        }

        if (\in_array($type, ['text', 'textarea', 'url', 'number', 'integer', 'int', 'color'], true)) {
            $maximum = (int) ($field['maxlength'] ?? 0);

            return $base + ['kind' => 'string', 'maxLength' => $maximum > 0 ? min($maximum, AutosaveDynamicSchema::MAXIMUM_STRING_SIZE) : AutosaveDynamicSchema::MAXIMUM_STRING_SIZE];
        }

        return null;
    }
}
