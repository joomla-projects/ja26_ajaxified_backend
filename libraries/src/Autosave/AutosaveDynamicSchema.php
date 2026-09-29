<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A finite server-owned schema for one known dynamic payload branch.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveDynamicSchema
{
    public const SUPPORT_SUPPORTED   = 'supported';
    public const SUPPORT_PARTIAL     = 'partial';
    public const SUPPORT_UNSUPPORTED = 'unsupported';

    public const MAXIMUM_FIELDS       = 32;
    public const MAXIMUM_PATH_DEPTH   = 2;
    public const MAXIMUM_SEGMENT_SIZE = 48;
    public const MAXIMUM_STRING_SIZE  = 4096;
    public const MAXIMUM_ENUM_VALUES  = 64;
    public const MAXIMUM_ITEMS        = 50;

    /** @var list<array<string, mixed>> */
    private array $fields;

    /** @param list<array<string, mixed>> $fields */
    public function __construct(
        array $fields,
        private readonly string $supportStatus = self::SUPPORT_SUPPORTED,
        private readonly array $supportReasons = [],
        private readonly bool $parameterless = false
    ) {
        if (
            !\in_array($supportStatus, [self::SUPPORT_SUPPORTED, self::SUPPORT_PARTIAL, self::SUPPORT_UNSUPPORTED], true)
            || array_filter($supportReasons, static fn ($reason) => !\is_string($reason) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) !== 1) !== []
            || ($parameterless && ($fields !== [] || $supportStatus !== self::SUPPORT_SUPPORTED))
        ) {
            throw new \InvalidArgumentException('The Autosave dynamic schema support status is invalid.');
        }

        if (\count($fields) > self::MAXIMUM_FIELDS) {
            throw new \InvalidArgumentException('The Autosave dynamic schema contains too many fields.');
        }

        $normalized = [];
        $paths      = [];

        foreach ($fields as $field) {
            $descriptor = $this->normalizeDescriptor($field);
            $pathKey    = implode("\0", $descriptor['path']);

            if (isset($paths[$pathKey])) {
                throw new \InvalidArgumentException('The Autosave dynamic schema contains a duplicate path.');
            }

            $paths[$pathKey] = true;
            $normalized[]    = $descriptor;
        }

        usort($normalized, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);
        $this->fields = $normalized;
    }

    /** @return list<array<string, mixed>> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** Return browser-safe recovery capability metadata. */
    public function support(): array
    {
        return [
            'status'        => $this->supportStatus,
            'reasons'       => array_values(array_unique($this->supportReasons)),
            'parameterless' => $this->parameterless,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function normalizePayload(mixed $payload): array
    {
        if ($this->fields === []) {
            if ($payload === [] || $payload === ['fieldparams' => []]) {
                return $payload;
            }

            throw new \InvalidArgumentException('The dynamic Autosave payload contains an unknown field.');
        }

        if (!\is_array($payload) || array_is_list($payload)) {
            throw new \InvalidArgumentException('The dynamic Autosave payload is invalid.');
        }

        $result = [];

        foreach ($this->fields as $field) {
            [$group, $name] = $field['path'];

            if (!isset($payload[$group]) || !\is_array($payload[$group]) || array_is_list($payload[$group]) || !\array_key_exists($name, $payload[$group])) {
                throw new \InvalidArgumentException('The dynamic Autosave payload is incomplete.');
            }

            $result[$group][$name] = $this->normalizeValue($payload[$group][$name], $field);
        }

        $expectedGroups = [];

        foreach ($this->fields as $field) {
            $expectedGroups[$field['path'][0]][$field['path'][1]] = true;
        }

        if (array_diff_key($payload, $expectedGroups)) {
            throw new \InvalidArgumentException('The dynamic Autosave payload contains an unknown group.');
        }

        foreach ($expectedGroups as $group => $names) {
            if (array_diff_key($payload[$group], $names)) {
                throw new \InvalidArgumentException('The dynamic Autosave payload contains an unknown field.');
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function normalizeDescriptor(array $field): array
    {
        $allowed = ['path' => true, 'id' => true, 'kind' => true, 'maxLength' => true, 'values' => true, 'maxItems' => true, 'columns' => true];

        if (array_diff_key($field, $allowed) || !isset($field['path'], $field['id'], $field['kind']) || !\is_array($field['path']) || \count($field['path']) !== self::MAXIMUM_PATH_DEPTH) {
            throw new \InvalidArgumentException('The Autosave dynamic field descriptor is invalid.');
        }

        foreach ($field['path'] as $segment) {
            if (!\is_string($segment) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,47}$/D', $segment) !== 1 || \in_array(strtolower($segment), ['__proto__', 'prototype', 'constructor'], true)) {
                throw new \InvalidArgumentException('The Autosave dynamic field path is invalid.');
            }
        }

        if (!\is_string($field['id']) || $field['id'] === '' || \strlen($field['id']) > 128 || !\in_array($field['kind'], ['string', 'boolean', 'enum', 'strings', 'rows'], true)) {
            throw new \InvalidArgumentException('The Autosave dynamic field descriptor is invalid.');
        }

        $descriptor = ['path' => array_values($field['path']), 'id' => $field['id'], 'kind' => $field['kind']];

        if ($field['kind'] === 'string') {
            $maximum = $field['maxLength'] ?? self::MAXIMUM_STRING_SIZE;
            if (!\is_int($maximum) || $maximum < 1 || $maximum > self::MAXIMUM_STRING_SIZE) {
                throw new \InvalidArgumentException('The Autosave dynamic string bound is invalid.');
            }
            $descriptor['maxLength'] = $maximum;
        }

        if (\in_array($field['kind'], ['enum', 'strings'], true)) {
            $values = $field['values'] ?? null;
            if (!\is_array($values) || $values === [] || \count($values) > self::MAXIMUM_ENUM_VALUES || array_filter($values, static fn ($value) => !\is_string($value) || preg_match('//u', $value) !== 1 || \strlen($value) > 128) !== []) {
                throw new \InvalidArgumentException('The Autosave dynamic enum is invalid.');
            }
            $descriptor['values'] = array_values(array_unique($values));
            if ($field['kind'] === 'strings') {
                $descriptor['maxItems'] = $this->normalizeMaximumItems($field);
            }
        }

        if ($field['kind'] === 'rows') {
            $columns = $field['columns'] ?? null;
            if (!\is_array($columns) || $columns === [] || \count($columns) > 8 || array_filter($columns, static fn ($value, $key) => !\is_string($key) || !\is_int($value) || $value < 1 || $value > self::MAXIMUM_STRING_SIZE, ARRAY_FILTER_USE_BOTH)) {
                throw new \InvalidArgumentException('The Autosave dynamic row schema is invalid.');
            }
            $descriptor['columns']  = $columns;
            $descriptor['maxItems'] = $this->normalizeMaximumItems($field);
        }

        return $descriptor;
    }

    private function normalizeMaximumItems(array $field): int
    {
        $maximum = $field['maxItems'] ?? self::MAXIMUM_ITEMS;

        if (!\is_int($maximum) || $maximum < 1 || $maximum > self::MAXIMUM_ITEMS) {
            throw new \InvalidArgumentException('The Autosave dynamic collection bound is invalid.');
        }

        return $maximum;
    }

    private function normalizeValue(mixed $value, array $field): mixed
    {
        if ($field['kind'] === 'boolean') {
            if (!\is_bool($value)) {
                throw new \InvalidArgumentException('The dynamic Autosave boolean is invalid.');
            }
            return $value;
        }

        if ($field['kind'] === 'string' || $field['kind'] === 'enum') {
            if (!\is_string($value) || preg_match('//u', $value) !== 1 || ($field['kind'] === 'string' && StringHelper::strlen($value) > $field['maxLength']) || ($field['kind'] === 'enum' && !\in_array($value, $field['values'], true))) {
                throw new \InvalidArgumentException('The dynamic Autosave scalar is invalid.');
            }
            return $value;
        }

        if ($field['kind'] === 'strings') {
            if (!\is_array($value) || !array_is_list($value) || \count($value) > $field['maxItems'] || array_filter($value, fn ($item) => !\is_string($item) || !\in_array($item, $field['values'], true))) {
                throw new \InvalidArgumentException('The dynamic Autosave collection is invalid.');
            }
            return array_values(array_unique($value));
        }

        if (!\is_array($value) || !array_is_list($value) || \count($value) > $field['maxItems']) {
            throw new \InvalidArgumentException('The dynamic Autosave rows are invalid.');
        }

        $rows = [];
        foreach ($value as $row) {
            if (!\is_array($row) || array_is_list($row) || array_diff_key($row, $field['columns']) || array_diff_key($field['columns'], $row)) {
                throw new \InvalidArgumentException('A dynamic Autosave row is invalid.');
            }
            foreach ($field['columns'] as $column => $maximum) {
                if (!\is_string($row[$column]) || preg_match('//u', $row[$column]) !== 1 || StringHelper::strlen($row[$column]) > $maximum) {
                    throw new \InvalidArgumentException('A dynamic Autosave row value is invalid.');
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
