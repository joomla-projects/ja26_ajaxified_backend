<?php

/** @package Joomla.Administrator @subpackage com_users */

namespace Joomla\Component\Users\Administrator\Autosave;

use Joomla\CMS\Access\Access;
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

final class LevelAutosaveProvider implements AutosaveProviderInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_users.level';
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
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
            || ($first > 0 && Access::checkGroup($first, 'core.admin') && !$user->authorise('core.admin'))
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
        if ($schemaVersion !== 1 || !\is_array($payload) || array_keys($payload) !== ['title'] || !\is_string($payload['title']) || preg_match('//u', $payload['title']) !== 1 || StringHelper::strlen($payload['title']) > 100) {
            throw new AutosaveException('invalid_payload', 'The Access Level draft payload is invalid.');
        }
        return ['title' => $payload['title']];
    }
    private function load(string $id): ?object
    {
        $query = $this->db->createQuery()->select(['id', 'title', 'ordering', 'rules'])->from($this->db->quoteName('#__viewlevels'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
}
