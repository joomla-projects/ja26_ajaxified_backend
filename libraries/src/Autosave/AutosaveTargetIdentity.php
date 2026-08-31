<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Canonical, bounded Autosave target identities.
 *
 * Numeric identities retain their existing representation. Composite
 * identities use byte-length-prefixed members, avoiding delimiter ambiguity
 * without accepting arbitrary JSON or exposing component-specific semantics.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveTargetIdentity
{
    public const MAXIMUM_LENGTH      = 191;
    private const PROVISIONAL_PREFIX = 'p1:';

    public static function numeric(string $value, int $maximum = PHP_INT_MAX): string
    {
        $maximumText = (string) $maximum;

        if (
            $maximum < 1
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || \strlen($value) > \strlen($maximumText)
            || (\strlen($value) === \strlen($maximumText) && strcmp($value, $maximumText) > 0)
        ) {
            throw new \InvalidArgumentException('The numeric Autosave target is invalid.');
        }

        return $value;
    }

    public static function provisional(int $userId, string $context, string $initializationKey, string $secret): string
    {
        if ($userId <= 0 || $secret === '' || AutosaveContext::getComponentName($context) === null) {
            throw new \InvalidArgumentException('The provisional Autosave target inputs are invalid.');
        }

        self::validateInitializationKey($initializationKey);
        $input = pack('N', $userId)
            . pack('N', \strlen($context)) . $context
            . pack('N', \strlen($initializationKey)) . $initializationKey;
        $key = hash_hmac('sha256', 'joomla.autosave.provisional-target-key.v1', $secret, true);

        return self::PROVISIONAL_PREFIX
            . hash_hmac('sha256', "joomla.autosave.provisional-target.v1\0" . $input, $key);
    }

    public static function isProvisional(string $identity): bool
    {
        return preg_match('/^p1:[0-9a-f]{64}$/D', $identity) === 1;
    }

    public static function isProvisionalNamespace(string $identity): bool
    {
        return str_starts_with($identity, self::PROVISIONAL_PREFIX);
    }

    public static function requireProvisional(string $identity): string
    {
        if (!self::isProvisional($identity)) {
            throw new \InvalidArgumentException('The provisional Autosave target is invalid.');
        }

        return $identity;
    }

    public static function validateInitializationKey(string $initializationKey): void
    {
        if (
            $initializationKey === ''
            || \strlen($initializationKey) > self::MAXIMUM_LENGTH
            || preg_match('//u', $initializationKey) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $initializationKey) === 1
        ) {
            throw new \InvalidArgumentException('The Autosave initialization key is invalid.');
        }
    }

    /**
     * @param   list<string>  $members  Provider-controlled ordered members.
     */
    public static function composite(string $type, array $members): string
    {
        if (preg_match('/^[a-z][a-z0-9.]{0,31}$/D', $type) !== 1 || $members === []) {
            throw new \InvalidArgumentException('The composite Autosave target type is invalid.');
        }

        $identity = 'c1|' . \strlen($type) . ':' . $type;

        foreach ($members as $member) {
            if (!\is_string($member) || preg_match('//u', $member) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $member) === 1) {
                throw new \InvalidArgumentException('A composite Autosave target member is invalid.');
            }

            $identity .= '|' . \strlen($member) . ':' . $member;
        }

        if (\strlen($identity) > self::MAXIMUM_LENGTH) {
            throw new \InvalidArgumentException('The composite Autosave target is too long.');
        }

        return $identity;
    }

    /**
     * @return  array{type: string, members: list<string>}
     */
    public static function parseComposite(string $identity): array
    {
        if (!str_starts_with($identity, 'c1|') || \strlen($identity) > self::MAXIMUM_LENGTH) {
            throw new \InvalidArgumentException('The composite Autosave target is invalid.');
        }

        $offset = 3;
        $parts  = [];

        while ($offset < \strlen($identity)) {
            $colon = strpos($identity, ':', $offset);

            if ($colon === false) {
                throw new \InvalidArgumentException('The composite Autosave target is invalid.');
            }

            $lengthText = substr($identity, $offset, $colon - $offset);

            if (preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $lengthText) !== 1) {
                throw new \InvalidArgumentException('The composite Autosave target is invalid.');
            }

            $length = (int) $lengthText;
            $start  = $colon + 1;
            $part   = substr($identity, $start, $length);

            if (\strlen($part) !== $length || preg_match('//u', $part) !== 1) {
                throw new \InvalidArgumentException('The composite Autosave target is invalid.');
            }

            $parts[] = $part;
            $offset  = $start + $length;

            if ($offset === \strlen($identity)) {
                break;
            }

            if ($identity[$offset] !== '|') {
                throw new \InvalidArgumentException('The composite Autosave target is invalid.');
            }

            ++$offset;
        }

        if (\count($parts) < 2 || self::composite($parts[0], \array_slice($parts, 1)) !== $identity) {
            throw new \InvalidArgumentException('The composite Autosave target is invalid.');
        }

        return ['type' => $parts[0], 'members' => \array_slice($parts, 1)];
    }
}
