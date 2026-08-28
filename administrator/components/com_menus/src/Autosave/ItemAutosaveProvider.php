<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Menus\Administrator\Autosave;

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

final class ItemAutosaveProvider implements TargetAwareAutosaveProviderInterface
{
    private const STRING_LIMITS = ['title' => 255, 'alias' => 400, 'note' => 255];

    /** @var callable(object): AutosaveDynamicSchema */
    private $schemaResolver;

    public function __construct(private readonly DatabaseInterface $db, callable $schemaResolver)
    {
        $this->schemaResolver = $schemaResolver;
    }

    public function getContext(): string
    {
        return 'com_menus.item';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1 || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)) {
            throw new AutosaveException('invalid_target', 'The Menu Item target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        if (!$user->authorise('core.edit', 'com_menus.menu.' . (int) $record->menu_type_id)) {
            throw new AutosaveException('forbidden', 'The Menu Item cannot be edited by this user.');
        }

        if ((int) $record->checked_out !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Menu Item is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_menus.item:base-revision:v1';

        return $domain . ':' . hash('sha256', $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function getDynamicSchema(string $targetId): AutosaveDynamicSchema
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        return ($this->schemaResolver)($record);
    }

    public function getDynamicSchemaForForm(string $targetId, Form $form): AutosaveDynamicSchema
    {
        if (!$this->targetExists($targetId)) {
            throw new AutosaveException('target_not_found', 'The Menu Item was not found.');
        }

        return (new ItemAutosaveSchemaFactory())->fromForm($form);
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        throw $this->invalidPayload();
    }

    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $keys = array_fill_keys([...array_keys(self::STRING_LIMITS), 'browserNav', 'schemaFingerprint', 'params'], true);

        if ($schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $keys) || array_diff_key($keys, $payload)) {
            throw $this->invalidPayload();
        }

        foreach (self::STRING_LIMITS as $name => $limit) {
            if (!\is_string($payload[$name]) || preg_match('//u', $payload[$name]) !== 1 || StringHelper::strlen($payload[$name]) > $limit) {
                throw $this->invalidPayload();
            }
        }

        if (!\is_string($payload['browserNav']) || !\in_array($payload['browserNav'], ['0', '1', '2'], true) || !\is_string($payload['schemaFingerprint'])) {
            throw $this->invalidPayload();
        }

        $schema = $this->getDynamicSchema($targetId);

        if (!hash_equals($schema->fingerprint(), $payload['schemaFingerprint'])) {
            throw $this->invalidPayload();
        }

        try {
            $params = $schema->normalizePayload(['params' => $payload['params']])['params'];
        } catch (\InvalidArgumentException) {
            throw $this->invalidPayload();
        }

        return array_merge(array_intersect_key($payload, self::STRING_LIMITS), ['browserNav' => $payload['browserNav'], 'schemaFingerprint' => $schema->fingerprint(), 'params' => $params]);
    }

    private function load(string $targetId): ?object
    {
        $fields = ['m.id', 'm.menutype', 'm.title', 'm.alias', 'm.note', 'm.link', 'm.type', 'm.component_id', 'm.browserNav', 'm.params', 'm.checked_out', 'm.published', 'm.parent_id', 'm.access', 'm.language', 'm.home', 'mt.id AS menu_type_id', 'mt.client_id AS client_id'];
        $query  = $this->db->createQuery()->select($fields)->from($this->db->quoteName('#__menu', 'm'))->join('INNER', $this->db->quoteName('#__menu_types', 'mt') . ' ON mt.menutype = m.menutype')->where('m.id = :id')->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Menu Item draft payload is invalid.');
    }
}
