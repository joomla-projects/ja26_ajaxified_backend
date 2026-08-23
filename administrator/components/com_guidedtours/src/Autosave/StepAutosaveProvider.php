<?php

/**
 * @package       Joomla.Administrator
 * @subpackage    com_guidedtours
 *
 * @copyright     (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license       GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Guidedtours\Administrator\Autosave;

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
 * Read-only Autosave provider for existing Guided Tour Steps.
 *
 * @since  __DEPLOY_VERSION__
 */
final class StepAutosaveProvider implements AutosaveProviderInterface
{
    private const CONTEXT                = 'com_guidedtours.step';
    private const MAXIMUM_ID             = '4294967295';
    private const PAYLOAD_SCHEMA_VERSION = 1;
    private const BASE_REVISION_DOMAIN   = 'autosave:com_guidedtours.step:base-revision:v1';
    private const STRING_LIMITS          = [
        'position'      => 255,
        'target'        => 255,
        'title'         => 255,
        'description'   => 65535,
        'url'           => 255,
        'note'          => 255,
        'requiredvalue' => 65535,
    ];
    private const POSITION_VALUES        = ['bottom', 'center', 'left', 'right', 'top'];
    private const TYPE_VALUES            = [0, 1, 2];
    private const INTERACTIVE_VALUES     = [1, 2, 3, 4, 5, 6];

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
            throw new AutosaveException('invalid_target', 'The Guided Tour Step target is invalid.');
        }

        return $targetId;
    }

    public function targetExists(string $targetId): bool
    {
        return $this->loadStep($this->canonicalizeTargetId($targetId)) !== null;
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

        $step = $this->loadStep($targetId);

        if ($step === null) {
            throw new AutosaveException('target_not_found', 'The Guided Tour Step was not found.');
        }

        $canEdit    = $user->authorise('core.edit', 'com_guidedtours');
        $canEditOwn = (int) $step->created_by === (int) $user->id
            && $user->authorise('core.edit.own', 'com_guidedtours');

        if (!$canEdit && !$canEditOwn) {
            throw new AutosaveException('forbidden', 'The Guided Tour Step cannot be edited by this user.');
        }

        if ((int) $step->checked_out !== 0 && (int) $step->checked_out !== (int) $user->id) {
            throw new AutosaveException('forbidden', 'The Guided Tour Step is checked out by another user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $step = $this->loadStep($this->canonicalizeTargetId($targetId));

        if ($step === null) {
            throw new AutosaveException('target_not_found', 'The Guided Tour Step was not found.');
        }

        $canonical = json_encode(
            [
                'id'               => (int) $step->id,
                'tour_id'          => (int) $step->tour_id,
                'title'            => $step->title,
                'description'      => $step->description,
                'position'         => $step->position,
                'target'           => $step->target,
                'type'             => (int) $step->type,
                'interactive_type' => (int) $step->interactive_type,
                'url'              => $step->url,
                'note'             => $step->note,
                'params'           => $step->params,
                'published'        => (int) $step->published,
                'language'         => $step->language,
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

        $integerKeys = ['type', 'interactive_type', 'required'];
        $required    = array_fill_keys([...array_keys(self::STRING_LIMITS), ...$integerKeys], true);

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

        if (
            !\in_array($payload['position'], self::POSITION_VALUES, true)
            || !\is_int($payload['type']) || !\in_array($payload['type'], self::TYPE_VALUES, true)
            || !\is_int($payload['interactive_type'])
            || !\in_array($payload['interactive_type'], self::INTERACTIVE_VALUES, true)
            || !\is_int($payload['required']) || !\in_array($payload['required'], [0, 1], true)
        ) {
            throw $this->invalidPayload();
        }

        return [
            'position'         => $payload['position'],
            'target'           => $payload['target'],
            'title'            => $payload['title'],
            'description'      => $payload['description'],
            'type'             => $payload['type'],
            'url'              => $payload['url'],
            'interactive_type' => $payload['interactive_type'],
            'note'             => $payload['note'],
            'required'         => $payload['required'],
            'requiredvalue'    => $payload['requiredvalue'],
        ];
    }

    private function loadStep(string $targetId): ?object
    {
        $id     = (int) $targetId;
        $fields = [
            's.id', 's.tour_id', 's.title', 's.description', 's.position', 's.target', 's.type',
            's.interactive_type', 's.url', 's.note', 's.params', 's.published', 's.language',
            's.created_by', 's.checked_out',
        ];
        $query = $this->db->createQuery()
            ->select(array_map(fn ($field) => $this->db->quoteName($field), $fields))
            ->from($this->db->quoteName('#__guidedtour_steps', 's'))
            ->innerJoin($this->db->quoteName('#__guidedtours', 't'), $this->db->quoteName('t.id') . ' = ' . $this->db->quoteName('s.tour_id'))
            ->where($this->db->quoteName('s.id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Guided Tour Step draft payload is invalid.');
    }
}
