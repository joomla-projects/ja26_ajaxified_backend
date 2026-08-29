<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_modules
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Modules\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Form\Form;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class ModuleAutosaveSchemaFactory
{
    public function fromForm(Form $form): AutosaveDynamicSchema
    {
        return new AutosaveDynamicSchema($this->descriptors($form, 'params'));
    }

    private function descriptors(Form $form, string $group): array
    {
        $descriptors = [];

        foreach ($form->getXml()->xpath('//fields[@name="' . $group . '"]//field') ?: [] as $field) {
            $name = (string) $field['name'];
            $type = strtolower((string) $field['type']);

            if (
                $name === ''
                || preg_match('/(?:password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|(?:^|[_-])(?:file|upload)(?:$|[_-]))/i', $name)
            ) {
                continue;
            }

            $base = ['path' => [$group, $name], 'id' => (string) ($field['id'] ?: 'jform_' . $group . '_' . $name)];

            if (\in_array($type, ['radio', 'list', 'folderlist'], true)) {
                $values = (string) $field['useglobal'] === 'true' ? [''] : [];
                foreach ($field->option as $option) {
                    $values[] = (string) $option['value'];
                }
                $values     = array_values(array_unique($values));
                $descriptor = $base + ['kind' => (string) $field['multiple'] === 'true' ? 'strings' : 'enum', 'values' => $values, 'maxItems' => 32];
            } elseif ($type === 'checkbox') {
                $descriptor = $base + ['kind' => 'boolean'];
            } elseif (\in_array($type, ['text', 'textarea', 'url', 'number', 'integer', 'int', 'color', 'calendar', 'media'], true)) {
                $limit         = (int) ($field['maxlength'] ?? 0);
                $descriptor    = $base + ['kind' => 'string', 'maxLength' => $limit > 0 ? min($limit, AutosaveDynamicSchema::MAXIMUM_STRING_SIZE) : AutosaveDynamicSchema::MAXIMUM_STRING_SIZE];
            } else {
                continue;
            }

            try {
                new AutosaveDynamicSchema([$descriptor]);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $descriptors[] = $descriptor;

            if (\count($descriptors) > AutosaveDynamicSchema::MAXIMUM_FIELDS) {
                return [];
            }
        }

        return $descriptors;
    }
}
