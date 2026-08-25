<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_languages
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Languages\Administrator\Autosave;

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

final class LanguageAutosaveProvider implements AutosaveProviderInterface
{
    private const LIMITS = ['title' => 50, 'title_native' => 50, 'description' => 512, 'metadesc' => 300, 'sitename' => 1024];
    public function __construct(private readonly DatabaseInterface $db)
    {
    }
    public function getContext(): string
    {
        return 'com_languages.language';
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }
    public function canonicalizeTargetId(string $id): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || (\strlen($id) === 10 && strcmp($id, '4294967295') > 0)) {
            throw new AutosaveException('invalid_target', 'The Language target is invalid.');
        }
        return $id;
    }
    public function targetExists(string $id): bool
    {
        return $this->load($this->canonicalizeTargetId($id)) !== null;
    }
    public function authorize(User $user, string $id, AutosaveOperation $operation): void
    {
        if ($this->load($this->canonicalizeTargetId($id)) === null) {
            throw new AutosaveException('target_not_found', 'The Language was not found.');
        }
        if (!$user->authorise('core.edit', 'com_languages')) {
            throw new AutosaveException('forbidden', 'The Language cannot be edited by this user.');
        }
    }
    public function getBaseRevision(string $id): string
    {
        $record = $this->load($this->canonicalizeTargetId($id));
        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Language was not found.');
        }
        $domain = 'autosave:com_languages.language:base-revision:v1';
        $json   = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $domain . ':' . hash('sha256', $domain . "\0" . $json);
    }
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, self::LIMITS) || array_diff_key(self::LIMITS, $payload)) {
            throw $this->invalid();
        }
        foreach (self::LIMITS as $key => $limit) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1 || StringHelper::strlen($payload[$key]) > $limit) {
                throw $this->invalid();
            }
        }
        return array_intersect_key($payload, self::LIMITS);
    }
    private function load(string $id): ?object
    {
        $fields = ['lang_id', 'asset_id', 'lang_code', 'title', 'title_native', 'sef', 'image', 'description', 'metadesc', 'sitename', 'published', 'access', 'ordering'];
        $query  = $this->db->createQuery()->select(array_map(fn ($f) => $this->db->quoteName($f), $fields))->from($this->db->quoteName('#__languages'))->where($this->db->quoteName('lang_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadObject() ?: null;
    }
    private function invalid(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Language draft payload is invalid.');
    }
}
