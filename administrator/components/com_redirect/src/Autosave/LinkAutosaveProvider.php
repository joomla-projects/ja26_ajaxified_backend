<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_redirect
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Redirect\Administrator\Autosave;

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
 * Read-only Autosave provider for existing Redirect links.
 *
 * @since  __DEPLOY_VERSION__
 */
final class LinkAutosaveProvider implements AutosaveProviderInterface
{
    private const CONTEXT                = 'com_redirect.link';
    private const MAXIMUM_ID             = '4294967295';
    private const PAYLOAD_SCHEMA_VERSION = 1;
    private const MAXIMUM_URL_LENGTH     = 2048;
    private const MAXIMUM_COMMENT_LENGTH = 255;
    private const BASE_REVISION_DOMAIN   = 'autosave:com_redirect.link:base-revision:v1';

    /**
     * Constructor.
     *
     * @param   DatabaseInterface  $db  The database connection.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getContext(): string
    {
        return self::CONTEXT;
    }

    /**
     * {@inheritDoc}
     */
    public function canonicalizeTargetId(string $targetId): string
    {
        if (
            preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1
            || (\strlen($targetId) === 10 && strcmp($targetId, self::MAXIMUM_ID) > 0)
        ) {
            throw new AutosaveException('invalid_target', 'The Redirect target is invalid.');
        }

        return $targetId;
    }

    /**
     * {@inheritDoc}
     */
    public function targetExists(string $targetId): bool
    {
        return $this->loadLink($this->canonicalizeTargetId($targetId)) !== null;
    }

    /**
     * {@inheritDoc}
     */
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

        if ($this->loadLink($targetId) === null) {
            throw new AutosaveException('target_not_found', 'The Redirect target was not found.');
        }

        if (!$user->authorise('core.edit', 'com_redirect')) {
            throw new AutosaveException('forbidden', 'The Redirect link cannot be edited by this user.');
        }
    }

    /**
     * {@inheritDoc}
     *
     * Traffic counters are deliberately excluded: following a redirect must
     * not make an otherwise current editorial draft stale.
     */
    public function getBaseRevision(string $targetId): string
    {
        $targetId = $this->canonicalizeTargetId($targetId);
        $link     = $this->loadLink($targetId);

        if ($link === null) {
            throw new AutosaveException('target_not_found', 'The Redirect target was not found.');
        }

        $canonical = json_encode(
            [
                'id'            => (int) $link->id,
                'old_url'       => $link->old_url,
                'new_url'       => $link->new_url,
                'comment'       => $link->comment,
                'published'     => (int) $link->published,
                'header'        => (int) $link->header,
                'modified_date' => $link->modified_date,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return self::BASE_REVISION_DOMAIN . ':' . hash(
            'sha256',
            self::BASE_REVISION_DOMAIN . "\0" . $canonical
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPayloadSchemaVersion(): int
    {
        return self::PAYLOAD_SCHEMA_VERSION;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        if ($schemaVersion !== self::PAYLOAD_SCHEMA_VERSION) {
            throw new AutosaveException(
                'unsupported_schema_version',
                'The payload schema version is not supported.'
            );
        }

        $requiredKeys = [
            'old_url' => true,
            'new_url' => true,
            'comment' => true,
        ];

        if (
            !\is_array($payload)
            || array_is_list($payload)
            || \count($payload) !== \count($requiredKeys)
            || array_diff_key($payload, $requiredKeys) !== []
            || array_diff_key($requiredKeys, $payload) !== []
        ) {
            throw $this->invalidPayload();
        }

        foreach ($requiredKeys as $key => $unused) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1) {
                throw $this->invalidPayload();
            }
        }

        if (
            StringHelper::strlen($payload['old_url']) > self::MAXIMUM_URL_LENGTH
            || StringHelper::strlen($payload['new_url']) > self::MAXIMUM_URL_LENGTH
            || StringHelper::strlen($payload['comment']) > self::MAXIMUM_COMMENT_LENGTH
        ) {
            throw $this->invalidPayload();
        }

        return [
            'old_url' => $payload['old_url'],
            'new_url' => $payload['new_url'],
            'comment' => $payload['comment'],
        ];
    }

    /**
     * Load only canonical fields required by this provider.
     *
     * @param   string  $targetId  The canonical Redirect identity.
     *
     * @return  ?object
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadLink(string $targetId): ?object
    {
        $id    = (int) $targetId;
        $query = $this->db->createQuery()
            ->select(
                [
                    $this->db->quoteName('id'),
                    $this->db->quoteName('old_url'),
                    $this->db->quoteName('new_url'),
                    $this->db->quoteName('comment'),
                    $this->db->quoteName('published'),
                    $this->db->quoteName('header'),
                    $this->db->quoteName('modified_date'),
                ]
            )
            ->from($this->db->quoteName('#__redirect_links'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $link = $this->db->setQuery($query)->loadObject();

        return $link ?: null;
    }

    /**
     * Create the stable invalid-payload domain failure.
     *
     * @return  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    private function invalidPayload(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Redirect draft payload is invalid.');
    }
}
