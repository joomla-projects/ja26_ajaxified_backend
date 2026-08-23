<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Menus\Administrator\Autosave;

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

/**
 * Read-only Autosave provider for existing Menu metadata records.
 *
 * @since  __DEPLOY_VERSION__
 */
final class MenuAutosaveProvider implements AutosaveProviderInterface
{
    private const CONTEXT                = 'com_menus.menu';
    private const MAXIMUM_ID             = '4294967295';
    private const PAYLOAD_SCHEMA_VERSION = 1;
    private const BASE_REVISION_DOMAIN   = 'autosave:com_menus.menu:base-revision:v1';
    private const STRING_LIMITS          = ['title' => 48, 'description' => 255];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getContext(): string
    {
        return self::CONTEXT;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (
            preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1
            || (\strlen($targetId) === 10 && strcmp($targetId, self::MAXIMUM_ID) > 0)
        ) {
            throw new AutosaveException('invalid_target', 'The Menu target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return $this->loadMenu($this->canonicalizeTargetId($targetId)) !== null;
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $targetId = $this->canonicalizeTargetId($targetId);

        match ($operation) {
            AutosaveOperation::Initialize,
            AutosaveOperation::Preserve,
            AutosaveOperation::Detect,
            AutosaveOperation::Read,
            AutosaveOperation::PrepareCanonicalAction,
            AutosaveOperation::QueryCanonicalAction => null,
        };

        $menu = $this->loadMenu($targetId);

        if ($menu === null) {
            throw new AutosaveException('target_not_found', 'The Menu was not found.');
        }

        if (!$user->authorise('core.edit', 'com_menus.menu.' . (int) $menu->id)) {
            throw new AutosaveException('forbidden', 'The Menu cannot be edited by this user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $menu = $this->loadMenu($this->canonicalizeTargetId($targetId));

        if ($menu === null) {
            throw new AutosaveException('target_not_found', 'The Menu was not found.');
        }

        $canonical = json_encode(
            [
                'id'          => (int) $menu->id,
                'menutype'    => $menu->menutype,
                'title'       => $menu->title,
                'description' => $menu->description,
                'client_id'   => (int) $menu->client_id,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return self::BASE_REVISION_DOMAIN . ':' . hash('sha256', self::BASE_REVISION_DOMAIN . "\0" . $canonical);
    }

    public function getPayloadSchemaVersion(): int
    {
        return self::PAYLOAD_SCHEMA_VERSION;
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        if ($schemaVersion !== self::PAYLOAD_SCHEMA_VERSION) {
            throw new AutosaveException('unsupported_schema_version', 'The payload schema version is not supported.');
        }

        $required = array_fill_keys(array_keys(self::STRING_LIMITS), true);

        if (
            !\is_array($payload) || array_is_list($payload) || \count($payload) !== \count($required)
            || array_diff_key($payload, $required) !== [] || array_diff_key($required, $payload) !== []
        ) {
            throw $this->invalidPayload();
        }

        foreach (self::STRING_LIMITS as $key => $limit) {
            if (
                !\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1
                || StringHelper::strlen($payload[$key]) > $limit
            ) {
                throw $this->invalidPayload();
            }
        }

        return ['title' => $payload['title'], 'description' => $payload['description']];
    }

    private function loadMenu(string $targetId): ?object
    {
        $id     = (int) $targetId;
        $fields = ['id', 'menutype', 'title', 'description', 'client_id'];
        $query  = $this->db->createQuery()
            ->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))
            ->from($this->db->quoteName('#__menu_types'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Menu draft payload is invalid.');
    }
}
