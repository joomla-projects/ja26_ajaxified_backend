<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One owner-private editing instance per row; canonical records are never written here.
 *
 * @since  __DEPLOY_VERSION__
 */
final class DraftStore
{
    public const MAX_REVISION = 9007199254740991;

    public const DISCOVERY_PAGE_SIZE = 100;

    public const PURGE_BATCH_SIZE = 100;

    public const POLICY = [
        'max_payload_bytes' => 4194304,
        'empty_ttl'         => 3600,
        'idle_ttl'          => 604800,
        'absolute_ttl'      => 2592000,
        'tombstone_ttl'     => 86400,
    ];

    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly DatabaseInterface $db, private readonly array $policy = self::POLICY)
    {
        foreach (self::POLICY as $key => $default) {
            if (!isset($policy[$key]) || !\is_int($policy[$key]) || $policy[$key] <= 0) {
                throw new \InvalidArgumentException('Invalid draft storage policy.');
            }
        }
    }

    /**
     * Allocate only during authorized native rendering; preserve never performs an upsert.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function open(int $owner, array $descriptor, Date $now): array
    {
        return $this->transaction($owner, function () use ($owner, $descriptor, $now): array {
            $row = $this->newRow($owner, $descriptor, $now, $now);
            $this->db->insertObject('#__autosave_drafts', $row);

            return $this->decode((array) $row);
        });
    }

    /**
     * Render provenance expires no later than a terminal tombstone can be purged.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getAdmissionExpiry(Date $rendered): string
    {
        return $this->deadline($rendered, min($this->policy['tombstone_ttl'], $this->policy['absolute_ttl']));
    }

    /**
     * Resume only an unused render, using metadata obtained from the native server session.
     * Raw expired reads and ordinary preservation still fail closed.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function resume(int $owner, string $id, array $admission, Date $now, callable $validate): array
    {
        return $this->transaction($owner, function () use ($owner, $id, $admission, $now, $validate): array {
            $rendered = $this->validateAdmission($owner, $id, $admission, $now);
            try {
                $row = $this->loadRow($owner, $id);
            } catch (AutosaveException $exception) {
                if ($exception->getErrorCode() !== 'draft_not_found') {
                    throw $exception;
                }
                $newRow = $this->newRow($owner, $admission['descriptor'], $rendered, $now, $id);
                $row    = $this->decode((array) $newRow);
                $validate($row);
                $this->db->insertObject('#__autosave_drafts', $newRow);

                return $row;
            }
            if ($row['retired_revision'] > 0 || $row['result_id'] !== null) {
                throw new AutosaveException('draft_retired', 'This editing instance was retired.');
            }
            if ($row['client_revision'] !== 0 || $row['payload'] !== null) {
                throw new AutosaveException('draft_expired', 'Only unused render admission can be resumed.');
            }
            if (
                $row['context'] !== $admission['descriptor']['context'] || $row['target_id'] !== $admission['descriptor']['target']
                || $row['scope'] !== $admission['descriptor']['scope'] || $row['fields'] !== $admission['descriptor']['fields']
                || $row['baseline_revision'] !== $admission['descriptor']['revision']
                || $row['created_at'] !== $admission['rendered_at'] || $row['schema_version'] !== $admission['descriptor']['version']
            ) {
                throw new AutosaveException('scope_conflict', 'The render admission does not match this instance.');
            }
            $validate($row);
            if ($row['expires_at'] <= $now->toSql()) {
                $old               = $row;
                $row['expires_at'] = min($this->deadline($now, $this->policy['empty_ttl']), $this->deadline($rendered, $this->policy['absolute_ttl']));
                $this->update($row, $old, $now, null, true);
            }

            return $row;
        });
    }

    /**
     * Read an unexpired owner-bound instance.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function read(int $owner, string $id, Date $now): array
    {
        $row = $this->loadRow($owner, $id);
        if ($row['expires_at'] <= $now->toSql()) {
            throw new AutosaveException('draft_expired', 'The draft has expired.');
        }

        return $row;
    }

    /**
     * Missing and foreign IDs are intentionally indistinguishable.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function loadRow(int $owner, string $id): array
    {
        if ($owner <= 0 || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new AutosaveException('draft_not_found', 'The draft was not found.');
        }

        $query = $this->db->createQuery()->select('*')
            ->from($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('owner_id') . ' = :owner')
            ->where($this->db->quoteName('draft_id') . ' = :draft')
            ->bind(':owner', $owner, ParameterType::INTEGER)
            ->bind(':draft', $id);
        $row = $this->db->setQuery($query)->loadAssoc();

        if (!$row) {
            throw new AutosaveException('draft_not_found', 'The draft was not found.');
        }

        return $this->decode($row);
    }

    /**
     * Return one page of headers; payloads are read after an explicit recovery choice.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function find(int $owner, array $binding, Date $now, ?string $cursor = null, ?array $sources = null): array
    {
        if ($sources === []) {
            return ['candidates' => [], 'next' => null];
        }

        $context = $binding['context'];
        $scope   = json_encode($binding['scope'], JSON_THROW_ON_ERROR);
        $target  = $binding['target_id'];
        $id      = $binding['draft_id'];
        $instant = $now->toSql();
        $query   = $this->db->createQuery()
            ->select($this->db->quoteName(['draft_id', 'baseline_revision', 'updated_at', 'schema_version']))
            ->from($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('owner_id') . ' = :owner')
            ->where($this->db->quoteName('context') . ' = :context')
            ->where($this->db->quoteName('scope') . ' = :scope')
            ->where($this->db->quoteName('expires_at') . ' > :now')
            ->where($target === null ? $this->db->quoteName('target_id') . ' IS NULL'
                : $this->db->quoteName('target_id') . ' = :target')
            ->where($this->db->quoteName('draft_id') . ' <> :draft')
            ->where($this->db->quoteName('payload') . ' IS NOT NULL')
            ->order($this->db->quoteName('updated_at') . ' DESC, ' . $this->db->quoteName('draft_id'))
            ->bind(':owner', $owner, ParameterType::INTEGER)->bind(':context', $context)
            ->bind(':scope', $scope)->bind(':draft', $id)->bind(':now', $instant);

        if ($target !== null) {
            $query->bind(':target', $target);
        }

        if ($sources !== null) {
            $query->whereIn($this->db->quoteName('draft_id'), $sources, ParameterType::STRING);
        }

        if ($cursor !== null) {
            if (preg_match('/^[a-f0-9]{32}$/D', $cursor) !== 1) {
                throw new AutosaveException('invalid_request', 'Invalid draft discovery cursor.');
            }

            // Use only an eligible owner's header as the cursor; never load a payload for paging.
            $cursorQuery = clone $query;
            $cursorQuery->where($this->db->quoteName('draft_id') . ' = :cursor')->bind(':cursor', $cursor);
            $header = $this->db->setQuery($cursorQuery, 0, 1)->loadAssoc();

            if (!$header) {
                throw new AutosaveException('invalid_request', 'Invalid draft discovery cursor.');
            }

            $before = $header['updated_at'];
            $query->where('(' . $this->db->quoteName('updated_at') . ' < :before OR ('
                . $this->db->quoteName('updated_at') . ' = :before AND '
                . $this->db->quoteName('draft_id') . ' > :cursor))')
                ->bind(':before', $before)->bind(':cursor', $cursor);
        }

        $headers = $this->db->setQuery($query, 0, self::DISCOVERY_PAGE_SIZE + 1)->loadAssocList();
        $next    = null;

        if (\count($headers) > self::DISCOVERY_PAGE_SIZE) {
            array_pop($headers);
            $next = $headers[array_key_last($headers)]['draft_id'];
        }

        return ['candidates' => $headers, 'next' => $next];
    }

    /**
     * Recheck authority inside the same owner-serialized mutation as revision retirement.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function preserve(int $owner, string $id, int $revision, mixed $payload, Date $now, callable $validate): array
    {
        $this->checkRevision($revision);

        return $this->transaction($owner, function () use ($owner, $id, $revision, $payload, $now, $validate): array {
            $row = $this->read($owner, $id, $now);

            if ($revision <= $row['retired_revision']) {
                throw new AutosaveException('draft_retired', 'This draft revision has been retired.');
            }

            $payload = $validate($row, $payload);
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (\strlen($encoded) > $this->policy['max_payload_bytes']) {
                throw new AutosaveException('payload_too_large', 'The draft is too large. Ordinary saving remains available.');
            }

            $digest = hash('sha256', $encoded);

            if ($revision < $row['client_revision'] || ($revision === $row['client_revision'] && $digest !== $row['payload_digest'])) {
                throw new AutosaveException('revision_conflict', 'A different or newer draft revision is already preserved.');
            }

            if ($revision === $row['client_revision']) {
                return $row;
            }

            $old                    = $row;
            $row['client_revision'] = $revision;
            $row['payload']         = $payload;
            $row['payload_digest']  = $digest;
            $row['updated_at']      = $now->toSql();
            $row['expires_at']      = min(
                $this->deadline($now, $this->policy['idle_ttl']),
                $this->deadline(new Date($row['created_at'], 'UTC'), $this->policy['absolute_ttl'])
            );
            $this->update($row, $old, $now, $encoded);

            return $row;
        });
    }

    /**
     * A successful native task retires a sequence, never an entire tab's future edits.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function reconcile(
        int $owner,
        string $id,
        int $revision,
        string $context,
        ?string $source,
        array $scope,
        string $task,
        string $result,
        ?string $resultRevision,
        Date $now,
        ?array $recovery = null,
        bool $identityMayChange = false,
        ?array $resultScope = null,
        ?array $admission = null
    ): void {
        $this->checkRevision($revision);

        if (!\in_array($task, ['apply', 'save', 'save2new', 'save2copy', 'save2menu', 'save2menulist', 'save2menublog'], true) || $result === '') {
            throw new AutosaveException('invalid_request', 'The native result cannot reconcile this draft.');
        }

        if ($recovery !== null) {
            if (!isset($recovery['draft_id'], $recovery['revision']) || !\is_string($recovery['draft_id']) || !\is_int($recovery['revision'])) {
                throw new AutosaveException('invalid_request', 'Invalid recovered snapshot metadata.');
            }

            $this->checkRevision($recovery['revision']);
        }

        $this->transaction($owner, function () use ($owner, $id, $revision, $context, $source, $scope, $task, $result, $resultRevision, $now, $recovery, $identityMayChange, $resultScope, $admission): void {
            $row = $this->retirementRow($owner, $id, $now, $admission);

            if (
                $row['context'] !== $context || $row['target_id'] !== $source || $row['scope'] !== $scope
                || ($source !== null && $task !== 'save2copy' && !$identityMayChange && $result !== $source)
            ) {
                throw new AutosaveException('scope_conflict', 'The native submission does not match the draft binding.');
            }

            $recovered = null;

            if ($recovery !== null) {
                $recovered = $this->read($owner, $recovery['draft_id'], $now);

                if (
                    $recovered['draft_id'] === $id || $recovered['context'] !== $context
                    || $recovered['target_id'] !== $source || $recovered['scope'] !== $scope
                    || $recovered['schema_version'] !== $row['schema_version']
                ) {
                    throw new AutosaveException('scope_conflict', 'The recovered snapshot does not match this submission.');
                }

                if ($recovery['revision'] > $recovered['client_revision']) {
                    throw new AutosaveException('invalid_request', 'The recovered sequence was not preserved.');
                }

                foreach ($recovered['payload'] ?? [] as $key => $value) {
                    if (!isset($row['fields'][$key]) || $row['fields'][$key]['control'] !== $recovered['fields'][$key]['control']) {
                        throw new AutosaveException('schema_version_conflict', 'The recovered controls are incompatible.');
                    }
                }
            }

            $old              = $row;
            $row['result_id'] = $result;

            if ($task !== 'save2copy') {
                $row['target_id'] = $result;
                if ($resultScope !== null) {
                    $row['scope'] = $resultScope;
                }

                // A later read could belong to another writer. Only use a known native result.
                if ($resultRevision !== null) {
                    $row['baseline_revision'] = $resultRevision;
                }
            }

            $this->retire($row, $revision, $now);
            $this->update($row, $old, $now, null, true);

            if ($recovered !== null) {
                $oldSource              = $recovered;
                $recovered['result_id'] = $result;
                if ($task !== 'save2copy') {
                    $recovered['target_id'] = $result;
                    if ($resultScope !== null) {
                        $recovered['scope'] = $resultScope;
                    }
                    if ($resultRevision !== null) {
                        $recovered['baseline_revision'] = $resultRevision;
                    }
                }
                $this->retire($recovered, $recovery['revision'], $now);
                $this->update($recovered, $oldSource, $now);
            }
        });
    }

    /**
     * Owner-only discard remains available after component access is revoked.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function discard(int $owner, string $id, Date $now, ?array $admission = null): void
    {
        $this->transaction($owner, function () use ($owner, $id, $now, $admission): void {
            $row = $this->retirementRow($owner, $id, $now, $admission);
            $old = $row;

            if ($row['retired_revision'] === self::MAX_REVISION) {
                return;
            }

            $row['retired_revision'] = self::MAX_REVISION;
            $row['payload']          = null;
            $row['payload_digest']   = null;
            $row['expires_at']       = $this->deadline($now, $this->policy['tombstone_ttl']);
            $this->update($row, $old, $now, null, true);
        });
    }

    /**
     * One physical cleanup batch; the native scheduler supplies the run budget.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function purge(Date $now, int $limit = self::PURGE_BATCH_SIZE): int
    {
        if ($limit < 1 || $limit > self::PURGE_BATCH_SIZE) {
            throw new \InvalidArgumentException('Invalid draft cleanup batch.');
        }

        $instant = $now->toSql();
        $query   = $this->db->createQuery()->select($this->db->quoteName(['draft_id', 'owner_id']))
            ->from($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('expires_at') . ' <= :now')
            ->order($this->db->quoteName('expires_at'))->bind(':now', $instant);
        $expired = $this->db->setQuery($query, 0, $limit)->loadAssocList();
        $count   = 0;

        foreach ($expired as $row) {
            $count += $this->transaction((int) $row['owner_id'], function () use ($row, $instant): int {
                $id    = $row['draft_id'];
                $query = $this->db->createQuery()->delete($this->db->quoteName('#__autosave_drafts'))
                    ->where($this->db->quoteName('draft_id') . ' = :draft')
                    ->where($this->db->quoteName('expires_at') . ' <= :now')
                    ->bind(':draft', $id)->bind(':now', $instant);
                $this->db->setQuery($query)->execute();

                return $this->db->getAffectedRows();
            }, true);
        }

        return $count;
    }

    /**
     * Let the native Scheduler request continuation without reading expired payloads.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function hasExpired(Date $now): bool
    {
        $instant = $now->toSql();
        $query   = $this->db->createQuery()->select('1')
            ->from($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('expires_at') . ' <= :now')->bind(':now', $instant);

        return (bool) $this->db->setQuery($query, 0, 1)->loadResult();
    }

    private function transaction(int $owner, callable $operation, bool $allowMissingOwner = false): mixed
    {
        if ($owner <= 0) {
            throw new AutosaveException('forbidden', 'Draft storage requires an authenticated owner.');
        }

        $this->db->transactionStart();

        try {
            // Lock an existing native owner row to serialize even admission to an empty draft set.
            // SQLite serializes writers itself; the two supported core engines use row locks.
            $query = $this->db->createQuery()->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__users'))
                ->where($this->db->quoteName('id') . ' = :owner')->bind(':owner', $owner, ParameterType::INTEGER);
            $sql = (string) $query;

            if ($this->db->getServerType() !== 'sqlite') {
                $sql .= ' FOR UPDATE';
            }

            $query = $this->db->createQuery()->setQuery($sql)->bind(':owner', $owner, ParameterType::INTEGER);

            if (!$this->db->setQuery($query)->loadResult() && !$allowMissingOwner) {
                throw new AutosaveException('forbidden', 'Draft storage requires an existing owner.');
            }

            $result = $operation();
            $this->db->transactionCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transactionRollback();

            throw $exception;
        }
    }

    private function retire(array &$row, int $revision, Date $now): void
    {
        $row['retired_revision'] = max($row['retired_revision'], $revision);

        if ($row['client_revision'] <= $row['retired_revision']) {
            $row['payload']        = null;
            $row['payload_digest'] = null;
            $row['expires_at']     = $this->deadline($now, $this->policy['tombstone_ttl']);
        }
    }

    private function update(array $row, array $old, Date $now, ?string $encoded = null, bool $allowExpired = false): void
    {
        $query  = $this->db->createQuery()->update($this->db->quoteName('#__autosave_drafts'));
        $values = [
            'target_id'         => $row['target_id'],
            'scope'             => json_encode($row['scope'], JSON_THROW_ON_ERROR),
            'baseline_revision' => $row['baseline_revision'],
            'client_revision'   => $row['client_revision'],
            'retired_revision'  => $row['retired_revision'],
            'payload_digest'    => $row['payload_digest'],
            'result_id'         => $row['result_id'],
            'updated_at'        => $row['updated_at'],
            'expires_at'        => $row['expires_at'],
        ];

        if ($row['payload_digest'] !== $old['payload_digest']) {
            $values['payload'] = $row['payload'] === null ? null : $encoded;
        }

        foreach ($values as $column => &$value) {
            $query->set($this->db->quoteName($column) . ' = :set_' . $column)
                ->bind(':set_' . $column, $value, $value === null ? ParameterType::NULL
                    : (\is_int($value) ? ParameterType::INTEGER : ParameterType::STRING));
        }

        unset($value);
        $id       = $old['draft_id'];
        $owner    = $old['owner_id'];
        $previous = $old['client_revision'];
        $floor    = $old['retired_revision'];
        $instant  = $now->toSql();
        $query->where($this->db->quoteName('draft_id') . ' = :draft')
            ->where($this->db->quoteName('owner_id') . ' = :owner')
            ->where($this->db->quoteName('client_revision') . ' = :previous')
            ->where($this->db->quoteName('retired_revision') . ' = :floor')
            ->bind(':draft', $id)->bind(':owner', $owner, ParameterType::INTEGER)
            ->bind(':previous', $previous, ParameterType::INTEGER)->bind(':floor', $floor, ParameterType::INTEGER);
        if (!$allowExpired) {
            $query->where($this->db->quoteName('expires_at') . ' > :now')->bind(':now', $instant);
        }
        $this->db->setQuery($query)->execute();

        // Equal reconciliation is idempotent, including MySQL's zero changed-row result.
        if ($this->db->getAffectedRows() === 0) {
            $current = $this->read($owner, $id, $now);

            if ($current['client_revision'] !== $row['client_revision'] || $current['retired_revision'] !== $row['retired_revision']) {
                throw new AutosaveException('revision_conflict', 'The draft changed during reconciliation.');
            }
        }
    }

    private function newRow(int $owner, array $descriptor, Date $rendered, Date $now, ?string $id = null): object
    {
        $instant = $now->toSql();
        return (object) [
            'draft_id'          => $id ?? bin2hex(random_bytes(16)),
            'owner_id'          => $owner,
            'context'           => $descriptor['context'],
            'target_id'         => $descriptor['target'],
            'scope'             => json_encode($descriptor['scope'], JSON_THROW_ON_ERROR),
            'schema_version'    => $descriptor['version'],
            'fields'            => json_encode($descriptor['fields'], JSON_THROW_ON_ERROR),
            'baseline_revision' => $descriptor['revision'],
            'client_revision'   => 0,
            'retired_revision'  => 0,
            'payload'           => null,
            'payload_digest'    => null,
            'result_id'         => null,
            'created_at'        => $rendered->toSql(),
            'updated_at'        => $instant,
            'expires_at'        => min($this->deadline($now, $this->policy['empty_ttl']), $this->deadline($rendered, $this->policy['absolute_ttl'])),
        ];
    }

    private function validateAdmission(int $owner, string $id, ?array $admission, Date $now): Date
    {
        if (
            $admission === null || ($admission['owner_id'] ?? null) !== $owner
            || ($admission['draft_id'] ?? null) !== $id || !isset($admission['descriptor'], $admission['rendered_at'])
            || !\is_array($admission['descriptor']) || !\is_string($admission['rendered_at'])
            || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
        ) {
            throw new AutosaveException('render_admission_missing', 'Trusted render admission is unavailable.');
        }
        $rendered = new Date($admission['rendered_at'], 'UTC');
        if ($rendered->toSql() > $now->toSql() || $this->getAdmissionExpiry($rendered) <= $now->toSql()) {
            throw new AutosaveException('render_admission_expired', 'Trusted render admission has expired.');
        }

        return $rendered;
    }

    private function retirementRow(int $owner, string $id, Date $now, ?array $admission): array
    {
        try {
            return $this->loadRow($owner, $id);
        } catch (AutosaveException $exception) {
            if ($exception->getErrorCode() !== 'draft_not_found' || $admission === null) {
                throw $exception;
            }
        }
        $rendered = $this->validateAdmission($owner, $id, $admission, $now);
        $row      = $this->newRow($owner, $admission['descriptor'], $rendered, $now, $id);
        $this->db->insertObject('#__autosave_drafts', $row);

        return $this->decode((array) $row);
    }

    private function decode(array $row): array
    {
        foreach (['owner_id', 'schema_version', 'client_revision', 'retired_revision'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        foreach (['scope', 'fields'] as $key) {
            $row[$key] = json_decode($row[$key], true, 32, JSON_THROW_ON_ERROR);
        }

        $row['payload'] = $row['payload'] === null ? null : json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);

        return $row;
    }

    private function checkRevision(int $revision): void
    {
        if ($revision < 1 || $revision > self::MAX_REVISION) {
            throw new AutosaveException('invalid_request', 'The draft sequence is invalid.');
        }
    }

    private function deadline(Date $now, int $seconds): string
    {
        return (new Date('@' . ($now->getTimestamp() + $seconds), 'UTC'))->toSql();
    }
}
