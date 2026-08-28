<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\TargetAwareAutosaveProviderInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class FieldAutosaveProvider implements TargetAwareAutosaveProviderInterface
{
    private const STRING_LIMITS = ['title' => 255, 'name' => 255, 'label' => 255, 'description' => 65535, 'default_value' => 65535, 'note' => 255];

    /** @var callable(string): AutosaveDynamicSchema */
    private $schemaResolver;

    private FieldAutosaveSchemaFactory $schemaFactory;

    public function __construct(private readonly DatabaseInterface $db, ?callable $schemaResolver = null)
    {
        $this->schemaFactory  = new FieldAutosaveSchemaFactory();
        $this->schemaResolver = $schemaResolver ?? fn (string $type): AutosaveDynamicSchema => $this->schemaFactory->forType($type);
    }

    public function getContext(): string
    {
        return 'com_fields.field';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)) {
            throw new AutosaveException('invalid_target', 'The Custom Field target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Custom Field was not found.');
        }

        $component = explode('.', $record->context, 2)[0];
        $asset     = $component . '.field.' . (int) $record->id;
        if (
            !$user->authorise('core.edit', $asset)
            && !($user->authorise('core.edit.own', $asset) && (int) $record->created_user_id === (int) $user->id)
        ) {
            throw new AutosaveException('forbidden', 'The Custom Field cannot be edited by this user.');
        }

        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Custom Field is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Custom Field was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_fields.field:base-revision:v1';

        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function getDynamicSchema(string $targetId): AutosaveDynamicSchema
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Custom Field was not found.');
        }

        return ($this->schemaResolver)((string) $record->type);
    }

    public function getDynamicSchemaForForm(string $targetId, Form $form): AutosaveDynamicSchema
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Custom Field was not found.');
        }

        return $this->schemaFactory->fromForm($form, (string) $record->type);
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        throw $this->invalidPayload();
    }

    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $keys = array_fill_keys([...array_keys(self::STRING_LIMITS), 'required', 'only_use_in_subform', 'schemaFingerprint', 'fieldparams'], true);
        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $keys) || array_diff_key($keys, $payload)) {
            throw $this->invalidPayload();
        }

        foreach (self::STRING_LIMITS as $name => $limit) {
            if (!\is_string($payload[$name]) || preg_match('//u', $payload[$name]) !== 1 || StringHelper::strlen($payload[$name]) > $limit) {
                throw $this->invalidPayload();
            }
        }

        if (!\is_bool($payload['required']) || !\is_bool($payload['only_use_in_subform']) || !\is_string($payload['schemaFingerprint'])) {
            throw $this->invalidPayload();
        }

        $schema = $this->getDynamicSchema($this->canonicalizeTargetId($targetId));
        if (!hash_equals($schema->fingerprint(), $payload['schemaFingerprint'])) {
            throw new AutosaveException('invalid_payload', 'The Custom Field draft schema is stale.');
        }

        try {
            $dynamic = $schema->normalizePayload(['fieldparams' => $payload['fieldparams']])['fieldparams'];
        } catch (\InvalidArgumentException) {
            throw $this->invalidPayload();
        }

        return array_merge(array_intersect_key($payload, self::STRING_LIMITS), [
            'required'            => $payload['required'],
            'only_use_in_subform' => $payload['only_use_in_subform'],
            'schemaFingerprint'   => $schema->fingerprint(),
            'fieldparams'         => $dynamic,
        ]);
    }

    private function load(string $targetId): ?object
    {
        $fields = ['id', 'asset_id', 'context', 'group_id', 'title', 'name', 'label', 'default_value', 'type', 'note', 'description', 'state', 'required', 'only_use_in_subform', 'params', 'fieldparams', 'language', 'created_time', 'created_user_id', 'modified_time', 'modified_by', 'access', 'checked_out'];
        $query  = $this->db->createQuery()->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))->from($this->db->quoteName('#__fields'))->where($this->db->quoteName('id') . ' = :id')->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Custom Field draft payload is invalid.');
    }
}
