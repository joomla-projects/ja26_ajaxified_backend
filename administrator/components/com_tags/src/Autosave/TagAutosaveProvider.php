<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_tags
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Tags\Administrator\Autosave;

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

final class TagAutosaveProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    private const LIMITS = ['title' => 255, 'note' => 255, 'description' => 65535, 'version_note' => 255, 'metadesc' => 300, 'metakey' => 1024];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_tags.tag';
    }
    public function getPayloadSchemaVersion(): int
    {
        return 2;
    }
    public function getCreateContractVersion(): string
    {
        return 'tag-create-v1';
    }
    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        if (!$user->authorise('core.create', 'com_tags')) {
            throw new AutosaveException('forbidden', 'A Tag cannot be created by this user.');
        }
        if ($normalizedPayload !== null && (!$this->isPositiveId($normalizedPayload['parent_id'] ?? null) || $this->load((string) $normalizedPayload['parent_id']) === null)) {
            throw $this->invalidPayload();
        }
    }
    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)) {
            throw new AutosaveException('invalid_target', 'The Tag target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Tag was not found.');
        }
        if (!$user->authorise('core.edit', 'com_tags')) {
            throw new AutosaveException('forbidden', 'The Tag cannot be edited by this user.');
        }
        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Tag is checked out by another user.');
        }
    }
    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Tag was not found.');
        }
        unset($record->checked_out);
        $domain = 'autosave:com_tags.tag:base-revision:v1';
        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required = array_fill_keys([...array_keys(self::LIMITS), 'parent_id'], true);
        if ($schemaVersion !== 2 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $required) || array_diff_key($required, $payload)) {
            throw $this->invalidPayload();
        }
        if (!$this->isPositiveId($payload['parent_id'])) {
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
    private function isPositiveId(mixed $id): bool
    {
        return \is_int($id) && $id > 0 && $id <= 2147483647;
    }
    private function load(string $targetId): ?object
    {
        $fields = ['id', 'parent_id', 'lft', 'rgt', 'level', 'path', 'title', 'alias', 'note', 'description', 'published', 'access', 'params', 'metadesc', 'metakey', 'metadata', 'created_user_id', 'created_time', 'created_by_alias', 'modified_user_id', 'modified_time', 'images', 'urls', 'hits', 'language', 'version', 'publish_up', 'publish_down', 'checked_out'];
        $query  = $this->db->createQuery()->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))->from($this->db->quoteName('#__tags'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $targetId, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Tag draft payload is invalid.');
    }
}
