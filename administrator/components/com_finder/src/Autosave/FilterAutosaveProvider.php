<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_finder
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Finder\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Bounded Autosave contract for existing Finder Filter records.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FilterAutosaveProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    private const STRING_LIMITS = [
        'title'            => 255,
        'alias'            => 255,
        'created'          => 255,
        'created_alt'      => 255,
        'created_by'       => 10,
        'created_by_alias' => 255,
    ];

    private const DATE_STRING_LIMIT = 255;
    private const MAXIMUM_ID        = '2147483647';
    private const MAXIMUM_MAPS      = 1000;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getContext(): string
    {
        return 'com_finder.filter';
    }

    public function getCreateContractVersion(): string
    {
        return 'finder-filter-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        if (!$user->authorise('core.create', 'com_finder')) {
            throw new AutosaveException('forbidden', 'A Finder Filter cannot be created by this user.');
        }
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (!$this->isCanonicalPositiveId($targetId)) {
            throw new AutosaveException('invalid_target', 'The Finder Filter target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return $this->load($this->canonicalizeTargetId($targetId)) !== null;
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Finder Filter was not found.');
        }

        if (!$user->authorise('core.edit', 'com_finder')) {
            throw new AutosaveException('forbidden', 'The Finder Filter cannot be edited by this user.');
        }

        if ((int) $record->checked_out !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Finder Filter is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Finder Filter was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_finder.filter:base-revision:v1';

        return $domain . ':' . hash(
            'sha256',
            $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $keys = [...array_keys(self::STRING_LIMITS), 'state', 'params', 'taxonomy_ids'];

        if (
            $schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload)
            || array_diff_key($payload, array_fill_keys($keys, true)) !== []
            || array_diff_key(array_fill_keys($keys, true), $payload) !== []
        ) {
            throw $this->invalidPayload();
        }

        foreach (self::STRING_LIMITS as $key => $limit) {
            if (!$this->isBoundedString($payload[$key], $limit)) {
                throw $this->invalidPayload();
            }
        }

        if ($payload['created_by'] !== '' && !$this->isCanonicalNonNegativeId($payload['created_by'])) {
            throw $this->invalidPayload();
        }

        if (!\is_int($payload['state']) || !\in_array($payload['state'], [0, 1], true)) {
            throw $this->invalidPayload();
        }

        $params    = $payload['params'];
        $paramKeys = ['w1', 'd1', 'd1_alt', 'w2', 'd2', 'd2_alt'];

        if (
            !\is_array($params) || array_is_list($params)
            || array_diff_key($params, array_fill_keys($paramKeys, true)) !== []
            || array_diff_key(array_fill_keys($paramKeys, true), $params) !== []
        ) {
            throw $this->invalidPayload();
        }

        foreach (['w1', 'w2'] as $key) {
            if (!\is_string($params[$key]) || !\in_array($params[$key], ['', '-1', '0', '1'], true)) {
                throw $this->invalidPayload();
            }
        }

        foreach (['d1', 'd1_alt', 'd2', 'd2_alt'] as $key) {
            if (!$this->isBoundedString($params[$key], self::DATE_STRING_LIMIT)) {
                throw $this->invalidPayload();
            }
        }

        if (
            !\is_array($payload['taxonomy_ids']) || !array_is_list($payload['taxonomy_ids'])
            || \count($payload['taxonomy_ids']) > self::MAXIMUM_MAPS
        ) {
            throw $this->invalidPayload();
        }

        $seen = [];

        foreach ($payload['taxonomy_ids'] as $id) {
            if (!\is_string($id) || !$this->isCanonicalPositiveId($id) || isset($seen[$id])) {
                throw $this->invalidPayload();
            }

            $seen[$id] = true;
        }

        return array_replace(array_fill_keys($keys, null), $payload);
    }

    private function load(string $targetId): ?object
    {
        $fields = [
            'filter_id', 'title', 'alias', 'state', 'created', 'created_by', 'created_by_alias',
            'modified', 'modified_by', 'checked_out', 'map_count', 'data', 'params',
        ];
        $query = $this->db->createQuery()
            ->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))
            ->from($this->db->quoteName('#__finder_filters'))
            ->where($this->db->quoteName('filter_id') . ' = :id')
            ->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function isCanonicalPositiveId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1
            && (\strlen($value) < 10 || strcmp($value, self::MAXIMUM_ID) <= 0);
    }

    private function isCanonicalNonNegativeId(string $value): bool
    {
        return $value === '0' || $this->isCanonicalPositiveId($value);
    }

    private function isBoundedString(mixed $value, int $limit): bool
    {
        return \is_string($value) && preg_match('//u', $value) === 1 && StringHelper::strlen($value) <= $limit;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Finder Filter draft payload is invalid.');
    }
}
