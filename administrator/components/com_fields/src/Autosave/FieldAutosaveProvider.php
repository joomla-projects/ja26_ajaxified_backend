<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\TargetAwareAutosaveProviderInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Fields\FieldsServiceInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class FieldAutosaveProvider implements
    TargetAwareAutosaveProviderInterface,
    AutosaveCreateProviderInterface,
    AutosaveDynamicCreateDescriptorProviderInterface
{
    private const STRING_LIMITS = ['title' => 255, 'name' => 255, 'label' => 255, 'description' => 65535, 'default_value' => 65535, 'note' => 255];

    private const DESCRIPTOR_PREFIX = 'fd1';

    /** @var callable(string): AutosaveDynamicSchema */
    private $schemaResolver;

    /** @var callable(string): bool */
    private $typeResolver;

    /** @var callable(string): bool */
    private $contextResolver;

    private FieldAutosaveSchemaFactory $schemaFactory;

    public function __construct(
        private readonly DatabaseInterface $db,
        ?callable $schemaResolver = null,
        ?callable $typeResolver = null,
        ?callable $contextResolver = null
    ) {
        $this->schemaFactory  = new FieldAutosaveSchemaFactory();
        $this->schemaResolver = $schemaResolver ?? fn (string $type): AutosaveDynamicSchema => $this->schemaFactory->forType($type);
        // Mirrors the native type <select>: a type is only selectable when its field
        // plugin is enabled (FieldsHelper dispatches onCustomFieldsGetTypes over the
        // enabled plugin group). Never trusts a bare type string.
        $this->typeResolver = $typeResolver ?? static fn (string $type): bool => \array_key_exists($type, FieldsHelper::getFieldTypes());
        // Mirrors the native context pickers: a context is only a genuine Custom Field
        // context when the owning extension registers it. com_fields.field itself is
        // never a field context, so the bare component is refused as a "nonsense"
        // situation exactly like the native views refuse to render it.
        $this->contextResolver = $contextResolver ?? static function (string $context): bool {
            $component = explode('.', $context, 2)[0];

            try {
                $extension = Factory::getApplication()->bootComponent($component);
            } catch (\Throwable) {
                return false;
            }

            return $extension instanceof FieldsServiceInterface && \array_key_exists($context, $extension->getContexts());
        };
    }

    public function getContext(): string
    {
        return 'com_fields.field';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function getCreateContractVersion(): string
    {
        return 'field-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // A Custom Field can only be authorized against its anchored creation
        // descriptor. The lifecycle routes every provisional operation of this
        // provider through authorizeStaticCreateScope(); reaching this method means
        // no anchored descriptor is available, so it fails closed.
        throw new AutosaveException('scope_required', 'A Custom Field requires an anchored creation descriptor.');
    }

    public function getStaticScopeContractVersion(): string
    {
        return 'field-descriptor-v1';
    }

    public function canonicalizeStaticCreateScope(mixed $candidateScope): string
    {
        if (!\is_string($candidateScope)) {
            throw $this->invalidScope();
        }

        // Accepts the compact raw pair "context|type" the create-mode view proposes
        // (the browser never carries more than these two identifiers) as well as the
        // canonical descriptor token, so re-canonicalizing a bound token is idempotent.
        if (str_contains($candidateScope, '|')) {
            $raw = explode('|', $candidateScope, 2);

            if (\count($raw) !== 2 || !$this->validContext($raw[0]) || !$this->validTypeName($raw[1])) {
                throw $this->invalidScope();
            }

            $context     = $raw[0];
            $type        = $raw[1];
            $fingerprint = null;
        } else {
            $parts = explode(':', $candidateScope, 4);

            if (\count($parts) !== 4 || $parts[0] !== self::DESCRIPTOR_PREFIX) {
                throw $this->invalidScope();
            }

            [$context, $type, $fingerprint] = [$parts[1], $parts[2], $parts[3]];

            if (!$this->validContext($context) || !$this->validTypeName($type) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw $this->invalidScope();
            }
        }

        // The type must be a real enabled field plugin and the context a registered
        // Custom Field context of its owning extension; both are re-verified on every
        // later provisional operation so a disabled plugin or revoked context fails
        // closed instead of being silently trusted from the candidate.
        if (!$this->typeSupported($type) || !($this->contextResolver)($context)) {
            throw $this->invalidScope();
        }

        // The fingerprint must equal the schema the server can build right now. A
        // candidate carrying a stale or fabricated fingerprint is refused, so a P1
        // can never anchor a schema the server does not own.
        $schema = ($this->schemaResolver)($type);

        if ($fingerprint !== null && !hash_equals($schema->fingerprint(), $fingerprint)) {
            throw $this->invalidScope();
        }

        return self::DESCRIPTOR_PREFIX . ':' . $context . ':' . $type . ':' . $schema->fingerprint();
    }

    public function authorizeStaticCreateScope(User $user, string $canonicalScope, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $shape = $this->parseDescriptor($canonicalScope);

        // Mirrors FieldController::allowAdd(): a Field can only be created when the
        // user holds core.create on the context component. Re-evaluated for every
        // provisional operation, so a revoked permission fails closed.
        if (!$this->typeSupported($shape['type']) || !($this->contextResolver)($shape['context'])) {
            throw new AutosaveException('forbidden', 'The Custom Field creation descriptor is no longer supported.');
        }

        if (!$user->authorise('core.create', $shape['component'])) {
            throw new AutosaveException('forbidden', 'A Custom Field cannot be created in this context.');
        }
    }

    public function verifyFinalTargetStaticScope(string $finalTargetId, string $canonicalScope): void
    {
        $shape  = $this->parseDescriptor($canonicalScope);
        $record = $this->load($this->canonicalizeTargetId($finalTargetId));

        if (
            $record === null
            || $record->context !== $shape['context']
            || $record->type !== $shape['type']
        ) {
            throw new AutosaveException('scope_mismatch', 'The saved Custom Field does not match its creation descriptor.');
        }
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        throw $this->invalidPayload();
    }

    public function normalizeCreatePayload(string $descriptor, mixed $payload, int $schemaVersion): array
    {
        $shape  = $this->parseDescriptor($descriptor);
        $static = $this->assertStaticPayload($payload, $schemaVersion);

        // Reconstruct the descriptor-bound schema from the type and compare against
        // the schema fingerprint anchored when the P1 was created. Plugin/schema
        // evolution therefore fails closed deterministically instead of silently
        // coercing the old draft into the new schema.
        $schema = ($this->schemaResolver)($shape['type']);

        if (!hash_equals($schema->fingerprint(), $shape['fingerprint'])) {
            throw new AutosaveException('descriptor_stale', 'The Custom Field creation descriptor schema is stale.');
        }

        if (!hash_equals($schema->fingerprint(), $static['schemaFingerprint'])) {
            throw new AutosaveException('invalid_payload', 'The Custom Field draft schema is stale.');
        }

        try {
            $dynamic = $schema->normalizePayload(['fieldparams' => $static['fieldparams']])['fieldparams'];
        } catch (\InvalidArgumentException) {
            throw $this->invalidPayload();
        }

        return $this->normalizedPayload($static, $schema->fingerprint(), $dynamic);
    }

    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $static = $this->assertStaticPayload($payload, $schemaVersion);

        $schema = $this->getDynamicSchema($this->canonicalizeTargetId($targetId));

        if (!hash_equals($schema->fingerprint(), $static['schemaFingerprint'])) {
            throw new AutosaveException('invalid_payload', 'The Custom Field draft schema is stale.');
        }

        try {
            $dynamic = $schema->normalizePayload(['fieldparams' => $static['fieldparams']])['fieldparams'];
        } catch (\InvalidArgumentException) {
            throw $this->invalidPayload();
        }

        return $this->normalizedPayload($static, $schema->fingerprint(), $dynamic);
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

    public function fullyRepresentsDynamicForm(string $type, Form $form): bool
    {
        return $this->schemaFactory->fullyRepresentsForm($form, $type);
    }

    public function getDynamicSchemaForType(string $type): AutosaveDynamicSchema
    {
        return ($this->schemaResolver)($type);
    }

    /**
     * Validate the bounded static shape shared by every Custom Field draft payload.
     *
     * @return array{title: string, name: string, label: string, description: string, default_value: string, note: string, required: bool, only_use_in_subform: bool, schemaFingerprint: string, fieldparams: mixed}
     */
    private function assertStaticPayload(mixed $payload, int $schemaVersion): array
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

        return $payload;
    }

    /**
     * @return array{title: string, name: string, label: string, description: string, default_value: string, note: string, required: bool, only_use_in_subform: bool, schemaFingerprint: string, fieldparams: mixed}
     */
    private function normalizedPayload(array $static, string $fingerprint, array $dynamic): array
    {
        return array_merge(array_intersect_key($static, self::STRING_LIMITS), [
            'required'            => $static['required'],
            'only_use_in_subform' => $static['only_use_in_subform'],
            'schemaFingerprint'   => $fingerprint,
            'fieldparams'         => $dynamic,
        ]);
    }

    /**
     * @return array{prefix: string, context: string, component: string, type: string, fingerprint: string}
     */
    private function parseDescriptor(string $descriptor): array
    {
        $parts = explode(':', $descriptor, 4);

        if (\count($parts) !== 4 || $parts[0] !== self::DESCRIPTOR_PREFIX) {
            throw new AutosaveException('invalid_scope', 'The Custom Field creation descriptor is invalid.');
        }

        [$prefix, $context, $type, $fingerprint] = $parts;

        if (!$this->validContext($context) || !$this->validTypeName($type) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new AutosaveException('invalid_scope', 'The Custom Field creation descriptor is invalid.');
        }

        return ['prefix' => $prefix, 'context' => $context, 'component' => explode('.', $context, 2)[0], 'type' => $type, 'fingerprint' => $fingerprint];
    }

    private function validContext(string $context): bool
    {
        if (preg_match('/^com_[a-z][a-z0-9_]{0,48}\.[A-Za-z0-9_.-]{1,64}$/D', $context) !== 1) {
            return false;
        }

        // The bare component is the "nonsense situation" the native views refuse to
        // render and the field admin never offers as a context.
        return explode('.', $context, 2)[0] !== 'com_fields';
    }

    private function validTypeName(string $type): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $type) === 1;
    }

    private function typeSupported(string $type): bool
    {
        return ($this->typeResolver)($type);
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

    private function invalidScope(): AutosaveException
    {
        return new AutosaveException('invalid_scope', 'The Custom Field creation descriptor is invalid.');
    }
}
