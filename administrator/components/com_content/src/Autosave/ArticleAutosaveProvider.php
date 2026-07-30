<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Content\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Read-only Autosave provider for existing Articles.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ArticleAutosaveProvider implements AutosaveProviderInterface
{
    /**
     * Exact supported context.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const CONTEXT = 'com_content.article';

    /**
     * Component owning valid Article categories.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const CATEGORY_EXTENSION = 'com_content';

    /**
     * Largest Article identity supported by Joomla's unsigned integer schema.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const MAXIMUM_ID = '4294967295';

    /**
     * Payload schema implemented by this provider.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const PAYLOAD_SCHEMA_VERSION = 1;

    /**
     * Domain and version prefix for opaque canonical Article revisions.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const BASE_REVISION_DOMAIN = 'autosave:com_content.article:base-revision:v1';

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
        if (!$this->isCanonicalId($targetId)) {
            throw new AutosaveException('invalid_target', 'The Article target is invalid.');
        }

        return $targetId;
    }

    /**
     * {@inheritDoc}
     */
    public function targetExists(string $targetId): bool
    {
        $targetId = $this->canonicalizeTargetId($targetId);

        return $this->loadArticle($targetId) !== null;
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
            AutosaveOperation::Read => null,
        };

        $article = $this->loadArticle($targetId);

        if ($article === null) {
            throw new AutosaveException('target_not_found', 'The Article target was not found.');
        }

        $asset   = self::CONTEXT . '.' . $targetId;
        $canEdit = $user->authorise('core.edit', $asset)
            || (
                $user->authorise('core.edit.own', $asset)
                && (int) $article->created_by === (int) $user->id
            );

        if (!$canEdit) {
            throw new AutosaveException('forbidden', 'The Article cannot be edited by this user.');
        }

        if ((int) $article->checked_out !== 0 && (int) $article->checked_out !== (int) $user->id) {
            throw new AutosaveException('checkout_conflict', 'The Article is checked out by another user.');
        }
    }

    /**
     * {@inheritDoc}
     *
     * The returned token exposes only its format version and a SHA-256 digest.
     * Its internal canonical input is component-owned and is not a wire format.
     */
    public function getBaseRevision(string $targetId): string
    {
        $targetId = $this->canonicalizeTargetId($targetId);
        $article  = $this->loadArticle($targetId);

        if ($article === null) {
            throw new AutosaveException('target_not_found', 'The Article target was not found.');
        }

        $title     = (string) $article->title;
        $version   = (string) (int) $article->version;
        $canonical = self::BASE_REVISION_DOMAIN
            . "\0" . $targetId
            . "\0" . $version
            . "\0" . \strlen($title)
            . "\0" . $title;

        return self::BASE_REVISION_DOMAIN . ':' . hash('sha256', $canonical);
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
            'title'       => true,
            'alias'       => true,
            'articletext' => true,
            'catid'       => true,
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

        foreach (['title', 'alias', 'articletext'] as $key) {
            if (!\is_string($payload[$key]) || preg_match('//u', $payload[$key]) !== 1) {
                throw $this->invalidPayload();
            }
        }

        $categoryId = $this->canonicalizeCategoryId($payload['catid']);

        if (!$this->categoryExists($categoryId)) {
            throw $this->invalidPayload();
        }

        return [
            'title'       => $payload['title'],
            'alias'       => $payload['alias'],
            'articletext' => $payload['articletext'],
            'catid'       => (int) $categoryId,
        ];
    }

    /**
     * Determine whether an identifier is a canonical Joomla unsigned integer ID.
     *
     * @param   string  $id  The proposed identifier.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function isCanonicalId(string $id): bool
    {
        return preg_match('/^[1-9][0-9]{0,9}$/D', $id) === 1
            && (\strlen($id) < 10 || strcmp($id, self::MAXIMUM_ID) <= 0);
    }

    /**
     * Validate and canonicalize a draft category identity.
     *
     * @param   mixed  $categoryId  The proposed category identity.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function canonicalizeCategoryId(mixed $categoryId): string
    {
        if (\is_int($categoryId)) {
            $categoryId = (string) $categoryId;
        }

        if (!\is_string($categoryId) || !$this->isCanonicalId($categoryId)) {
            throw $this->invalidPayload();
        }

        return $categoryId;
    }

    /**
     * Determine whether a category belongs to com_content.
     *
     * This validates inert draft data only. It does not authorize or perform a
     * canonical category move.
     *
     * @param   string  $categoryId  The canonical category identity.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function categoryExists(string $categoryId): bool
    {
        $id        = (int) $categoryId;
        $extension = self::CATEGORY_EXTENSION;
        $query     = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('id') . ' = :categoryId')
            ->where($this->db->quoteName('extension') . ' = :extension')
            ->bind(':categoryId', $id, ParameterType::INTEGER)
            ->bind(':extension', $extension, ParameterType::STRING);

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Load only canonical fields required by the provider.
     *
     * @param   string  $targetId  The canonical Article identity.
     *
     * @return  ?object
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadArticle(string $targetId): ?object
    {
        $id    = (int) $targetId;
        $query = $this->db->createQuery()
            ->select(
                [
                    $this->db->quoteName('id'),
                    $this->db->quoteName('title'),
                    $this->db->quoteName('version'),
                    $this->db->quoteName('created_by'),
                    $this->db->quoteName('checked_out'),
                ]
            )
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $article = $this->db->setQuery($query)->loadObject();

        return $article ?: null;
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
        return new AutosaveException('invalid_payload', 'The Article draft payload is invalid.');
    }
}
