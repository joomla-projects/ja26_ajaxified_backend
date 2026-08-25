<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Autosave;

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

final class GroupAutosaveProvider implements AutosaveProviderInterface
{
    private const LIMITS = ['title' => 255, 'note' => 255, 'description' => 65535];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_fields.group';
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }
    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)) {
            throw new AutosaveException('invalid_target', 'The Field Group target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Field Group was not found.');
        }
        $component = explode('.', $record->context, 2)[0];
        $asset     = $component . '.fieldgroup.' . (int) $record->id;
        if (
            !$user->authorise('core.edit', $asset)
            && !(($user->authorise('core.edit.own', $asset) || $user->authorise('core.edit.own', $component))
                && (int) $record->created_by === (int) $user->id)
        ) {
            throw new AutosaveException('forbidden', 'The Field Group cannot be edited by this user.');
        }
        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Field Group is checked out by another user.');
        }
    }
    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Field Group was not found.');
        }
        unset($record->checked_out);
        $domain = 'autosave:com_fields.group:base-revision:v1';
        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required = array_fill_keys(array_keys(self::LIMITS), true);
        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $required) || array_diff_key($required, $payload)) {
            throw $this->invalidPayload();
        }
        foreach (self::LIMITS as $key => $limit) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1 || StringHelper::strlen($payload[$key]) > $limit) {
                throw $this->invalidPayload();
            }
        }
        $normalized = [];

        foreach (array_keys($required) as $key) {
            $normalized[$key] = $payload[$key];
        }

        return $normalized;
    }
    private function load(string $targetId): ?object
    {
        $fields = ['id', 'asset_id', 'context', 'title', 'note', 'description', 'state', 'ordering', 'params', 'language', 'created', 'created_by', 'modified', 'modified_by', 'access', 'checked_out'];
        $query  = $this->db->createQuery()->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))->from($this->db->quoteName('#__fields_groups'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $targetId, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Field Group draft payload is invalid.');
    }
}
