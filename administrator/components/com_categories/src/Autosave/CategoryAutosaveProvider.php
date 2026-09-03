<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_categories
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Categories\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\Autosave\AutosaveStaticScopeProviderInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class CategoryAutosaveProvider implements
    AutosaveProviderInterface,
    AutosaveCreateProviderInterface,
    AutosaveStaticScopeProviderInterface
{
    private const LIMITS = [
        'title'        => 255,
        'note'         => 255,
        'description'  => 65535,
        'version_note' => 255,
        'metadesc'     => 300,
        'metakey'      => 1024,
    ];
    private const MAXIMUM_ID = '2147483647';
    private const ROOT_ID    = 1;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getContext(): string
    {
        return 'com_categories.category';
    }

    public function getCreateContractVersion(): string
    {
        return 'category-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // A Category can only be authorized against its anchored owning extension. The
        // lifecycle routes every provisional operation of this provider through
        // authorizeStaticCreateScope(); reaching this method means no anchored scope is
        // available, so it fails closed instead of guessing an extension.
        throw new AutosaveException('scope_required', 'A Category requires an anchored owning extension.');
    }

    public function getStaticScopeContractVersion(): string
    {
        return 'category-scope-v1';
    }

    public function canonicalizeStaticCreateScope(mixed $candidateScope): string
    {
        if (!\is_string($candidateScope)) {
            throw $this->invalidScope();
        }

        // The owning extension is a plain component name. The bare com_categories default
        // is the "nonsense situation" the native views refuse to render.
        if (preg_match('/^com_[a-z][a-z0-9_]{0,48}$/D', $candidateScope) !== 1 || $candidateScope === 'com_categories') {
            throw $this->invalidScope();
        }

        return $candidateScope;
    }

    public function authorizeStaticCreateScope(User $user, string $canonicalScope, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // Mirrors CategoryController::allowAdd(): creation is allowed on the extension
        // root asset or when the user may create in any category of that extension. The
        // extension is the anchored scope, never the browser request. Every provisional
        // operation re-runs this check so a revoked permission fails closed.
        if (
            !$user->authorise('core.create', $canonicalScope)
            && \count($user->getAuthorisedCategories($canonicalScope, 'core.create')) === 0
        ) {
            throw new AutosaveException('forbidden', 'A Category cannot be created in this extension.');
        }

        // Authored parent relations must stay inside the anchored tree: the global ROOT
        // row is a legal parent for every extension, any other parent must be an existing
        // Category of the anchored extension. This runs after normalization and before any
        // storage mutation on every generation, so a parent moved to another extension or
        // deleted mid-lineage fails closed.
        if ($normalizedPayload === null) {
            return;
        }

        if (!\is_array($normalizedPayload) || !\array_key_exists('parent_id', $normalizedPayload)) {
            throw $this->invalidPayload();
        }

        $parentId = (int) $normalizedPayload['parent_id'];

        if ($parentId === self::ROOT_ID) {
            return;
        }

        $parent = $this->load((string) $parentId);

        if ($parent === null || $parent->extension !== $canonicalScope) {
            throw $this->invalidPayload();
        }
    }

    public function verifyFinalTargetStaticScope(string $finalTargetId, string $canonicalScope): void
    {
        $record = $this->load($this->canonicalizeTargetId($finalTargetId));

        if ($record === null || $record->extension !== $canonicalScope) {
            throw new AutosaveException('scope_mismatch', 'The saved Category does not belong to the anchored extension.');
        }
    }

    public function getPayloadSchemaVersion(): int
    {
        return 2;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (
            preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1
            || (\strlen($targetId) === 10 && strcmp($targetId, self::MAXIMUM_ID) > 0)
        ) {
            throw new AutosaveException('invalid_target', 'The Category target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Category was not found.');
        }

        $asset = $record->extension . '.category.' . (int) $record->id;

        if (
            !$user->authorise('core.edit', $asset)
            && !($user->authorise('core.edit.own', $asset) && (int) $record->created_user_id === (int) $user->id)
        ) {
            throw new AutosaveException('forbidden', 'The Category cannot be edited by this user.');
        }

        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Category is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Category was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_categories.category:base-revision:v1';

        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required              = array_fill_keys(array_keys(self::LIMITS), true);
        $required['parent_id'] = true;

        if (
            $schemaVersion !== 2 || !\is_array($payload) || array_is_list($payload)
            || array_diff_key($payload, $required) || array_diff_key($required, $payload)
        ) {
            throw $this->invalidPayload();
        }

        foreach (self::LIMITS as $key => $limit) {
            if (
                !\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1
                || StringHelper::strlen($payload[$key]) > $limit
            ) {
                throw $this->invalidPayload();
            }
        }

        // parent_id is a bounded integer: the global ROOT row (1) or an existing Category.
        if (
            !\is_int($payload['parent_id'])
            || $payload['parent_id'] < self::ROOT_ID
            || $payload['parent_id'] > (int) self::MAXIMUM_ID
        ) {
            throw $this->invalidPayload();
        }

        $normalized = [];

        foreach (array_keys(self::LIMITS) as $key) {
            $normalized[$key] = $payload[$key];
        }

        $normalized['parent_id'] = $payload['parent_id'];

        return $normalized;
    }

    private function load(string $targetId): ?object
    {
        $fields = [
            'id', 'asset_id', 'parent_id', 'lft', 'rgt', 'level', 'path', 'extension', 'title', 'alias', 'note',
            'description', 'published', 'access', 'params', 'metadesc', 'metakey', 'metadata', 'created_user_id',
            'created_time', 'modified_user_id', 'modified_time', 'hits', 'language', 'version', 'checked_out',
        ];
        $query = $this->db->createQuery()
            ->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Category draft payload is invalid.');
    }

    private function invalidScope(): AutosaveException
    {
        return new AutosaveException('invalid_scope', 'The Category creation scope is invalid.');
    }
}
