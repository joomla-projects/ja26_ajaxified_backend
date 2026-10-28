<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Input\Input;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Draft-only operations and optional reconciliation after a native successful save.
 *
 * @since  __DEPLOY_VERSION__
 */
final class DraftService
{
    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly AutosaveContextResolver $resolver, private readonly DraftStore $store)
    {
    }

    /**
     * Open a draft for a prepared form with supported fields.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function open(User $user, array $descriptor, Date $now): array
    {
        if ($descriptor['fields'] === []) {
            throw new AutosaveException('unsupported_context', 'No supported protected controls are available.');
        }

        return $this->store->open((int) $user->id, $descriptor, $now);
    }

    /**
     * List private allocation headers for a quota-blocked native administrator form.
     *
     * @since __DEPLOY_VERSION__
     */
    public function allocations(User $user): array
    {
        if ($user->guest || (int) $user->id <= 0 || !$user->authorise('core.login.admin')) {
            throw new AutosaveException('forbidden', 'Private draft allocation metadata requires an authenticated administrator.');
        }

        $headers = $this->store->allocations((int) $user->id);
        foreach ($headers as &$header) {
            foreach (['created_at', 'updated_at', 'expires_at'] as $key) {
                $header[$key] = (new Date($header[$key], 'UTC'))->format('Y-m-d\TH:i:s\Z');
            }
        }
        unset($header);

        return $headers;
    }

    /**
     * Execute a request validated by the Autosave plugin.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function execute(string $operation, array $request, User $user, Date $now): array
    {
        $keys = match ($operation) {
            'preserve'          => ['draft_id', 'client_revision', 'schema_version', 'payload'],
            'read'              => ['draft_id', 'source_id'],
            'detect', 'discard' => ['draft_id'],
            default             => throw new \InvalidArgumentException('Unsupported draft operation.'),
        };

        $optional = $operation === 'detect' ? ['creation_id', 'acknowledged_id'] : [];

        if (
            array_diff_key($request, array_flip(array_merge($keys, $optional))) || array_diff_key(array_flip($keys), $request)
            || !\is_string($request['draft_id'])
        ) {
            throw new \InvalidArgumentException('Invalid draft envelope.');
        }

        $owner = (int) $user->id;
        $id    = $request['draft_id'];

        if ($operation === 'discard') {
            $this->store->discard($owner, $id);

            return ['discarded' => true];
        }

        if ($operation === 'preserve') {
            if (!\is_int($request['client_revision']) || !\is_int($request['schema_version'])) {
                throw new \InvalidArgumentException('Invalid draft sequence or schema.');
            }

            $conflict = false;
            $row      = $this->store->preserve(
                $owner,
                $id,
                $request['client_revision'],
                $request['payload'],
                $now,
                function (array $binding, mixed $payload) use ($user, $request, &$conflict): array {
                    $provider = $this->provider($binding['context']);

                    if ($request['schema_version'] !== $binding['schema_version']) {
                        throw new AutosaveException('unsupported_schema_version', 'The draft schema is incompatible.');
                    }

                    $payload = DraftProjection::normalize($payload, $binding['fields']);
                    $current = $provider->read($this->providerIdentity($binding), $user, $payload);

                    foreach (array_intersect_key($binding['fields'], $payload) as $key => $field) {
                        if (!isset($current['fields'][$key]) || $current['fields'][$key]['control'] !== $field['control']) {
                            throw new AutosaveException('schema_version_conflict', 'The protected controls changed. Reopen the native form.');
                        }
                    }

                    $conflict = $current['revision'] !== $binding['baseline_revision'];

                    if ($conflict && $binding['result_id'] === null) {
                        throw new AutosaveException('base_revision_conflict', 'The saved item changed. Recover explicitly in a fresh form.');
                    }

                    // After definite success without a specific result revision, retain later values
                    // against the earlier baseline and disclose conflict; never guess a new baseline.
                    return $payload;
                }
            );

            return [
                'client_revision' => $row['client_revision'],
                'updated_at'      => $row['updated_at'],
                'conflict'        => $conflict,
            ];
        }

        $binding = $this->store->read($owner, $id, $now);
        $current = $this->provider($binding['context'])->read($this->providerIdentity($binding), $user, $binding['payload']);

        if ($operation === 'detect') {
            $sources = null;

            if ($binding['target_id'] === null) {
                $sources = [];
                foreach (['creation_id', 'acknowledged_id'] as $key) {
                    if (!\array_key_exists($key, $request)) {
                        continue;
                    }
                    if (!\is_string($request[$key]) || preg_match('/^[a-f0-9]{32}$/D', $request[$key]) !== 1) {
                        throw new \InvalidArgumentException('Invalid creation recovery reference.');
                    }
                    $sources[] = $request[$key];
                }
                $sources = array_values(array_unique($sources));
            } elseif (isset($request['creation_id']) || isset($request['acknowledged_id'])) {
                throw new \InvalidArgumentException('Creation recovery references cannot change record discovery.');
            }

            $candidates = $this->store->find($owner, $binding, $now, $sources)['candidates'];

            if ($sources !== null) {
                $headers    = array_column($candidates, null, 'draft_id');
                $candidates = [];
                foreach ($sources as $sourceId) {
                    if (isset($headers[$sourceId])) {
                        $candidates[] = $headers[$sourceId];
                        break;
                    }
                }
            }

            foreach ($candidates as &$candidate) {
                $candidate['updated_at']         = (new Date($candidate['updated_at'], 'UTC'))->format('Y-m-d\TH:i:s\Z');
                $candidate['conflict']           = $candidate['baseline_revision'] !== $current['revision'];
                $candidate['uncertain_creation'] = $binding['target_id'] === null;
                unset($candidate['baseline_revision']);
            }

            return ['candidates' => $candidates, 'conflict' => $current['revision'] !== $binding['baseline_revision']];
        }

        if (!\is_string($request['source_id'])) {
            throw new \InvalidArgumentException('Invalid recovery source.');
        }

        $source = $this->store->read($owner, $request['source_id'], $now);

        if (
            $source['context'] !== $binding['context'] || $source['target_id'] !== $binding['target_id']
            || $source['scope'] !== $binding['scope'] || $source['payload'] === null
        ) {
            throw new AutosaveException('scope_conflict', 'The recovery source does not match this native form.');
        }

        if ($source['schema_version'] !== $binding['schema_version']) {
            throw new AutosaveException('unsupported_schema_version', 'The recovery source is incompatible.');
        }

        // Current access applies to the source too, including its proposed creation category.
        $sourceCurrent = $this->provider($source['context'])->read($this->providerIdentity($source), $user, $source['payload']);
        $eligible      = array_intersect_key($binding['fields'], $current['fields'], $sourceCurrent['fields'], $source['payload']);
        $payload       = [];
        $omitted       = [];

        foreach ($source['payload'] as $key => $value) {
            if (
                !isset($eligible[$key], $source['fields'][$key])
                || $eligible[$key]['control'] !== $source['fields'][$key]['control']
                || $eligible[$key]['control'] !== $current['fields'][$key]['control']
                || $eligible[$key]['control'] !== $sourceCurrent['fields'][$key]['control']
            ) {
                $omitted[] = $key;
                continue;
            }

            $payload[$key] = $value;
        }

        return [
            'source_revision'    => $source['client_revision'],
            'payload'            => $payload,
            'canonical'          => array_intersect_key($current['values'], $payload),
            'omitted'            => $omitted,
            'conflict'           => $source['baseline_revision'] !== $current['revision'],
            'uncertain_creation' => $source['target_id'] === null,
            'updated_at'         => $source['updated_at'],
        ];
    }

    /**
     * Called only by the native controller's successful path, never by a browser draft endpoint.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function reconcile(
        User $user,
        array $metadata,
        string $context,
        ?string $source,
        array $scope,
        string $task,
        string $result,
        ?string $revision,
        Date $now,
        bool $identityMayChange = false,
        ?array $resultScope = null
    ): void {
        if (
            array_diff_key($metadata, ['draft_id' => true, 'revision' => true, 'source_id' => true, 'source_revision' => true])
            || !isset($metadata['draft_id'], $metadata['revision']) || !\is_string($metadata['draft_id'])
            || !\is_string($metadata['revision']) || preg_match('/^[1-9][0-9]{0,15}$/D', $metadata['revision']) !== 1
            || (int) $metadata['revision'] > DraftStore::MAX_REVISION
        ) {
            throw new \InvalidArgumentException('Invalid optional draft metadata.');
        }

        $recovery = null;

        if (\array_key_exists('source_id', $metadata) || \array_key_exists('source_revision', $metadata)) {
            if (
                !isset($metadata['source_id'], $metadata['source_revision']) || !\is_string($metadata['source_id'])
                || !\is_string($metadata['source_revision']) || preg_match('/^[1-9][0-9]{0,15}$/D', $metadata['source_revision']) !== 1
                || (int) $metadata['source_revision'] > DraftStore::MAX_REVISION
            ) {
                throw new \InvalidArgumentException('Invalid optional recovered snapshot metadata.');
            }

            $recovery = ['draft_id' => $metadata['source_id'], 'revision' => (int) $metadata['source_revision']];
        }

        $this->store->reconcile(
            (int) $user->id,
            $metadata['draft_id'],
            (int) $metadata['revision'],
            $context,
            $source,
            $scope,
            $task,
            $result,
            $revision,
            $now,
            $recovery,
            $identityMayChange,
            $resultScope
        );
    }

    /**
     * Absence or failure of draft storage cannot alter native controller success or navigation.
     *
     * @since  __DEPLOY_VERSION__
     */
    public static function reconcileNativeSave(
        CMSApplicationInterface $application,
        Input $input,
        string $context,
        ?string $source,
        array $scope,
        ?string $task,
        string $result,
        ?string $revision = null,
        bool $identityMayChange = false,
        array $completedScopeKeys = [],
        array $changedScopeKeys = []
    ): void {
        if ($task === null || $task === '') {
            return;
        }

        $metadata = $input->post->get('autosave', [], 'array');

        if ($metadata === []) {
            return;
        }

        try {
            $service     = Factory::getContainer()->get(self::class);
            $resultScope = null;
            // Native success may complete a blank creation or change explicitly declared scope keys.
            if (($source === null && $completedScopeKeys !== []) || ($source !== null && $changedScopeKeys !== [])) {
                $binding = $service->store->read((int) $application->getIdentity()->id, $metadata['draft_id'], new Date('now', 'UTC'));
                if ($binding['context'] === $context && $binding['target_id'] === $source && array_keys($binding['scope']) === array_keys($scope)) {
                    $compatible = true;
                    foreach ($scope as $name => $value) {
                        if (
                            $binding['scope'][$name] !== $value
                            && ($source === null ? (!\in_array($name, $completedScopeKeys, true) || !\in_array($binding['scope'][$name], ['', '0', null], true)) : !\in_array($name, $changedScopeKeys, true))
                        ) {
                            $compatible = false;
                        }
                    }
                    if ($compatible) {
                        $resultScope = $scope;
                        $scope       = $binding['scope'];
                    }
                }
            }
            $service->reconcile(
                $application->getIdentity(),
                $metadata,
                $context,
                $source,
                $scope,
                $task,
                $result,
                $revision,
                new Date('now', 'UTC'),
                $identityMayChange,
                $resultScope
            );
        } catch (AutosaveException | \InvalidArgumentException) {
            // Ignore missing or incompatible draft metadata after a successful save.
        } catch (\Throwable $exception) {
            try {
                // No payload, request, stack trace or exception text belongs in normal logs.
                $application->getLogger()->warning('Optional draft reconciliation failed.', ['exception_class' => $exception::class]);
            } catch (\Throwable) {
                // Keep the successful save if logging fails.
            }
        }
    }


    /**
     * Prepare the saved item's scope and reconcile its submitted draft.
     *
     * @since  __DEPLOY_VERSION__
     */
    public static function reconcileNativeModelSave(
        CMSApplicationInterface $application,
        Input $input,
        \Joomla\CMS\MVC\Model\BaseDatabaseModel $model,
        string $context,
        ?string $task,
        string $key = 'id',
        array $completedScopeKeys = [],
        array $changedScopeKeys = []
    ): void {
        if ($task === null || $task === '' || $input->post->get('autosave', [], 'array') === []) {
            return;
        }
        try {
            $result = (int) $model->getState($model->getName() . '.id');
            if ($result <= 0) {
                return;
            }
            $item     = $model->getItem($result);
            $form     = $model->getForm((array) $item, false);
            $provider = $application->bootComponent(AutosaveContext::getComponentName($context))->getAutosaveProvider($context);
            if (!$form || !$provider instanceof DraftProviderInterface) {
                return;
            }
            $descriptor = $provider->describe($form, $item, $application->getIdentity());
            if ($descriptor['target'] !== (string) $result) {
                return;
            }
            self::reconcileNativeSave(
                $application,
                $input,
                $context,
                $input->getInt($key) > 0 ? (string) $input->getInt($key) : null,
                $descriptor['scope'],
                $task,
                (string) $result,
                // This read proves current scope, not the exact revision committed by this save.
                // Preserve the original baseline for later edits rather than attributing another writer.
                null,
                false,
                $completedScopeKeys,
                $changedScopeKeys
            );
        } catch (AutosaveException) {
            // Keep the successful save if draft preparation fails.
        } catch (\Throwable $exception) {
            try {
                $application->getLogger()->warning('Optional draft save preparation failed.', ['exception_class' => $exception::class]);
            } catch (\Throwable) {
                // Keep the successful save if logging fails.
            }
        }
    }

    /**
     * Return only the native identity needed by a component provider.
     *
     * @return array{context: string, target_id: ?string, scope: array}
     *
     * @since __DEPLOY_VERSION__
     */
    private function providerIdentity(array $binding): array
    {
        return [
            'context'   => $binding['context'],
            'target_id' => $binding['target_id'],
            'scope'     => $binding['scope'],
        ];
    }

    private function provider(string $context): DraftProviderInterface
    {
        $provider = $this->resolver->resolve($context);

        if (!$provider instanceof DraftProviderInterface) {
            throw new AutosaveException('unsupported_context', 'This context has no native draft provider.');
        }

        return $provider;
    }
}
