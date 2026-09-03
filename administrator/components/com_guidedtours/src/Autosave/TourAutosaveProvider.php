<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_guidedtours
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Guidedtours\Administrator\Autosave;

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
 * Read-only Autosave provider for existing Guided Tours.
 *
 * @since  __DEPLOY_VERSION__
 */
final class TourAutosaveProvider implements AutosaveProviderInterface, AutosaveCreateProviderInterface
{
    private const CONTEXT                = 'com_guidedtours.tour';
    private const MAXIMUM_ID             = '4294967295';
    private const PAYLOAD_SCHEMA_VERSION = 1;
    private const BASE_REVISION_DOMAIN   = 'autosave:com_guidedtours.tour:base-revision:v1';
    private const STRING_LIMITS          = [
        'title'       => 255,
        'uid'         => 255,
        'description' => 65535,
        'note'        => 255,
        'url'         => 255,
    ];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getContext(): string
    {
        return self::CONTEXT;
    }

    public function getCreateContractVersion(): string
    {
        return 'guided-tour-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        if (!$user->authorise('core.create', 'com_guidedtours')) {
            throw new AutosaveException('forbidden', 'A Guided Tour cannot be created by this user.');
        }
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (
            preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1
            || (\strlen($targetId) === 10 && strcmp($targetId, self::MAXIMUM_ID) > 0)
        ) {
            throw new AutosaveException('invalid_target', 'The Guided Tour target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return $this->loadTour($this->canonicalizeTargetId($targetId)) !== null;
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

        $tour = $this->loadTour($targetId);

        if ($tour === null) {
            throw new AutosaveException('target_not_found', 'The Guided Tour was not found.');
        }

        $canEdit    = $user->authorise('core.edit', 'com_guidedtours');
        $canEditOwn = (int) $tour->created_by === (int) $user->id
            && $user->authorise('core.edit.own', 'com_guidedtours');

        if (!$canEdit && !$canEditOwn) {
            throw new AutosaveException('forbidden', 'The Guided Tour cannot be edited by this user.');
        }

        if ((int) $tour->checked_out !== 0 && (int) $tour->checked_out !== (int) $user->id) {
            throw new AutosaveException('forbidden', 'The Guided Tour is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $tour = $this->loadTour($this->canonicalizeTargetId($targetId));

        if ($tour === null) {
            throw new AutosaveException('target_not_found', 'The Guided Tour was not found.');
        }

        $canonical = json_encode(
            [
                'id'          => (int) $tour->id,
                'title'       => $tour->title,
                'uid'         => $tour->uid,
                'description' => $tour->description,
                'note'        => $tour->note,
                'url'         => $tour->url,
                'autostart'   => (int) $tour->autostart,
                'published'   => (int) $tour->published,
                'access'      => (int) $tour->access,
                'language'    => $tour->language,
                'extensions'  => $tour->extensions,
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

        $required = array_fill_keys([...array_keys(self::STRING_LIMITS), 'autostart'], true);

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

        if (!\is_int($payload['autostart']) || !\in_array($payload['autostart'], [0, 1], true)) {
            throw $this->invalidPayload();
        }

        return [
            'title'       => $payload['title'],
            'uid'         => $payload['uid'],
            'description' => $payload['description'],
            'note'        => $payload['note'],
            'url'         => $payload['url'],
            'autostart'   => $payload['autostart'],
        ];
    }

    private function loadTour(string $targetId): ?object
    {
        $id     = (int) $targetId;
        $fields = [
            'id', 'title', 'uid', 'description', 'note', 'url', 'autostart', 'published', 'access', 'language',
            'extensions', 'created_by', 'checked_out',
        ];
        $query = $this->db->createQuery()
            ->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))
            ->from($this->db->quoteName('#__guidedtours'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Guided Tour draft payload is invalid.');
    }
}
