<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
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

final class StageAutosaveProvider implements AutosaveProviderInterface
{
    private const MAXIMUM_ID = '2147483647';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_workflow.stage';
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }
    public function canonicalizeTargetId(string $id): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || (\strlen($id) === 10 && strcmp($id, self::MAXIMUM_ID) > 0)) {
            throw new AutosaveException('invalid_target', 'The Stage target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Stage was not found.');
        }
        if (!$user->authorise('core.edit', $record->extension . '.stage.' . (int) $record->id)) {
            throw new AutosaveException('forbidden', 'The Stage cannot be edited by this user.');
        }
        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Stage is checked out by another user.');
        }
    }
    public function getBaseRevision(string $id): string
    {
        $record = $this->load($this->canonicalizeTargetId($id));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Stage was not found.');
        }
        $domain = 'autosave:com_workflow.stage:base-revision:v1';
        $json   = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $domain . ':' . hash('sha256', $domain . "\0" . $json);
    }
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required = ['title' => true, 'description' => true];

        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $required) || array_diff_key($required, $payload)) {
            throw $this->invalid();
        }
        foreach (['title' => 255, 'description' => 65535] as $key => $limit) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1 || StringHelper::strlen($payload[$key]) > $limit) {
                throw $this->invalid();
            }
        }
        return ['title' => $payload['title'], 'description' => $payload['description']];
    }
    private function load(string $id): ?object
    {
        $fields = ['s.id', 's.asset_id', 's.ordering', 's.workflow_id', 's.published', 's.title', 's.description', 's.default', 's.position', 's.checked_out', 's.checked_out_time', 'w.extension'];
        $query  = $this->db->createQuery()->select(array_map(fn ($f) => $this->db->quoteName($f), $fields))->from($this->db->quoteName('#__workflow_stages', 's'))->join('INNER', $this->db->quoteName('#__workflows', 'w') . ' ON ' . $this->db->quoteName('w.id') . ' = ' . $this->db->quoteName('s.workflow_id'))->where($this->db->quoteName('s.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
    private function invalid(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Stage draft payload is invalid.');
    }
}
