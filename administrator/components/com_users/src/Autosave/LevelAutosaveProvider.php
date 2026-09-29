<?php

/** @package Joomla.Administrator @subpackage com_users */

namespace Joomla\Component\Users\Administrator\Autosave;

use Joomla\CMS\Access\Access;
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

final class LevelAutosaveProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    /**
     * An Access Level draft may name at most this many user groups. The native
     * form renders one checkbox per existing group and the rules column stores
     * a JSON array, so the bound is a sanity ceiling rather than a native limit.
     */
    private const MAXIMUM_GROUPS = 100;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_users.level';
    }
    public function getCreateContractVersion(): string
    {
        return 'user-level-create-v1';
    }
    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // Mirrors LevelController::allowSave() for a new record: the com_users
        // core.admin gate plus FormController::allowAdd().
        if (
            !$user->authorise('core.admin', 'com_users')
            || (!$user->authorise('core.create', 'com_users') && \count($user->getAuthorisedCategories('com_users', 'core.create')) === 0)
        ) {
            throw new AutosaveException('forbidden', 'An Access Level cannot be created by this user.');
        }

        if ($normalizedPayload === null) {
            return;
        }

        $rules = $normalizedPayload['rules'];

        // Every authored group must resolve to a real user group. The browser's
        // group ids are never authority.
        foreach ($rules as $groupId) {
            if (!$this->groupExists((int) $groupId)) {
                throw $this->invalidPayload();
            }
        }

        // Mirrors LevelModel::validate(): a user who is not a global super user
        // may not author a level that assigns a super-user group.
        if (
            !$user->authorise('core.admin')
            && \count(array_filter($rules, static fn ($groupId) => Access::checkGroup((int) $groupId, 'core.admin'))) > 0
        ) {
            throw new AutosaveException('forbidden', 'An Access Level cannot assign super-user groups by this user.');
        }
    }
    public function getPayloadSchemaVersion(): int
    {
        return 2;
    }
    public function canonicalizeTargetId(string $id): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || (\strlen($id) === 10 && strcmp($id, '4294967295') > 0)) {
            throw new AutosaveException('invalid_target', 'The Access Level target is invalid.');
        }
        return $id;
    }
    public function targetExists(string $id): bool
    {
        return $this->load($this->canonicalizeTargetId($id)) !== null;
    }
    public function authorize(User $user, string $id, AutosaveOperation $operation): void
    {
        $record = $this->load($this->canonicalizeTargetId($id));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Access Level was not found.');
        }

        $rules = json_decode($record->rules, true);
        $first = \is_array($rules) && isset($rules[0]) ? (int) $rules[0] : 0;

        if (
            !$user->authorise('core.admin', 'com_users')
            || !$user->authorise('core.edit', 'com_users')
            || ($first > 0 && !$user->authorise('core.admin') && Access::checkGroup($first, 'core.admin'))
        ) {
            throw new AutosaveException('forbidden', 'The Access Level cannot be edited by this user.');
        }
    }
    public function getBaseRevision(string $id): string
    {
        $record = $this->load($this->canonicalizeTargetId($id));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Access Level was not found.');
        }
        $domain = 'autosave:com_users.level:base-revision:v1';
        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_THROW_ON_ERROR));
    }
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        if ($schemaVersion !== 2) {
            throw new AutosaveException('unsupported_schema_version', 'The Autosave payload schema version is not supported.');
        }

        if (
            !\is_array($payload) || array_is_list($payload)
            || array_keys($payload) !== ['title', 'rules']
            || !\is_string($payload['title']) || preg_match('//u', $payload['title']) !== 1
            || StringHelper::strlen($payload['title']) > 100
            || !\is_array($payload['rules']) || !array_is_list($payload['rules'])
            || \count($payload['rules']) > self::MAXIMUM_GROUPS
        ) {
            throw new AutosaveException('invalid_payload', 'The Access Level draft payload is invalid.');
        }

        $rules = [];

        foreach ($payload['rules'] as $groupId) {
            if (!\is_int($groupId) || $groupId < 1 || $groupId > 2147483647) {
                throw new AutosaveException('invalid_payload', 'The Access Level draft payload is invalid.');
            }

            $rules[] = $groupId;
        }

        return ['title' => $payload['title'], 'rules' => $rules];
    }
    private function groupExists(int $groupId): bool
    {
        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__usergroups'))
            ->where($this->db->quoteName('id') . ' = :groupId')
            ->bind(':groupId', $groupId, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() === 1;
    }
    private function load(string $id): ?object
    {
        $query = $this->db->createQuery()->select(['id', 'title', 'ordering', 'rules'])->from($this->db->quoteName('#__viewlevels'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Access Level draft payload is invalid.');
    }
}
