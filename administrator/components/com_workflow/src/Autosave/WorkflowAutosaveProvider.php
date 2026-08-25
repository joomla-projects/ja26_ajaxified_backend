<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Autosave;

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

final class WorkflowAutosaveProvider implements AutosaveProviderInterface
{
    private const CONTEXT       = 'com_workflow.workflow';
    private const MAXIMUM_ID    = '2147483647';
    private const STRING_LIMITS = ['title' => 255, 'description' => 65535];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getContext(): string
    {
        return self::CONTEXT;
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, self::MAXIMUM_ID) > 0)) {
            throw new AutosaveException('invalid_target', 'The Workflow target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Workflow was not found.');
        }
        $asset = $record->extension . '.workflow.' . (int) $record->id;
        if (!$user->authorise('core.edit', $asset) && !($user->authorise('core.edit.own', $asset) && (int) $record->created_by === (int) $user->id)) {
            throw new AutosaveException('forbidden', 'The Workflow cannot be edited by this user.');
        }
        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Workflow is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Workflow was not found.');
        }
        return $this->revision($record);
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required = ['title' => true, 'description' => true];

        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $required) || array_diff_key($required, $payload)) {
            throw $this->invalidPayload();
        }
        foreach (self::STRING_LIMITS as $key => $limit) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1 || StringHelper::strlen($payload[$key]) > $limit) {
                throw $this->invalidPayload();
            }
        }
        return ['title' => $payload['title'], 'description' => $payload['description']];
    }

    private function load(string $targetId): ?object
    {
        $fields = ['id', 'asset_id', 'published', 'title', 'description', 'extension', 'default', 'ordering', 'created', 'created_by', 'modified', 'modified_by', 'checked_out', 'checked_out_time'];
        $query  = $this->db->createQuery()->select(array_map(fn ($f) => $this->db->quoteName($f), $fields))->from($this->db->quoteName('#__workflows'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $targetId, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function revision(object $record): string
    {
        $domain = 'autosave:com_workflow.workflow:base-revision:v1';
        $json   = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $domain . ':' . hash('sha256', $domain . "\0" . $json);
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Workflow draft payload is invalid.');
    }
}
