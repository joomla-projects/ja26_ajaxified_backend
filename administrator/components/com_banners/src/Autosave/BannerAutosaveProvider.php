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
 * Component-owned Autosave contract for existing Banner records.
 *
 * @since  __DEPLOY_VERSION__
 */
final class BannerAutosaveProvider implements AutosaveProviderInterface
{
    private const CONTEXT                = 'com_banners.banner';
    private const PAYLOAD_SCHEMA_VERSION = 1;
    private const MAXIMUM_ID             = '2147483647';
    private const BASE_REVISION_DOMAIN   = 'autosave:com_banners.banner:base-revision:v1';

    private const STRING_LIMITS = [
        'name'             => 255,
        'alias'            => 255,
        'description'      => 65535,
        'custombannercode' => 2048,
        'clickurl'         => 2048,
        'version_note'     => 255,
        'publish_up'       => 255,
        'publish_up_alt'   => 255,
        'publish_down'     => 255,
        'publish_down_alt' => 255,
        'imageurl'         => 2048,
        'width'            => 10,
        'height'           => 10,
        'alt'              => 255,
        'metakey'          => 65535,
        'metakey_prefix'   => 255,
    ];

    private const INTEGER_VALUES = [
        'type'       => [0, 1],
        'own_prefix' => [0, 1],
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
            throw new AutosaveException('invalid_target', 'The Banner target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        $banner = $this->loadBanner($this->canonicalizeTargetId($targetId));

        return $this->hasCanonicalCategory($banner);
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $banner = $this->loadBanner($this->canonicalizeTargetId($targetId));

        if (!$this->hasCanonicalCategory($banner)) {
            throw new AutosaveException('target_not_found', 'The Banner was not found.');
        }

        if (!$user->authorise('core.edit', 'com_banners.category.' . (int) $banner->catid)) {
            throw new AutosaveException('forbidden', 'The Banner cannot be edited by this user.');
        }

        if ((int) ($banner->checked_out ?? 0) !== 0 && (int) $banner->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Banner is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $banner = $this->loadBanner($this->canonicalizeTargetId($targetId));

        if (!$this->hasCanonicalCategory($banner)) {
            throw new AutosaveException('target_not_found', 'The Banner was not found.');
        }

        unset($banner->checked_out);
        $canonical = json_encode(
            $banner,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return self::BASE_REVISION_DOMAIN . ':'
            . hash('sha256', self::BASE_REVISION_DOMAIN . "\0" . $canonical);
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

        foreach (['width', 'height'] as $key) {
            if ($payload[$key] !== '' && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $payload[$key]) !== 1) {
                throw $this->invalidPayload();
            }

            if ($payload[$key] !== '' && (int) $payload[$key] > 2147483647) {
                throw $this->invalidPayload();
            }
        }

        if (!$this->isStableMediaReference($payload['imageurl'])) {
            throw $this->invalidPayload();
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

    private function isStableMediaReference(string $reference): bool
    {
        if ($reference === '') {
            return true;
        }

        if (str_contains($reference, "\0") || str_contains($reference, '\\') || str_starts_with($reference, '//')) {
            return false;
        }

        if (preg_match('/^(?:blob|data|file):/i', $reference) === 1) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) !== 1) {
            return !str_starts_with($reference, '/');
        }

        $parts = parse_url($reference);

        return \is_array($parts)
            && \in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && isset($parts['host']);
    }

    private function loadBanner(string $targetId): ?object
    {
        $fields = [
            'id', 'cid', 'type', 'name', 'alias', 'imptotal', 'impmade', 'clicks', 'clickurl', 'state',
            'catid', 'description', 'custombannercode', 'sticky', 'ordering', 'metakey', 'params',
            'own_prefix', 'metakey_prefix', 'purchase_type', 'track_clicks', 'track_impressions',
            'publish_up', 'publish_down', 'reset', 'created', 'language', 'created_by', 'created_by_alias',
            'modified', 'modified_by', 'version', 'checked_out',
        ];
        $query = $this->db->createQuery()
            ->select(array_map(fn ($field) => 'b.' . $this->db->quoteName($field), $fields))
            ->select([
                'c.' . $this->db->quoteName('id') . ' AS ' . $this->db->quoteName('category_id'),
                'c.' . $this->db->quoteName('extension') . ' AS ' . $this->db->quoteName('category_extension'),
            ])
            ->from($this->db->quoteName('#__banners', 'b'))
            ->leftJoin(
                $this->db->quoteName('#__categories', 'c')
                . ' ON c.' . $this->db->quoteName('id') . ' = b.' . $this->db->quoteName('catid')
            )
            ->where('b.' . $this->db->quoteName('id') . ' = :id')
            ->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function hasCanonicalCategory(?object $banner): bool
    {
        return $banner !== null
            && (int) $banner->category_id > 0
            && $banner->category_extension === 'com_banners';
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Banner draft payload is invalid.');
    }
}
