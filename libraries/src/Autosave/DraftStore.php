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
use Joomla\Database\Exception\ExecutionFailureException;
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
    /** @since  __DEPLOY_VERSION__ */
    public const MAX_REVISION = 9007199254740991;

    /** @since  __DEPLOY_VERSION__ */
    public const DISCOVERY_PAGE_SIZE = 100;

    /** @since  __DEPLOY_VERSION__ */
    public const PURGE_BATCH_SIZE = 100;

    /** @since  __DEPLOY_VERSION__ */
    public const MAX_OWNER_ROWS = 64;

    private const MAX_WRITE_ATTEMPTS = 4;

    /** @since  __DEPLOY_VERSION__ */
    public const POLICY = [
        'max_payload_bytes' => 4194304,
        'empty_ttl'         => 86400,
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
        $this->assertAutocommit();
        $query = $this->db->createQuery()->select('1')->from($this->db->quoteName('#__users'))
            ->where($this->db->quoteName('id') . ' = :owner')->bind(':owner', $owner, ParameterType::INTEGER);
        if ($owner <= 0 || !$this->db->setQuery($query)->loadResult()) {
            throw new AutosaveException('forbidden', 'Draft storage requires an existing owner.');
        }

        // A live empty row still belongs to its own form. Only recycle expired, unused work.
        $instant = $now->toSql();
        $query   = $this->db->createQuery()->select('*')->from($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('owner_id') . ' = :owner')
            ->where($this->db->quoteName('expires_at') . ' <= :now')
            ->where($this->db->quoteName('client_revision') . ' = 0')
            ->where($this->db->quoteName('retired_revision') . ' = 0')
            ->where($this->db->quoteName('payload') . ' IS NULL')
            ->where($this->db->quoteName('result_id') . ' IS NULL')
            ->bind(':owner', $owner, ParameterType::INTEGER)->bind(':now', $instant);
        foreach ($this->db->setQuery($query, 0, self::MAX_OWNER_ROWS)->loadAssocList() as $candidate) {
            $old = $this->decode($candidate);
            $row = (array) $this->newRow($owner, (int) $old['owner_slot'], $descriptor, $now);
            if ($this->binding($old) !== $this->binding($this->decode($row))) {
                continue;
            }
            if ($this->recycle($row, $old, $now)) {
                return $this->loadRow($owner, $row['draft_id']);
            }
        }

        // The unique owner/slot key arbitrates concurrent allocations; COUNT then INSERT cannot.
        for ($attempt = 0; $attempt < self::MAX_OWNER_ROWS; $attempt++) {
            $query = $this->db->createQuery()->select($this->db->quoteName(['draft_id', 'owner_id', 'owner_slot', 'row_version', 'expires_at']))
                ->from($this->db->quoteName('#__autosave_drafts'))
                ->where($this->db->quoteName('owner_id') . ' = :owner')->bind(':owner', $owner, ParameterType::INTEGER);
            $occupied = [];
            foreach ($this->db->setQuery($query)->loadAssocList() as $existing) {
                if ($existing['expires_at'] <= $instant && $this->deleteExpired($existing, $now)) {
                    continue;
                }
                $occupied[(int) $existing['owner_slot']] = true;
            }
            $free = array_values(array_diff(range(0, self::MAX_OWNER_ROWS - 1), array_keys($occupied)));
            if ($free === []) {
                throw new AutosaveException('draft_limit_reached', 'The private draft limit has been reached. Save normally or discard an older draft.');
            }
            $row = $this->newRow($owner, $free[0], $descriptor, $now);
            $this->assertAutocommit();
            try {
                $this->db->insertObject('#__autosave_drafts', $row);
                return $this->loadRow($owner, $row->draft_id);
            } catch (ExecutionFailureException $exception) {
                if (!\in_array((string) $exception->getCode(), ['1062', '23505'], true)) {
                    throw $exception;
                }
                // Retry only a competing slot claim, never an unrelated unique key or SQL error.
                $query = $this->db->createQuery()->select($this->db->quoteName('draft_id'))
                    ->from($this->db->quoteName('#__autosave_drafts'))
                    ->where($this->db->quoteName('owner_id') . ' = :owner')
                    ->where($this->db->quoteName('owner_slot') . ' = :slot')
                    ->bind(':owner', $owner, ParameterType::INTEGER)->bind(':slot', $row->owner_slot, ParameterType::INTEGER);
                $winner = $this->db->setQuery($query)->loadResult();
                if (!$winner || $winner === $row->draft_id) {
                    throw $exception;
                }
            }
        }
        throw new AutosaveException('service_unavailable', 'Draft allocation is busy. Ordinary saving remains available.');
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
     * Validate outside the write and acknowledge only a matching conditional update or retry.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function preserve(int $owner, string $id, int $revision, mixed $payload, Date $now, callable $validate): array
    {
        $this->checkRevision($revision);
        $this->assertAutocommit();
        $original = null;
        for ($attempt = 0; $attempt < self::MAX_WRITE_ATTEMPTS; $attempt++) {
            $row              = $this->read($owner, $id, $now);
            $absoluteDeadline = $this->deadline(new Date($row['created_at'], 'UTC'), $this->policy['absolute_ttl']);
            if ($absoluteDeadline <= $now->toSql()) {
                throw new AutosaveException('draft_expired', 'The draft has expired.');
            }
            if ($revision <= $row['retired_revision']) {
                throw new AutosaveException('draft_retired', 'This draft revision has been retired.');
            }
            $binding = $this->binding($row);
            if ($original !== null && $original !== $binding) {
                throw new AutosaveException('revision_conflict', 'The draft binding changed during preservation.');
            }
            $original ??= $binding;
            $normalized = $validate($row, $payload);
            $this->assertAutocommit();
            $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (\strlen($encoded) > $this->policy['max_payload_bytes']) {
                throw new AutosaveException('payload_too_large', 'The draft is too large. Ordinary saving remains available.');
            }
            $digest = hash('sha256', $encoded);
            if ($revision < $row['client_revision'] || ($revision === $row['client_revision'] && $digest !== $row['payload_digest'])) {
                throw new AutosaveException('revision_conflict', 'A different or newer draft revision is already preserved.');
            }
            if ($revision === $row['client_revision']) {
                $confirmed = $this->read($owner, $id, $now);
                if ($confirmed['row_version'] === $row['row_version']) {
                    return $confirmed;
                }
                continue;
            }
            $old                    = $row;
            $row['client_revision'] = $revision;
            $row['payload']         = $normalized;
            $row['payload_digest']  = $digest;
            $row['updated_at']      = $now->toSql();
            $row['expires_at']      = min(
                $this->deadline($now, $this->policy['idle_ttl']),
                $absoluteDeadline
            );
            if ($this->update($row, $old, $now, $encoded)) {
                return $row;
            }
        }
        throw new AutosaveException('revision_conflict', 'The draft changed during preservation.');
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
        ?array $resultScope = null
    ): void {
        $this->checkRevision($revision);

        if (!\in_array($task, ['apply', 'save', 'save2new', 'save2copy', 'save2menu', 'save2menulist', 'save2menublog', 'editAssociations'], true) || $result === '') {
            throw new AutosaveException('invalid_request', 'The native result cannot reconcile this draft.');
        }

        if ($recovery !== null) {
            if (!isset($recovery['draft_id'], $recovery['revision']) || !\is_string($recovery['draft_id']) || !\is_int($recovery['revision'])) {
                throw new AutosaveException('invalid_request', 'Invalid recovered snapshot metadata.');
            }

            $this->checkRevision($recovery['revision']);
        }

        $this->assertAutocommit();
        for ($attempt = 0; $attempt < self::MAX_WRITE_ATTEMPTS; $attempt++) {
            try {
                $row = $this->loadRow($owner, $id);
            } catch (AutosaveException $exception) {
                if ($exception->getErrorCode() === 'draft_not_found') {
                    return;
                }
                throw $exception;
            }
            // An older completion cannot replace a later successful result or extend its floor.
            $alreadyRetired = $row['retired_revision'] >= $revision;
            if ($source !== null && $task !== 'save2copy' && !$identityMayChange && $result !== $source) {
                throw new AutosaveException('scope_conflict', 'The native result does not match its source.');
            }
            if (
                !$alreadyRetired
                && ($row['context'] !== $context || $row['target_id'] !== $source || $row['scope'] !== $scope)
            ) {
                throw new AutosaveException('scope_conflict', 'The native submission does not match the draft binding.');
            }
            if (
                $alreadyRetired
                && (
                    $row['context'] !== $context || $row['result_id'] !== $result
                    || $row['target_id'] !== ($task === 'save2copy' ? $source : $result)
                    || $row['scope'] !== ($task === 'save2copy' ? $scope : ($resultScope ?? $scope))
                )
            ) {
                throw new AutosaveException('scope_conflict', 'The native result does not match the retired draft.');
            }
            $recovered = $this->recoveryRow($owner, $recovery, $row, $context, $source, $scope, $now);
            if (!$alreadyRetired) {
                $old = $row;
                $this->nativeResult($row, $revision, $task, $result, $resultRevision, $resultScope, $now);
                if (!$this->update($row, $old, $now, null, true)) {
                    continue;
                }
            }

            // Native success and current retirement stand even if optional source cleanup fails.
            for ($sourceAttempt = 0; $recovered !== null && $sourceAttempt < self::MAX_WRITE_ATTEMPTS; $sourceAttempt++) {
                if ($recovered['retired_revision'] >= $recovery['revision']) {
                    return;
                }
                $oldSource = $recovered;
                $this->nativeResult($recovered, $recovery['revision'], $task, $result, $resultRevision, $resultScope, $now);
                if ($this->update($recovered, $oldSource, $now)) {
                    return;
                }
                $recovered = $this->recoveryRow($owner, $recovery, $row, $context, $source, $scope, $now);
            }
            if ($recovered !== null) {
                throw new AutosaveException('revision_conflict', 'The recovered draft changed during reconciliation.');
            }
            return;
        }
        throw new AutosaveException('revision_conflict', 'The draft changed during reconciliation.');
    }

    /**
     * Owner-only physical discard cannot be undone by a delayed preserve UPDATE.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function discard(int $owner, string $id, Date $now): void
    {
        $this->assertAutocommit();
        for ($attempt = 0; $attempt < self::MAX_WRITE_ATTEMPTS; $attempt++) {
            $row   = $this->loadRow($owner, $id);
            $query = $this->mutation($row)->delete($this->db->quoteName('#__autosave_drafts'));
            $this->db->setQuery($query)->execute();
            if ($this->db->getAffectedRows() === 1) {
                return;
            }
        }
        throw new AutosaveException('revision_conflict', 'The draft changed during discard.');
    }

    /**
     * One physical cleanup batch; the native scheduler supplies the run budget.
     *
     * @param   Date          $now       The expiry cutoff.
     * @param   integer       $limit     The maximum discovery batch size.
     * @param   float|null    $deadline  Optional monotonic nanosecond deadline from hrtime(true).
     *
     * @return  integer  The number of rows physically deleted.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function purge(Date $now, int $limit = self::PURGE_BATCH_SIZE, ?float $deadline = null): int
    {
        if ($limit < 1 || $limit > self::PURGE_BATCH_SIZE) {
            throw new \InvalidArgumentException('Invalid draft cleanup batch.');
        }
        if ($deadline !== null && hrtime(true) >= $deadline) {
            return 0;
        }

        $instant = $now->toSql();
        $query   = $this->db->createQuery()->select($this->db->quoteName(['draft_id', 'owner_id', 'row_version']))
            ->from($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('expires_at') . ' <= :now')
            ->order($this->db->quoteName('expires_at'))->bind(':now', $instant);
        $expired = $this->db->setQuery($query, 0, $limit)->loadAssocList();
        $count   = 0;

        foreach ($expired as $row) {
            if ($deadline !== null && hrtime(true) >= $deadline) {
                break;
            }
            $count += $this->deleteExpired($row, $now);
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

    private function assertAutocommit(): void
    {
        $connection = $this->db->getConnection();
        if ($connection instanceof \PDO && $connection->inTransaction()) {
            throw new AutosaveException('service_unavailable', 'Optional draft writes require autocommit.');
        }
    }

    private function binding(array $row): array
    {
        return array_intersect_key($row, array_flip(['context', 'target_id', 'scope', 'schema_version', 'fields', 'baseline_revision', 'result_id']));
    }

    private function recoveryRow(int $owner, ?array $recovery, array $current, string $context, ?string $source, array $scope, Date $now): ?array
    {
        if ($recovery === null) {
            return null;
        }
        if ($recovery['draft_id'] === $current['draft_id'] || preg_match('/^[a-f0-9]{32}$/D', $recovery['draft_id']) !== 1) {
            throw new AutosaveException('invalid_request', 'Invalid recovered snapshot identity.');
        }
        try {
            $row = $this->read($owner, $recovery['draft_id'], $now);
        } catch (AutosaveException $exception) {
            if (\in_array($exception->getErrorCode(), ['draft_not_found', 'draft_expired'], true)) {
                return null;
            }
            throw $exception;
        }
        if ($row['context'] !== $context || $row['target_id'] !== $source || $row['scope'] !== $scope || $row['schema_version'] !== $current['schema_version']) {
            throw new AutosaveException('scope_conflict', 'The recovered snapshot does not match this submission.');
        }
        if ($recovery['revision'] > $row['client_revision']) {
            throw new AutosaveException('invalid_request', 'The recovered sequence was not preserved.');
        }
        foreach ($row['payload'] ?? [] as $key => $value) {
            if (!isset($current['fields'][$key], $row['fields'][$key]) || $current['fields'][$key]['control'] !== $row['fields'][$key]['control']) {
                throw new AutosaveException('schema_version_conflict', 'The recovered controls are incompatible.');
            }
        }
        return $row;
    }

    private function nativeResult(array &$row, int $revision, string $task, string $result, ?string $resultRevision, ?array $resultScope, Date $now): void
    {
        $row['result_id'] = $result;
        if ($task !== 'save2copy') {
            $row['target_id'] = $result;
            if ($resultScope !== null) {
                $row['scope'] = $resultScope;
            }
            if ($resultRevision !== null) {
                $row['baseline_revision'] = $resultRevision;
            }
        }
        $this->retire($row, $revision, $now);
    }

    private function mutation(array $old): \Joomla\Database\QueryInterface
    {
        $id      = $old['draft_id'];
        $owner   = (int) $old['owner_id'];
        $version = (int) $old['row_version'];
        return $this->db->createQuery()
            ->where($this->db->quoteName('draft_id') . ' = :draft')
            ->where($this->db->quoteName('owner_id') . ' = :owner')
            ->where($this->db->quoteName('row_version') . ' = :version')
            ->bind(':draft', $id)->bind(':owner', $owner, ParameterType::INTEGER)->bind(':version', $version, ParameterType::INTEGER);
    }

    private function deleteExpired(array $row, Date $now): int
    {
        $this->assertAutocommit();
        $instant = $now->toSql();
        $query   = $this->mutation($row)->delete($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('expires_at') . ' <= :now')->bind(':now', $instant);
        $this->db->setQuery($query)->execute();
        return $this->db->getAffectedRows();
    }

    private function recycle(array $row, array $old, Date $now): bool
    {
        $this->assertAutocommit();
        $instant            = $now->toSql();
        $row['row_version'] = $old['row_version'] + 1;
        $query              = $this->mutation($old)->update($this->db->quoteName('#__autosave_drafts'))
            ->where($this->db->quoteName('expires_at') . ' <= :now')
            ->where($this->db->quoteName('client_revision') . ' = 0')
            ->where($this->db->quoteName('retired_revision') . ' = 0')
            ->where($this->db->quoteName('payload') . ' IS NULL')
            ->where($this->db->quoteName('result_id') . ' IS NULL')->bind(':now', $instant);
        foreach ($row as $column => &$value) {
            $query->set($this->db->quoteName($column) . ' = :set_' . $column)
                ->bind(':set_' . $column, $value, $value === null ? ParameterType::NULL : (\is_int($value) ? ParameterType::INTEGER : ParameterType::STRING));
        }
        unset($value);
        $this->db->setQuery($query)->execute();
        return $this->db->getAffectedRows() === 1;
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

    private function update(array &$row, array $old, Date $now, ?string $encoded = null, bool $allowExpired = false): bool
    {
        $this->assertAutocommit();
        $row['row_version'] = $old['row_version'] + 1;
        $query              = $this->mutation($old)->update($this->db->quoteName('#__autosave_drafts'));
        $values             = [
            'row_version'       => $row['row_version'],
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
        $instant = $now->toSql();
        if (!$allowExpired) {
            $query->where($this->db->quoteName('expires_at') . ' > :now')->bind(':now', $instant);
        }
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows() === 1;
    }

    private function newRow(int $owner, int $slot, array $descriptor, Date $now): object
    {
        $instant = $now->toSql();
        return (object) [
            'draft_id'          => bin2hex(random_bytes(16)),
            'owner_slot'        => $slot,
            'row_version'       => 0,
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
            'created_at'        => $instant,
            'updated_at'        => $instant,
            'expires_at'        => min($this->deadline($now, $this->policy['empty_ttl']), $this->deadline($now, $this->policy['absolute_ttl'])),
        ];
    }

    private function decode(array $row): array
    {
        foreach (['owner_id', 'owner_slot', 'row_version', 'schema_version', 'client_revision', 'retired_revision'] as $key) {
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
