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

final class GroupAutosaveProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_users.group';
    }
    public function getCreateContractVersion(): string
    {
        return 'user-group-create-v1';
    }
    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // Mirrors GroupController::allowSave() for a new record: the com_users core.admin
        // gate plus FormController::allowAdd(). The draft payload carries only the title,
        // so no hierarchy relation is authored through Autosave and the native parent_id
        // safeguards in GroupModel::save() stay the sole authority over placement.
        if (
            !$user->authorise('core.admin', 'com_users')
            || (!$user->authorise('core.create', 'com_users') && \count($user->getAuthorisedCategories('com_users', 'core.create')) === 0)
        ) {
            throw new AutosaveException('forbidden', 'A User Group cannot be created by this user.');
        }
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }
    public function canonicalizeTargetId(string $id): string
    {
        return $this->canonical($id);
    }
    public function targetExists(string $id): bool
    {
        return $this->load($this->canonical($id)) !== null;
    }
    public function authorize(User $user, string $id, AutosaveOperation $operation): void
    {
        $record = $this->load($this->canonical($id));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The User Group was not found.');
        }
        if (
            !$user->authorise('core.admin', 'com_users')
            || !$user->authorise('core.edit', 'com_users')
            || (Access::checkGroup((int) $record->id, 'core.admin') && !$user->authorise('core.admin'))
        ) {
            throw new AutosaveException('forbidden', 'The User Group cannot be edited by this user.');
        }
    }
    public function getBaseRevision(string $id): string
    {
        return $this->revision($this->required($id), 'group');
    }
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        if ($schemaVersion !== 1 || !\is_array($payload) || array_keys($payload) !== ['title'] || !\is_string($payload['title']) || preg_match('//u', $payload['title']) !== 1 || StringHelper::strlen($payload['title']) > 100) {
            throw new AutosaveException('invalid_payload', 'The User Group draft payload is invalid.');
        }
        return ['title' => $payload['title']];
    }
    private function canonical(string $id): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || (\strlen($id) === 10 && strcmp($id, '4294967295') > 0)) {
            throw new AutosaveException('invalid_target', 'The User Group target is invalid.');
        }
        return $id;
    }
    private function load(string $id): ?object
    {
        $query = $this->db->createQuery()->select(['id', 'parent_id', 'lft', 'rgt', 'title'])->from($this->db->quoteName('#__usergroups'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
    private function required(string $id): object
    {
        $record = $this->load($this->canonical($id));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The User Group was not found.');
        } return $record;
    }
    private function revision(object $record, string $name): string
    {
        $domain = 'autosave:com_users.' . $name . ':base-revision:v1';
        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_THROW_ON_ERROR));
    }
}
