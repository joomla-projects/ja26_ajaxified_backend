<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_contact
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Contact\Administrator\Autosave;

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

/**
 * Privacy-minimized Autosave contract for Contact records.
 *
 * New-record (create-mode) drafts add only the authored category relation to the
 * existing text allow-list. The linked user, publication/access state, tags,
 * params/metadata and identity bookkeeping stay native-only: those values are
 * either derived server-side, re-primed from routing state, or gated behind the
 * com_users core.manage control that is not present for every editor, so they
 * never ride a draft (mirroring the pre-existing PII minimization).
 *
 * @since  __DEPLOY_VERSION__
 */
final class ContactAutosaveProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    private const LIMITS = [
        'name'             => 255,
        'alias'            => 255,
        'version_note'     => 255,
        'misc'             => 65535,
        'image'            => 255,
        'con_position'     => 255,
        'email_to'         => 255,
        'address'          => 65535,
        'suburb'           => 100,
        'state'            => 100,
        'postcode'         => 100,
        'country'          => 100,
        'telephone'        => 255,
        'mobile'           => 255,
        'fax'              => 255,
        'webpage'          => 255,
        'sortname1'        => 255,
        'sortname2'        => 255,
        'sortname3'        => 255,
        'publish_up'       => 255,
        'publish_up_alt'   => 255,
        'publish_down'     => 255,
        'publish_down_alt' => 255,
        'metakey'          => 65535,
        'metadesc'         => 300,
    ];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getContext(): string
    {
        return 'com_contact.contact';
    }

    public function getCreateContractVersion(): string
    {
        return 'contact-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        if ($normalizedPayload === null) {
            // Mirrors ContactController::allowAdd() without a category choice yet:
            // a global component create right or any creatable category is enough
            // to open the blank form and later anchor the draft to a category.
            if (
                !$user->authorise('core.create', 'com_contact')
                && \count($user->getAuthorisedCategories('com_contact', 'core.create')) === 0
            ) {
                throw new AutosaveException('forbidden', 'A Contact cannot be created by this user.');
            }

            return;
        }

        $catid = $normalizedPayload['catid'] ?? null;

        if (!\is_int($catid) || $catid <= 0 || $catid > 2147483647 || !$this->categoryExists($catid)) {
            throw $this->invalidPayload();
        }

        if (!$user->authorise('core.create', 'com_contact.category.' . $catid)) {
            throw new AutosaveException('forbidden', 'A Contact cannot be created in this category.');
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
            || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)
        ) {
            throw new AutosaveException('invalid_target', 'The Contact target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return $this->hasCanonicalCategory($this->load($this->canonicalizeTargetId($targetId)));
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if (!$this->hasCanonicalCategory($record)) {
            throw new AutosaveException('target_not_found', 'The Contact was not found.');
        }

        $asset   = 'com_contact.category.' . (int) $record->catid;
        $allowed = $user->authorise('core.edit', $asset)
            || ($user->authorise('core.edit.own', $asset) && (int) $record->created_by === (int) $user->id);

        if (!$allowed) {
            throw new AutosaveException('forbidden', 'The Contact cannot be edited by this user.');
        }

        if ((int) ($record->checked_out ?? 0) !== 0 && (int) $record->checked_out !== (int) $user->id) {
            throw new AutosaveException('checked_out', 'The Contact is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if (!$this->hasCanonicalCategory($record)) {
            throw new AutosaveException('target_not_found', 'The Contact was not found.');
        }

        unset($record->checked_out);
        $domain = 'autosave:com_contact.contact:base-revision:v1';

        return $domain . ':' . hash(
            'sha256',
            $domain . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $required          = array_fill_keys(array_keys(self::LIMITS), true);
        $required['catid'] = true;

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

        if (!\is_int($payload['catid']) || $payload['catid'] < 1 || $payload['catid'] > 2147483647) {
            throw $this->invalidPayload();
        }

        if (!$this->isStableMediaReference($payload['image'])) {
            throw $this->invalidPayload();
        }

        $normalized = [];

        foreach (array_keys(self::LIMITS) as $key) {
            $normalized[$key] = $payload[$key];
        }

        $normalized['catid'] = $payload['catid'];

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

    private function categoryExists(int $catid): bool
    {
        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('id') . ' = :catid')
            ->bind(':catid', $catid, ParameterType::INTEGER)
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_contact'));

        return (int) $this->db->setQuery($query)->loadResult() === 1;
    }

    private function load(string $targetId): ?object
    {
        $fields = [
            'id', 'name', 'alias', 'con_position', 'address', 'suburb', 'state', 'country', 'postcode',
            'telephone', 'fax', 'misc', 'image', 'email_to', 'default_con', 'published', 'checked_out',
            'ordering', 'params', 'user_id', 'catid', 'access', 'mobile', 'webpage', 'sortname1', 'sortname2',
            'sortname3', 'language', 'created', 'created_by', 'created_by_alias', 'modified', 'modified_by',
            'metakey', 'metadesc', 'metadata', 'featured', 'publish_up', 'publish_down', 'version', 'hits',
        ];
        $query = $this->db->createQuery()
            ->select(array_map(fn ($field) => 'c.' . $this->db->quoteName($field), $fields))
            ->select([
                'cat.' . $this->db->quoteName('id') . ' AS ' . $this->db->quoteName('category_id'),
                'cat.' . $this->db->quoteName('extension') . ' AS ' . $this->db->quoteName('category_extension'),
            ])
            ->from($this->db->quoteName('#__contact_details', 'c'))
            ->leftJoin(
                $this->db->quoteName('#__categories', 'cat')
                . ' ON cat.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('catid')
            )
            ->where('c.' . $this->db->quoteName('id') . ' = :id')
            ->bind(':id', $targetId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function hasCanonicalCategory(?object $record): bool
    {
        return $record !== null
            && (int) $record->category_id > 0
            && $record->category_extension === 'com_contact';
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Contact draft payload is invalid.');
    }
}
