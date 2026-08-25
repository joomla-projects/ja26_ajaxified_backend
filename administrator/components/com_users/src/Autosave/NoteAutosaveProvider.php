<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_users
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Users\Administrator\Autosave;

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

final class NoteAutosaveProvider implements AutosaveProviderInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_users.note';
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }
    public function canonicalizeTargetId(string $id): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || (\strlen($id) === 10 && strcmp($id, '4294967295') > 0)) {
            throw new AutosaveException('invalid_target', 'The User Note target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The User Note was not found.');
        }
        $asset = 'com_users.category.' . (int) $record->catid;
        if (!$user->authorise('core.edit', $asset) && !($user->authorise('core.edit.own', $asset) && (int) $record->created_user_id === (int) $user->id)) {
            throw new AutosaveException('forbidden', 'The User Note cannot be edited by this user.');
        }
        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The User Note is checked out by another user.');
        }
    }
    public function getBaseRevision(string $id): string
    {
        $record = $this->load($this->canonicalizeTargetId($id));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The User Note was not found.');
        }
        $domain = 'autosave:com_users.note:base-revision:v1';
        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required = ['subject' => true, 'body' => true];

        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $required) || array_diff_key($required, $payload)) {
            throw $this->invalid();
        }
        foreach (['subject' => 100, 'body' => 65535] as $key => $limit) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1 || StringHelper::strlen($payload[$key]) > $limit) {
                throw $this->invalid();
            }
        }
        return ['subject' => $payload['subject'], 'body' => $payload['body']];
    }
    private function load(string $id): ?object
    {
        $fields = ['id', 'user_id', 'catid', 'subject', 'body', 'state', 'checked_out', 'checked_out_time', 'created_user_id', 'created_time', 'modified_user_id', 'modified_time', 'review_time', 'publish_up', 'publish_down'];
        $query  = $this->db->createQuery()->select(array_map(fn ($f) => $this->db->quoteName($f), $fields))->from($this->db->quoteName('#__user_notes'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
    private function invalid(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The User Note draft payload is invalid.');
    }
}
