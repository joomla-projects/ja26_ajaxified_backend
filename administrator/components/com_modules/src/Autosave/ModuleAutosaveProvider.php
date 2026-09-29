<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_modules
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Modules\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
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

final class ModuleAutosaveProvider implements
    TargetAwareAutosaveProviderInterface,
    AutosaveCreateProviderInterface,
    AutosaveDynamicCreateDescriptorProviderInterface
{
    private const DESCRIPTOR_PREFIX   = 'md1';
    private const CREATE_CONTRACT     = 'module-create-v1';
    private const DESCRIPTOR_CONTRACT = 'module-descriptor-v1';

    /** @var callable(object): AutosaveDynamicSchema */
    private $schemaResolver;

    /** @var callable(string, int): bool */
    private $extensionResolver;

    public function __construct(
        private readonly DatabaseInterface $db,
        callable $schemaResolver,
        ?callable $extensionResolver = null
    ) {
        $this->schemaResolver = $schemaResolver;
        // Mirrors the native module-type chooser: a module type is only selectable
        // when its extension row exists, belongs to the target client and is enabled.
        // Never trusts a bare module element string.
        $this->extensionResolver = $extensionResolver ?? function (string $module, int $client): bool {
            $query = $this->db->createQuery()
                ->select($this->db->quoteName('extension_id'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('element') . ' = :element')
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('module'))
                ->where($this->db->quoteName('client_id') . ' = :client')
                ->where($this->db->quoteName('enabled') . ' = 1')
                ->bind(':element', $module)
                ->bind(':client', $client, ParameterType::INTEGER);
            $this->db->setQuery($query);

            return $this->db->loadResult() !== null;
        };
    }

    public function getContext(): string
    {
        return 'com_modules.module';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function getCreateContractVersion(): string
    {
        return self::CREATE_CONTRACT;
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // A Module can only be authorized against its anchored module-type creation
        // descriptor. The lifecycle routes every provisional operation of this
        // provider through authorizeStaticCreateScope(); reaching this method means
        // no anchored descriptor is available, so it fails closed instead of guessing
        // an extension.
        throw new AutosaveException('scope_required', 'A Module requires an anchored creation descriptor.');
    }

    public function getStaticScopeContractVersion(): string
    {
        return self::DESCRIPTOR_CONTRACT;
    }

    /**
     * Build the raw server-owned creation candidate for one new Module editor.
     *
     * @return  string|null  null when the editor is not a genuine module-type create form.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function createScopeCandidate(int $client, string $module): ?string
    {
        if (!$this->validClient((string) $client) || !$this->validModuleElement($module)) {
            return null;
        }

        return $client . '|' . $module;
    }

    public function canonicalizeStaticCreateScope(mixed $candidateScope): string
    {
        if (!\is_string($candidateScope)) {
            throw $this->invalidScope();
        }

        if (str_contains($candidateScope, '|')) {
            $raw = explode('|', $candidateScope, 2);

            if (\count($raw) !== 2) {
                throw $this->invalidScope();
            }

            [$client, $module] = $raw;
            $fingerprint       = null;
        } else {
            $parts = explode(':', $candidateScope, 4);

            if (\count($parts) !== 4 || $parts[0] !== self::DESCRIPTOR_PREFIX) {
                throw $this->invalidScope();
            }

            [$client, $module, $fingerprint] = [$parts[1], $parts[2], $parts[3]];

            if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw $this->invalidScope();
            }
        }

        if (!$this->validClient($client) || !$this->validModuleElement($module) || !$this->supported($module, (int) $client)) {
            throw $this->invalidScope();
        }

        // The fingerprint must equal the schema the server can build right now from
        // the extension-generated module form. A candidate carrying a stale or
        // fabricated fingerprint is refused, so a P1 can never anchor a schema the
        // server does not own.
        $schema = ($this->schemaResolver)($this->pseudoRecord($module, (int) $client));

        if ($fingerprint !== null && !hash_equals($schema->fingerprint(), $fingerprint)) {
            throw $this->invalidScope();
        }

        return self::DESCRIPTOR_PREFIX . ':' . (int) $client . ':' . $module . ':' . $schema->fingerprint();
    }

    public function authorizeStaticCreateScope(User $user, string $canonicalScope, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        $shape = $this->parseDescriptor($canonicalScope);

        // The module type must still describe a real, enabled extension of the
        // anchored client. Re-evaluated for every provisional operation so a missing
        // or disabled extension fails closed instead of being silently trusted.
        if (!$this->supported($shape['module'], $shape['client'])) {
            throw new AutosaveException('forbidden', 'The Module creation descriptor is no longer supported.');
        }

        // Mirrors the native module create check (FormController::allowAdd on the
        // component root); client/extension availability is anchored by the
        // descriptor. Permission revocation fails closed.
        if (!$user->authorise('core.create', 'com_modules')) {
            throw new AutosaveException('forbidden', 'A Module cannot be created by this user.');
        }
    }

    public function verifyFinalTargetStaticScope(string $finalTargetId, string $canonicalScope): void
    {
        $shape  = $this->parseDescriptor($canonicalScope);
        $record = $this->load($this->canonicalizeTargetId($finalTargetId));

        if (
            $record === null
            || (string) $record->module !== $shape['module']
            || (int) $record->client_id !== $shape['client']
        ) {
            throw new AutosaveException('scope_mismatch', 'The saved Module does not match its creation descriptor.');
        }
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        throw $this->invalid();
    }

    public function normalizeCreatePayload(string $descriptor, mixed $payload, int $schemaVersion): array
    {
        $shape   = $this->parseDescriptor($descriptor);
        $current = ($this->schemaResolver)($this->pseudoRecord($shape['module'], $shape['client']));

        // The anchored descriptor must still describe the schema the server builds
        // right now. A module XML/form change therefore fails closed deterministically
        // instead of coercing the old draft into the new schema.
        if (!hash_equals($current->fingerprint(), $shape['fingerprint'])) {
            throw new AutosaveException('descriptor_stale', 'The Module creation descriptor schema is stale.');
        }

        return $this->assertPayloadWithSchema($current, $payload, $schemaVersion);
    }

    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $schema = $this->getDynamicSchema($targetId);

        return $this->assertPayloadWithSchema($schema, $payload, $schemaVersion);
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (
            preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1
            || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)
        ) {
            throw new AutosaveException('invalid_target', 'The Module target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Module was not found.');
        }

        if (!$user->authorise('core.edit', 'com_modules.module.' . (int) $record->id)) {
            throw new AutosaveException('forbidden', 'The Module cannot be edited.');
        }

        if ((int) $record->checked_out !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Module is checked out.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Module was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_modules.module:base-revision:v1';

        return $domain . ':' . hash(
            'sha256',
            $domain . "\0" . json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    public function getDynamicSchema(string $targetId): AutosaveDynamicSchema
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Module was not found.');
        }

        return ($this->schemaResolver)($record);
    }

    public function getDynamicSchemaForForm(string $targetId, Form $form): AutosaveDynamicSchema
    {
        if (!$this->targetExists($targetId)) {
            throw new AutosaveException('target_not_found', 'The Module was not found.');
        }

        return (new ModuleAutosaveSchemaFactory())->fromForm($form);
    }

    /**
     * Reconstruct the descriptor-bound extension-generated schema for one canonical scope.
     *
     * @param   string  $canonicalScope  The anchored canonical creation descriptor.
     *
     * @return  AutosaveDynamicSchema
     *
     * @throws  AutosaveException  invalid_scope when the descriptor cannot be parsed.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getDynamicSchemaForScope(string $canonicalScope): AutosaveDynamicSchema
    {
        $shape = $this->parseDescriptor($canonicalScope);

        return ($this->schemaResolver)($this->pseudoRecord($shape['module'], $shape['client']));
    }

    /**
     * Validate the bounded static Module shape against one schema.
     *
     * @return  array{title: string, note: string, version_note: string, showtitle: string, position: string, content: string, schemaFingerprint: string, params: mixed}
     */
    private function assertPayloadWithSchema(AutosaveDynamicSchema $schema, mixed $payload, int $schemaVersion): array
    {
        $keys = array_fill_keys(
            ['title', 'note', 'version_note', 'showtitle', 'position', 'content', 'schemaFingerprint', 'params'],
            true
        );

        if (
            $schemaVersion !== 1
            || !\is_array($payload)
            || array_is_list($payload)
            || array_diff_key($payload, $keys)
            || array_diff_key($keys, $payload)
        ) {
            throw $this->invalid();
        }

        foreach (
            ['title' => 100, 'note' => 255, 'version_note' => 255, 'position' => 50, 'content' => 65535] as $name => $limit
        ) {
            if (
                !\is_string($payload[$name])
                || preg_match('//u', $payload[$name]) !== 1
                || StringHelper::strlen($payload[$name]) > $limit
            ) {
                throw $this->invalid();
            }
        }

        if (
            !\is_string($payload['showtitle'])
            || !\in_array($payload['showtitle'], ['0', '1'], true)
            || !\is_string($payload['schemaFingerprint'])
        ) {
            throw $this->invalid();
        }

        if (!hash_equals($schema->fingerprint(), $payload['schemaFingerprint'])) {
            throw $this->invalid();
        }

        try {
            $params = $schema->normalizePayload(['params' => $payload['params']])['params'] ?? [];
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }

        return array_merge(
            array_intersect_key($payload, $keys),
            ['schemaFingerprint' => $schema->fingerprint(), 'params' => $params]
        );
    }

    /**
     * @return array{client: int, module: string, fingerprint: string}
     */
    private function parseDescriptor(string $descriptor): array
    {
        $parts = explode(':', $descriptor, 4);

        if (\count($parts) !== 4 || $parts[0] !== self::DESCRIPTOR_PREFIX) {
            throw new AutosaveException('invalid_scope', 'The Module creation descriptor is invalid.');
        }

        [$prefix, $client, $module, $fingerprint] = $parts;

        if (
            !$this->validClient($client)
            || !$this->validModuleElement($module)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || !$this->supported($module, (int) $client)
        ) {
            throw new AutosaveException('invalid_scope', 'The Module creation descriptor is invalid.');
        }

        return ['client' => (int) $client, 'module' => $module, 'fingerprint' => $fingerprint];
    }

    private function supported(string $module, int $client): bool
    {
        return ($this->extensionResolver)($module, $client);
    }

    private function pseudoRecord(string $module, int $client): object
    {
        return (object) [
            'id'        => 0,
            'module'    => $module,
            'client_id' => $client,
            'params'    => '{}',
        ];
    }

    private function validClient(string $client): bool
    {
        return preg_match('/^[01]$/D', $client) === 1;
    }

    private function validModuleElement(string $module): bool
    {
        return preg_match('/^mod_[a-z][a-z0-9_]{0,49}$/D', $module) === 1;
    }

    private function load(string $id): ?object
    {
        $query = $this->db->createQuery()
            ->select([
                'id', 'asset_id', 'title', 'note', 'content', 'position', 'ordering', 'checked_out',
                'published', 'module', 'access', 'showtitle', 'params', 'client_id', 'language',
                'publish_up', 'publish_down',
            ])
            ->from($this->db->quoteName('#__modules'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalid(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Module draft payload is invalid.');
    }

    private function invalidScope(): AutosaveException
    {
        return new AutosaveException('invalid_scope', 'The Module creation descriptor is invalid.');
    }
}
