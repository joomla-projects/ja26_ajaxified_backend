<?php

/**
 * Joomla! Content Management System
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Form\Form;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Select supported form fields and normalize draft values.
 *
 * @since __DEPLOY_VERSION__
 */
final class DraftProjection
{
    /**
     * @since __DEPLOY_VERSION__
     */
    public static function describe(Form $form, array $keys): array
    {
        $fields = [];
        foreach ($keys as $key) {
            [$group, $name] = str_contains($key, '.') ? explode('.', $key, 2) : [null, $key];
            $type           = strtolower((string) $form->getFieldAttribute($name, 'type', '', $group));
            $control        = match ($type) {
                'text', 'alias', 'url', 'email', 'tel', 'number', 'int', 'color', 'user', 'media', 'moduleposition'                                                                                                  => 'text',
                'textarea'                                                                                                                                                                                           => 'textarea',
                'editor'                                                                                                                                                                                             => 'editor',
                'checkbox'                                                                                                                                                                                           => 'checkbox',
                'radio'                                                                                                                                                                                              => 'radio',
                'calendar'                                                                                                                                                                                           => 'calendar',
                'categoryedit', 'category', 'list', 'bannerclient', 'contentlanguage', 'tag', 'modulelayout', 'filelist', 'folderlist', 'integer', 'headertag', 'moduletag', 'plugins', 'sql', 'modulespositionedit' => 'select',
                default                                                                                                                                                                                              => null,
            };
            if ($control === null) {
                continue;
            }
            $field = $form->getField($name, $group);
            if (!$field || $field->disabled || $field->readonly || !$field->id || $field->getAttribute('sensitive') === 'true') {
                continue;
            }
            if ($field->multiple) {
                if ($control !== 'select') {
                    continue;
                }
                $control = 'multiple';
            }
            $fields[$key] = ['id' => $field->id, 'name' => $field->name, 'control' => $control,
                'label'           => (string) $field->getAttribute('label', $key)];
        }
        return $fields;
    }

    /**
     * List eligible fields in a form group, requiring explicit opt-in by default.
     *
     * @since __DEPLOY_VERSION__
     */
    public static function groupKeys(Form $form, string $group, bool $declaredOnly = true): array
    {
        $keys = [];
        foreach ($form->getXml()->xpath('//fields[@name="' . $group . '"]/descendant::field') ?: [] as $field) {
            if ((string) $field['sensitive'] === 'true' || \in_array(strtolower((string) $field['type']), ['hidden', 'password', 'spacer', 'note'], true)) {
                continue;
            }
            if (!$declaredOnly || (string) $field['autosave'] === 'true') {
                $keys[] = $group . '.' . (string) $field['name'];
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * @since __DEPLOY_VERSION__
     */
    public static function values(object $item, array $keys): array
    {
        $registry = new Registry($item);
        $values   = [];
        foreach ($keys as $key) {
            $value = $registry->get($key, '');
            if (str_contains($key, '.')) {
                [$group, $name] = explode('.', $key, 2);
                $value          = ($item->$group ?? null) instanceof Registry ? $item->$group->get($name, '') : (new Registry($item->$group ?? []))->get($name, '');
            }
            $values[$key] = \is_array($value) ? array_map('strval', array_values($value)) : (string) $value;
        }
        return $values;
    }

    /**
     * @since __DEPLOY_VERSION__
     */
    public static function descriptor(Form $form, object $item, string $context, ?string $target, array $scope, array $keys, array $markers = []): array
    {
        $fields = self::describe($form, $keys);
        $values = self::values($item, array_keys($fields));
        return ['context' => $context, 'target' => $target, 'scope' => $scope, 'version' => 1, 'fields' => $fields,
            'revision'    => $target === null ? null : hash(
                'sha256',
                json_encode(
                    [$context, $target, $scope, $values, $markers],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            ),
            'values' => $values, 'excluded' => array_values(array_diff($keys, array_keys($fields)))];
    }

    /**
     * @since __DEPLOY_VERSION__
     */
    public static function normalize(mixed $payload, array $fields): array
    {
        if (!\is_array($payload) || array_is_list($payload) || $payload === [] || array_diff_key($payload, $fields)) {
            throw new AutosaveException('invalid_payload', 'The draft contains unsupported values.');
        }
        $normalized = [];
        foreach ($fields as $key => $field) {
            if (!\array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (\in_array($field['control'], ['multiple', 'checkboxes'], true)) {
                if (!\is_array($value) || !array_is_list($value)) {
                    throw new AutosaveException('invalid_payload', 'The native selection is invalid.');
                }
                foreach ($value as $selected) {
                    if (!\is_string($selected) || !mb_check_encoding($selected, 'UTF-8')) {
                        throw new AutosaveException('invalid_payload', 'The native selection is invalid.');
                    }
                }
                $normalized[$key] = array_values(array_unique($value));
                continue;
            }
            if (\is_int($value) && \in_array($field['control'], ['select', 'radio'], true)) {
                $value = (string) $value;
            }
            if ($field['control'] === 'checkbox') {
                if (\is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (\is_int($value) && ($value === 0 || $value === 1)) {
                    $value = (string) $value;
                }
                if ($value !== '0' && $value !== '1') {
                    throw new AutosaveException('invalid_payload', 'The checkbox value is invalid.');
                }
            }
            if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new AutosaveException('invalid_payload', 'The draft value has an unsupported representation.');
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }
}
