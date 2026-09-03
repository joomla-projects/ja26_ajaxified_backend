<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_newsfeeds
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Newsfeeds\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
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

final class NewsfeedAutosaveProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    private const STRING_LIMITS = [
        'name'     => 100, 'description' => 65535, 'link' => 2048, 'version_note' => 255,
        'metadesc' => 300, 'metakey' => 65535,
    ];
    private const KEYS = ['name', 'description', 'link', 'version_note', 'numarticles', 'cache_time', 'metadesc', 'metakey', 'catid'];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getContext(): string
    {
        return 'com_newsfeeds.newsfeed';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 2;
    }

    public function getCreateContractVersion(): string
    {
        return 'newsfeed-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        if ($normalizedPayload === null) {
            if (!$user->authorise('core.create', 'com_newsfeeds') && \count($user->getAuthorisedCategories('com_newsfeeds', 'core.create')) === 0) {
                throw new AutosaveException('forbidden', 'A Newsfeed cannot be created by this user.');
            }
            return;
        }
        $catid = $normalizedPayload['catid'] ?? null;
        if (!\is_int($catid) || $catid <= 0 || !$this->categoryExists($catid)) {
            throw $this->invalidPayload();
        }
        if (!$user->authorise('core.create', 'com_newsfeeds.category.' . $catid)) {
            throw new AutosaveException('forbidden', 'A Newsfeed cannot be created in this category.');
        }
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)) {
            throw new AutosaveException('invalid_target', 'The Newsfeed target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        return $record !== null && (int) $record->category_id > 0 && $record->category_extension === 'com_newsfeeds';
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null || (int) $record->category_id <= 0 || $record->category_extension !== 'com_newsfeeds') {
            throw new AutosaveException('target_not_found', 'The Newsfeed was not found.');
        }

        $asset = 'com_newsfeeds.category.' . (int) $record->catid;

        if (!$user->authorise('core.edit', $asset) && !($user->authorise('core.edit.own', $asset) && (int) $record->created_by === (int) $user->id)) {
            throw new AutosaveException('forbidden', 'The Newsfeed cannot be edited by this user.');
        }

        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Newsfeed is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null || (int) $record->category_id <= 0 || $record->category_extension !== 'com_newsfeeds') {
            throw new AutosaveException('target_not_found', 'The Newsfeed was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_newsfeeds.newsfeed:base-revision:v1';

        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required = array_fill_keys(self::KEYS, true);

        if ($schemaVersion !== 2 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $required) || array_diff_key($required, $payload)) {
            throw $this->invalidPayload();
        }

        foreach (self::STRING_LIMITS as $key => $limit) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1 || StringHelper::strlen($payload[$key]) > $limit) {
                throw $this->invalidPayload();
            }
        }

        foreach (['numarticles', 'cache_time', 'catid'] as $key) {
            if (!\is_int($payload[$key]) || $payload[$key] < ($key === 'catid' ? 1 : 0) || $payload[$key] > 4294967295) {
                throw $this->invalidPayload();
            }
        }

        return array_replace(array_fill_keys(self::KEYS, null), $payload);
    }

    private function categoryExists(int $catid): bool
    {
        $extension = 'com_newsfeeds';
        $query = $this->db->createQuery()->select('COUNT(*)')->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('id') . ' = :catid')->where($this->db->quoteName('extension') . ' = :extension')
            ->bind(':catid', $catid, ParameterType::INTEGER)->bind(':extension', $extension);
        return (int) $this->db->setQuery($query)->loadResult() === 1;
    }

    private function load(string $targetId): ?object
    {
        $fields = ['id', 'catid', 'name', 'alias', 'link', 'published', 'numarticles', 'cache_time', 'ordering', 'rtl', 'access', 'language', 'params', 'created', 'created_by', 'modified', 'modified_by', 'metakey', 'metadesc', 'metadata', 'publish_up', 'publish_down', 'description', 'version', 'hits', 'images', 'checked_out'];
        $query  = $this->db->createQuery()
            ->select(array_map(fn ($field) => 'n.' . $this->db->quoteName($field), $fields))
            ->select(['c.' . $this->db->quoteName('id') . ' AS ' . $this->db->quoteName('category_id'), 'c.' . $this->db->quoteName('extension') . ' AS ' . $this->db->quoteName('category_extension')])
            ->from($this->db->quoteName('#__newsfeeds', 'n'))
            ->leftJoin($this->db->quoteName('#__categories', 'c') . ' ON c.' . $this->db->quoteName('id') . ' = n.' . $this->db->quoteName('catid'))
            ->where('n.' . $this->db->quoteName('id') . ' = :id')
            ->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Newsfeed draft payload is invalid.');
    }
}
