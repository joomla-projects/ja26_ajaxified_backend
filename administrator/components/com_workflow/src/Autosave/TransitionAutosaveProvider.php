<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\TargetAwareAutosaveProviderInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class TransitionAutosaveProvider implements TargetAwareAutosaveProviderInterface, AutosaveCreateProviderInterface
{
    private const KEYS = ['title', 'description', 'from_stage_id', 'to_stage_id'];

    /** @var callable(): int */
    private $workflowIdResolver;

    public function __construct(private readonly DatabaseInterface $db, ?callable $workflowIdResolver = null)
    {
        // The owning Workflow is immutable creation scope. It is never read from the
        // Autosave request: TransitionModel::getForm() puts it into server-side user
        // state when the native new-Transition form is built, and only that state is
        // consulted here.
        $this->workflowIdResolver = $workflowIdResolver
            ?? static fn (): int => (int) Factory::getApplication()
                ->getUserState('com_workflow.transition.filter.workflow_id');
    }

    public function getContext(): string
    {
        return 'com_workflow.transition';
    }

    public function getCreateContractVersion(): string
    {
        return 'workflow-transition-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $workflowId = ($this->workflowIdResolver)();
        $workflow   = $workflowId > 0 && $workflowId <= 2147483647 ? $this->loadWorkflow($workflowId) : null;

        if ($workflow === null) {
            throw new AutosaveException('forbidden', 'A Workflow Transition has no resolvable owning Workflow.');
        }

        // TransitionController::allowAdd() scopes creation to the owning Workflow asset.
        // The component part is taken from the stored Workflow row rather than the request,
        // so a forged extension cannot move the permission check to another component.
        $component = explode('.', (string) $workflow->extension, 2)[0];

        if ($component === '' || !$user->authorise('core.create', $component . '.workflow.' . $workflowId)) {
            throw new AutosaveException('forbidden', 'A Workflow Transition cannot be created in this Workflow.');
        }

        // Both authored stage relations must belong to the resolved Workflow, so a draft
        // can never pair "Workflow A" with a stage owned by "Workflow B".
        if (
            $normalizedPayload !== null
            && (
                !$this->stageIsValid($workflowId, (int) $normalizedPayload['from_stage_id'], true)
                || !$this->stageIsValid($workflowId, (int) $normalizedPayload['to_stage_id'], false)
            )
        ) {
            throw $this->invalidPayload();
        }
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)) {
            throw new AutosaveException('invalid_target', 'The Workflow Transition target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        return $record !== null && $record->extension !== null;
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null || $record->extension === null) {
            throw new AutosaveException('target_not_found', 'The Workflow Transition was not found.');
        }

        $component = explode('.', $record->extension, 2)[0];
        $asset     = $component . '.transition.' . (int) $record->id;

        if (!$user->authorise('core.edit', $asset)) {
            throw new AutosaveException('forbidden', 'The Workflow Transition cannot be edited by this user.');
        }

        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Workflow Transition is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null || $record->extension === null || !$this->stageIsValid((int) $record->workflow_id, (int) $record->from_stage_id, true) || !$this->stageIsValid((int) $record->workflow_id, (int) $record->to_stage_id, false)) {
            throw new AutosaveException('target_not_found', 'The Workflow Transition relations are invalid.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_workflow.transition:base-revision:v1';

        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required = array_fill_keys(self::KEYS, true);

        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $required) || array_diff_key($required, $payload)) {
            throw $this->invalidPayload();
        }

        foreach (['title' => 255, 'description' => 65535] as $key => $limit) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1 || StringHelper::strlen($payload[$key]) > $limit) {
                throw $this->invalidPayload();
            }
        }

        if (!\is_int($payload['from_stage_id']) || !\is_int($payload['to_stage_id']) || $payload['from_stage_id'] < -1 || $payload['from_stage_id'] === 0 || $payload['from_stage_id'] > 2147483647 || $payload['to_stage_id'] <= 0 || $payload['to_stage_id'] > 2147483647) {
            throw $this->invalidPayload();
        }

        return array_replace(array_fill_keys(self::KEYS, null), $payload);
    }

    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $normalized = $this->normalizePayload($payload, $schemaVersion);
        $record     = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null || !$this->stageIsValid((int) $record->workflow_id, $normalized['from_stage_id'], true) || !$this->stageIsValid((int) $record->workflow_id, $normalized['to_stage_id'], false)) {
            throw $this->invalidPayload();
        }

        return $normalized;
    }

    private function stageIsValid(int $workflowId, int $stageId, bool $allowAny): bool
    {
        if ($allowAny && $stageId === -1) {
            return true;
        }

        $query = $this->db->createQuery()->select('COUNT(*)')->from($this->db->quoteName('#__workflow_stages'))->where($this->db->quoteName('id') . ' = :id')->where($this->db->quoteName('workflow_id') . ' = :workflow')->where($this->db->quoteName('published') . ' = 1')->bind(':id', $stageId, ParameterType::INTEGER)->bind(':workflow', $workflowId, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() === 1;
    }

    private function loadWorkflow(int $workflowId): ?object
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['id', 'extension']))
            ->from($this->db->quoteName('#__workflows'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $workflowId, ParameterType::INTEGER);

        $record = $this->db->setQuery($query)->loadObject() ?: null;

        return $record !== null && \is_string($record->extension) && $record->extension !== '' ? $record : null;
    }

    private function load(string $targetId): ?object
    {
        $query = $this->db->createQuery()
            ->select(['t.' . $this->db->quoteName('id'), 't.' . $this->db->quoteName('workflow_id'), 't.' . $this->db->quoteName('title'), 't.' . $this->db->quoteName('description'), 't.' . $this->db->quoteName('from_stage_id'), 't.' . $this->db->quoteName('to_stage_id'), 't.' . $this->db->quoteName('published'), 't.' . $this->db->quoteName('ordering'), 't.' . $this->db->quoteName('options'), 't.' . $this->db->quoteName('checked_out'), 'w.' . $this->db->quoteName('extension')])
            ->from($this->db->quoteName('#__workflow_transitions', 't'))
            ->leftJoin($this->db->quoteName('#__workflows', 'w') . ' ON w.' . $this->db->quoteName('id') . ' = t.' . $this->db->quoteName('workflow_id'))
            ->where('t.' . $this->db->quoteName('id') . ' = :id')
            ->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Workflow Transition draft payload is invalid.');
    }
}
