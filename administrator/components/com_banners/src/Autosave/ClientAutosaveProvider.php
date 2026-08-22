<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_banners
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Banners\Administrator\Autosave;

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
 * Read-only Autosave provider for existing Banner clients.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ClientAutosaveProvider implements AutosaveProviderInterface
{
    private const CONTEXT                = 'com_banners.client';
    private const MAXIMUM_ID             = '4294967295';
    private const PAYLOAD_SCHEMA_VERSION = 1;
    private const BASE_REVISION_DOMAIN   = 'autosave:com_banners.client:base-revision:v1';
    private const STRING_LIMITS          = [
        'name'           => 255,
        'contact'        => 255,
        'email'          => 255,
        'extrainfo'      => 65535,
        'metakey'        => 65535,
        'metakey_prefix' => 400,
        'version_note'   => 255,
    ];
    private const INTEGER_VALUES = [
        'purchase_type'     => [-1, 0, 1, 2, 3, 4, 5],
        'track_impressions' => [-1, 0, 1],
        'track_clicks'      => [-1, 0, 1],
        'own_prefix'        => [0, 1],
    ];

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
            throw new AutosaveException('invalid_target', 'The Banner client target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return $this->loadClient($this->canonicalizeTargetId($targetId)) !== null;
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

        $client = $this->loadClient($targetId);

        if ($client === null) {
            throw new AutosaveException('target_not_found', 'The Banner client was not found.');
        }

        if (!$user->authorise('core.edit', 'com_banners')) {
            throw new AutosaveException('forbidden', 'The Banner client cannot be edited by this user.');
        }

        if ((int) $client->checked_out !== 0 && (int) $client->checked_out !== (int) $user->id) {
            throw new AutosaveException('forbidden', 'The Banner client is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $client = $this->loadClient($this->canonicalizeTargetId($targetId));

        if ($client === null) {
            throw new AutosaveException('target_not_found', 'The Banner client was not found.');
        }

        $canonical = json_encode(
            [
                'id'                => (int) $client->id,
                'name'              => $client->name,
                'contact'           => $client->contact,
                'email'             => $client->email,
                'extrainfo'         => $client->extrainfo,
                'state'             => (int) $client->state,
                'metakey'           => $client->metakey,
                'own_prefix'        => (int) $client->own_prefix,
                'metakey_prefix'    => $client->metakey_prefix,
                'purchase_type'     => (int) $client->purchase_type,
                'track_clicks'      => (int) $client->track_clicks,
                'track_impressions' => (int) $client->track_impressions,
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

        $required = array_fill_keys([...array_keys(self::STRING_LIMITS), ...array_keys(self::INTEGER_VALUES)], true);

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

        foreach (self::INTEGER_VALUES as $key => $values) {
            if (!\is_int($payload[$key]) || !\in_array($payload[$key], $values, true)) {
                throw $this->invalidPayload();
            }
        }

        $normalized = [];

        foreach (array_keys(self::STRING_LIMITS) as $key) {
            $normalized[$key] = $payload[$key];
        }

        foreach (array_keys(self::INTEGER_VALUES) as $key) {
            $normalized[$key] = $payload[$key];
        }

        return $normalized;
    }

    private function loadClient(string $targetId): ?object
    {
        $id = (int) $targetId;
        $fields = ['id', 'name', 'contact', 'email', 'extrainfo', 'state', 'checked_out', 'metakey',
            'own_prefix', 'metakey_prefix', 'purchase_type', 'track_clicks', 'track_impressions'];
        $query = $this->db->createQuery()
            ->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))
            ->from($this->db->quoteName('#__banner_clients'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Banner client draft payload is invalid.');
    }
}
