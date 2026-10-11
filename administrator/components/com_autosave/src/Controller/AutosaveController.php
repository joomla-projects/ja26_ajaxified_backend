<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Autosave\Administrator\Controller;

use Joomla\CMS\Autosave\AutosaveLifecycle;
use Joomla\CMS\Date\Date;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Validate operation envelopes and invoke the generic lifecycle.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveController
{
    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly AutosaveLifecycle $lifecycle)
    {
    }

    /**
     * Invoke one exact lifecycle operation.
     *
     * @return  array|null
     *
     * @throws  \InvalidArgumentException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function execute(string $operation, array $request, User $user, Date $now): ?array
    {
        return match ($operation) {
            'initialize'                => $this->initialize($request, $user, $now),
            'initializeCreate'          => $this->initializeCreate($request, $user, $now),
            'preserve'                  => $this->preserve($request, $user, $now),
            'detect'                    => $this->detect($request, $user, $now),
            'read'                      => $this->read($request, $user, $now),
            'discard'                   => $this->discard($request, $user, $now),
            'prepareCanonicalAction'    => $this->prepareCanonicalAction($request, $user, $now),
            'getCanonicalActionOutcome' => $this->getCanonicalActionOutcome($request, $user, $now),
            default                     => throw new \InvalidArgumentException('The Autosave operation is unsupported.'),
        };
    }

    private function initializeCreate(array $request, User $user, Date $now): array
    {
        $this->requireStrings($request, ['context', 'initialization_key']);

        if (\array_key_exists('create_scope', $request)) {
            $this->requireExactKeys($request, ['context', 'initialization_key', 'create_scope']);

            if (!\is_string($request['create_scope']) || $request['create_scope'] === '') {
                throw new \InvalidArgumentException('The Autosave request is invalid.');
            }

            $candidateScope = $request['create_scope'];
        } else {
            $this->requireExactKeys($request, ['context', 'initialization_key']);
            $candidateScope = null;
        }

        return $this->lifecycle->initializeCreate(
            $user,
            $request['context'],
            $request['initialization_key'],
            $now,
            $candidateScope
        );
    }

    private function initialize(array $request, User $user, Date $now): array
    {
        $this->requireExactKeys($request, ['context', 'target_id', 'initialization_key']);
        $this->requireStrings($request, ['context', 'target_id', 'initialization_key']);

        return $this->lifecycle->initialize(
            $user,
            $request['context'],
            $request['target_id'],
            $request['initialization_key'],
            $now
        );
    }

    private function preserve(array $request, User $user, Date $now): array
    {
        $this->requireExactKeys(
            $request,
            ['continuation_id', 'generation_id', 'client_revision', 'payload_schema_version', 'payload']
        );
        $this->requireStrings($request, ['continuation_id', 'generation_id']);
        $this->requirePositiveIntegers($request, ['client_revision', 'payload_schema_version']);

        return $this->lifecycle->preserve(
            $user,
            $request['continuation_id'],
            $request['generation_id'],
            $request['client_revision'],
            $request['payload'],
            $request['payload_schema_version'],
            $now
        );
    }

    private function detect(array $request, User $user, Date $now): ?array
    {
        $this->requireExactKeys($request, ['context', 'target_id']);
        $this->requireStrings($request, ['context', 'target_id']);

        return $this->lifecycle->detect($user, $request['context'], $request['target_id'], $now);
    }

    private function read(array $request, User $user, Date $now): array
    {
        $this->requireExactKeys($request, ['continuation_id', 'generation_id']);
        $this->requireStrings($request, ['continuation_id', 'generation_id']);

        return $this->lifecycle->read($user, $request['continuation_id'], $request['generation_id'], $now);
    }

    private function discard(array $request, User $user, Date $now): array
    {
        $this->requireExactKeys($request, ['continuation_id', 'generation_id']);
        $this->requireStrings($request, ['continuation_id', 'generation_id']);

        return $this->lifecycle->discard($user, $request['continuation_id'], $request['generation_id'], $now);
    }

    private function prepareCanonicalAction(array $request, User $user, Date $now): array
    {
        $this->requireExactKeys(
            $request,
            [
                'context',
                'target_id',
                'continuation_id',
                'generation_id',
                'client_revision',
                'payload_schema_version',
                'payload',
                'intent',
                'expected_base_revision',
            ]
        );
        $this->requireStrings(
            $request,
            [
                'context',
                'target_id',
                'continuation_id',
                'generation_id',
                'intent',
                'expected_base_revision',
            ]
        );
        $this->requirePositiveIntegers($request, ['client_revision', 'payload_schema_version']);

        return $this->lifecycle->prepareCanonicalAction(
            $user,
            $request['context'],
            $request['target_id'],
            $request['continuation_id'],
            $request['generation_id'],
            $request['client_revision'],
            $request['payload'],
            $request['payload_schema_version'],
            $request['intent'],
            $request['expected_base_revision'],
            $now
        );
    }

    private function getCanonicalActionOutcome(array $request, User $user, Date $now): array
    {
        $this->requireExactKeys($request, ['operation_id', 'context', 'target_id']);
        $this->requireStrings($request, ['operation_id', 'context', 'target_id']);

        return $this->lifecycle->getCanonicalActionOutcome(
            $user,
            $request['operation_id'],
            $request['context'],
            $request['target_id'],
            $now
        );
    }

    private function requireExactKeys(array $request, array $expected): void
    {
        $actual = array_keys($request);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new \InvalidArgumentException('The Autosave request is invalid.');
        }
    }

    private function requireStrings(array $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (!\is_string($request[$key]) || $request[$key] === '') {
                throw new \InvalidArgumentException('The Autosave request is invalid.');
            }
        }
    }

    private function requirePositiveIntegers(array $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (!\is_int($request[$key]) || $request[$key] <= 0) {
                throw new \InvalidArgumentException('The Autosave request is invalid.');
            }
        }
    }
}
