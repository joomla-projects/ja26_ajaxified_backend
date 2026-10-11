<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Templates\Administrator\Autosave;

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

final class StyleAutosaveProvider implements TargetAwareAutosaveProviderInterface
{
    /** @var callable(object): AutosaveDynamicSchema */
    private $schemaResolver;

    public function __construct(private readonly DatabaseInterface $db, callable $schemaResolver)
    {
        $this->schemaResolver = $schemaResolver;
    }

    public function getContext(): string
    {
        return 'com_templates.style';
    }

    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        if (
            preg_match('/^[1-9][0-9]{0,9}$/D', $targetId) !== 1
            || (\strlen($targetId) === 10 && strcmp($targetId, '2147483647') > 0)
        ) {
            throw new AutosaveException('invalid_target', 'The Template Style target is invalid.');
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
            throw new AutosaveException('target_not_found', 'The Template Style was not found.');
        }

        if (!$user->authorise('core.edit', 'com_templates')) {
            throw new AutosaveException('forbidden', 'The Template Style cannot be edited.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Template Style was not found.');
        }

        $domain = 'autosave:com_templates.style:base-revision:v1';

        return $domain . ':' . hash(
            'sha256',
            $domain . "\0" . json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    public function getDynamicSchema(string $targetId): AutosaveDynamicSchema
    {
        $record = $this->load($this->canonicalizeTargetId($targetId));

        if ($record === null) {
            throw new AutosaveException('target_not_found', 'The Template Style was not found.');
        }

        return ($this->schemaResolver)($record);
    }

    public function getDynamicSchemaForForm(string $targetId, Form $form): AutosaveDynamicSchema
    {
        if (!$this->targetExists($targetId)) {
            throw new AutosaveException('target_not_found', 'The Template Style was not found.');
        }

        return (new StyleAutosaveSchemaFactory())->fromForm($form);
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        throw $this->invalid();
    }

    public function normalizePayloadForTarget(string $targetId, mixed $payload, int $schemaVersion): array
    {
        $keys = ['title' => true, 'schemaFingerprint' => true, 'params' => true];

        if (
            $schemaVersion !== 1
            || !\is_array($payload)
            || array_is_list($payload)
            || array_diff_key($payload, $keys)
            || array_diff_key($keys, $payload)
            || !\is_string($payload['title'])
            || preg_match('//u', $payload['title']) !== 1
            || StringHelper::strlen($payload['title']) > 255
            || !\is_string($payload['schemaFingerprint'])
        ) {
            throw $this->invalid();
        }

        $schema = $this->getDynamicSchema($targetId);

        if (!hash_equals($schema->fingerprint(), $payload['schemaFingerprint'])) {
            throw $this->invalid();
        }

        try {
            $params = $schema->normalizePayload(['params' => $payload['params']])['params'] ?? [];
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }

        return [
            'title'             => $payload['title'],
            'schemaFingerprint' => $schema->fingerprint(),
            'params'            => $params,
        ];
    }

    private function load(string $id): ?object
    {
        $query = $this->db->createQuery()
            ->select(['id', 'template', 'client_id', 'title', 'home', 'params'])
            ->from($this->db->quoteName('#__template_styles'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function invalid(): AutosaveException
    {
        return new AutosaveException('invalid_payload', 'The Template Style draft payload is invalid.');
    }
}
