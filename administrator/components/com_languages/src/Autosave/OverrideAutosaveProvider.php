<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_languages
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Languages\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\Autosave\AutosaveStaticScopeProviderInterface;
use Joomla\CMS\Autosave\AutosaveTargetIdentity;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Languages\Administrator\Helper\LanguagesHelper;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class OverrideAutosaveProvider implements
    AutosaveProviderInterface,
    AutosaveCreateProviderInterface,
    AutosaveStaticScopeProviderInterface
{
    private const TYPE        = 'languages.override';
    private const KEY_LIMIT   = 110;
    private const VALUE_LIMIT = 65535;

    /** @var callable(string, string): array<string, string> */
    private $reader;

    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader ?? static function (string $client, string $language): array {
            $root = $client === 'administrator' ? JPATH_ADMINISTRATOR : JPATH_SITE;

            return LanguageHelper::parseIniFile($root . '/language/overrides/' . $language . '.override.ini');
        };
    }

    public function getContext(): string
    {
        return 'com_languages.override';
    }
    public function getPayloadSchemaVersion(): int
    {
        return 1;
    }
    public function getCreateContractVersion(): string
    {
        return 'override-create-v1';
    }

    public function authorizeCreate(User $user, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // Mirrors the native create gate for Language Overrides: a component-wide
        // core.create on com_languages. The target client/language live in the
        // anchored immutable scope, never in the draft payload, so no per-relation
        // payload authorization is possible or needed here.
        if (!$user->authorise('core.create', 'com_languages')) {
            throw new AutosaveException('forbidden', 'A Language Override cannot be created by this user.');
        }
    }

    public function getStaticScopeContractVersion(): string
    {
        return 'override-scope-v1';
    }

    public function canonicalizeStaticCreateScope(mixed $candidateScope): string
    {
        if (!\is_string($candidateScope)) {
            throw $this->invalidScope();
        }

        $parts = explode('|', $candidateScope, 2);

        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw $this->invalidScope();
        }

        [$client, $language] = $parts;

        if (
            !\in_array($client, ['site', 'administrator'], true)
            || \strlen($language) > 32
            || preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D', $language) !== 1
        ) {
            throw $this->invalidScope();
        }

        return $client . '|' . $language;
    }

    public function authorizeStaticCreateScope(User $user, string $canonicalScope, AutosaveOperation $operation, ?array $normalizedPayload): void
    {
        // The anchored scope is re-authorized for every provisional operation so a
        // permission revocation after P1 fails closed. Canonicalizing also rejects
        // a malformed stored scope before any authorization is granted.
        $this->canonicalizeStaticCreateScope($canonicalScope);

        if (!$user->authorise('core.create', 'com_languages')) {
            throw new AutosaveException('forbidden', 'A Language Override cannot be created by this user.');
        }
    }

    public function verifyFinalTargetStaticScope(string $finalTargetId, string $canonicalScope): void
    {
        // A malformed or foreign composite target fails through canonicalizeTargetId.
        [$client, $language] = $this->parts($this->canonicalizeTargetId($finalTargetId));

        if ($client . '|' . $language !== $this->canonicalizeStaticCreateScope($canonicalScope)) {
            throw new AutosaveException('scope_mismatch', 'The saved Language Override does not belong to the anchored client and language.');
        }
    }

    public static function target(string $client, string $language, string $key): string
    {
        return AutosaveTargetIdentity::composite(self::TYPE, [$client, $language, $key]);
    }

    public function canonicalizeTargetId(string $targetId): string
    {
        try {
            $identity = AutosaveTargetIdentity::parseComposite($targetId);
        } catch (\InvalidArgumentException) {
            throw $this->invalidTarget();
        }
        if ($identity['type'] !== self::TYPE || \count($identity['members']) !== 3) {
            throw $this->invalidTarget();
        }
        [$client, $language, $key] = $identity['members'];
        if (
            !\in_array($client, ['site', 'administrator'], true) || $language === '' || StringHelper::strlen($language) > 32
            || preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D', $language) !== 1 || $key === ''
            || StringHelper::strlen($key) > self::KEY_LIMIT || LanguagesHelper::filterKey($key) !== $key
        ) {
            throw $this->invalidTarget();
        }
        return self::target($client, $language, $key);
    }

    public function targetExists(string $targetId): bool
    {
        [$client, $language, $key] = $this->parts($this->canonicalizeTargetId($targetId));
        return \array_key_exists($key, $this->read($client, $language));
    }

    public function authorize(User $user, string $targetId, AutosaveOperation $operation): void
    {
        if (!$this->targetExists($targetId)) {
            throw new AutosaveException('target_not_found', 'The Language Override was not found.');
        }
        if (!$user->authorise('core.edit', 'com_languages')) {
            throw new AutosaveException('forbidden', 'The Language Override cannot be edited by this user.');
        }
    }

    public function getBaseRevision(string $targetId): string
    {
        [$client, $language, $key] = $this->parts($this->canonicalizeTargetId($targetId));
        $strings                   = $this->read($client, $language);
        if (!\array_key_exists($key, $strings)) {
            throw new AutosaveException('target_not_found', 'The Language Override was not found.');
        }
        $opposite = $this->read($client === 'site' ? 'administrator' : 'site', $language);
        $domain   = 'autosave:com_languages.override:entry-revision:v1';
        $state    = static function (array $entries, string $entryKey): string {
            if (!\array_key_exists($entryKey, $entries)) {
                return 'missing';
            }

            $value = (string) $entries[$entryKey];

            return 'present:' . \strlen($value) . ':' . $value;
        };

        return $domain . ':' . hash('sha256', $domain . "\0primary\0" . $state($strings, $key) . "\0opposite\0" . $state($opposite, $key));
    }

    public function normalizePayload(mixed $payload, int $schemaVersion): array
    {
        $keys = ['key' => true, 'override' => true, 'both' => true];
        if (
            $schemaVersion !== 1 || !\is_array($payload) || array_is_list($payload) || array_diff_key($payload, $keys)
            || array_diff_key($keys, $payload) || !\is_string($payload['key']) || !\is_string($payload['override'])
            || !\is_bool($payload['both']) || preg_match('//u', $payload['key']) !== 1 || preg_match('//u', $payload['override']) !== 1
            || StringHelper::strlen($payload['key']) > self::KEY_LIMIT || StringHelper::strlen($payload['override']) > self::VALUE_LIMIT
        ) {
            throw new AutosaveException('invalid_payload', 'The Language Override draft payload is invalid.');
        }
        return ['key' => $payload['key'], 'override' => $payload['override'], 'both' => $payload['both']];
    }

    private function parts(string $targetId): array
    {
        return AutosaveTargetIdentity::parseComposite($targetId)['members'];
    }
    private function read(string $client, string $language): array
    {
        return ($this->reader)($client, $language);
    }
    private function invalidTarget(): AutosaveException
    {
        return new AutosaveException('invalid_target', 'The Language Override target is invalid.');
    }
    private function invalidScope(): AutosaveException
    {
        return new AutosaveException('invalid_scope', 'The Language Override creation scope is invalid.');
    }
}
