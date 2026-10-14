<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Date\Date;

/**
 * Identical behavior cases for the native SQL store on local and CI database drivers.
 */
trait DraftStoreCases
{
    use DraftDatabaseFixture;


    public function testCreationScopeCompletionKeepsLaterAndRestoredWorkDiscoverable(): void
    {
        $scope       = ['client_id' => '0', 'menutype' => ''];
        $resultScope = ['client_id' => '0', 'menutype' => 'created-menu'];
        $descriptor  = array_replace($this->descriptor(null), ['context' => 'com_menus.menu', 'scope' => $scope]);
        $working     = $this->store->open(7, $descriptor, $this->now);
        $source      = $this->store->open(7, $descriptor, $this->now);
        $other       = $this->store->open(7, $descriptor, $this->now);
        $this->preserve($working, 1, 'submitted');
        $this->preserve($working, 2, 'later working');
        $this->preserve($source, 1, 'restored');
        $this->preserve($source, 2, 'later source');
        $this->preserve($other, 1, 'another tab');
        $this->store->reconcile(
            7,
            $working['draft_id'],
            1,
            'com_menus.menu',
            null,
            $scope,
            'apply',
            '84',
            'saved-revision',
            $this->now,
            ['draft_id' => $source['draft_id'], 'revision' => 1],
            false,
            $resultScope
        );
        foreach ([$working, $source] as $original) {
            $row = $this->store->read(7, $original['draft_id'], $this->now);
            $this->assertSame('84', $row['target_id']);
            $this->assertSame($resultScope, $row['scope']);
            $this->assertSame('saved-revision', $row['baseline_revision']);
            $this->assertSame(1, $row['retired_revision']);
            $this->assertNotNull($row['payload']);
            $this->preserve($row, 3, 'subsequent work');
        }
        $untouched = $this->store->read(7, $other['draft_id'], $this->now);
        $this->assertNull($untouched['target_id']);
        $this->assertSame($scope, $untouched['scope']);
        $this->assertSame(['title' => 'another tab'], $untouched['payload']);
    }

    public function testCompletedScopeCannotReplaceAContradictorySourceBinding(): void
    {
        $descriptor = array_replace($this->descriptor(null), ['context' => 'com_menus.menu', 'scope' => ['client_id' => '0', 'menutype' => 'original']]);
        $draft      = $this->store->open(7, $descriptor, $this->now);
        $this->preserve($draft, 1, 'recoverable');
        $this->failure('scope_conflict', fn () => $this->store->reconcile(
            7,
            $draft['draft_id'],
            1,
            'com_menus.menu',
            null,
            ['client_id' => '0', 'menutype' => 'different'],
            'apply',
            '84',
            null,
            $this->now,
            null,
            false,
            ['client_id' => '0', 'menutype' => 'completed']
        ));
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame($descriptor['scope'], $row['scope']);
        $this->assertNull($row['target_id']);
        $this->assertNotNull($row['payload']);
    }

    private function descriptor(?string $target = '42'): array
    {
        return [
            'context'  => 'com_content.article', 'target' => $target, 'scope' => [], 'version' => 1,
            'revision' => $target === null ? null : 'baseline',
            'fields'   => ['title' => ['control' => 'text']],
        ];
    }

    private function preserve(array $draft, int $revision, string $value = '', ?Date $now = null): array
    {
        return $this->store->preserve(
            7,
            $draft['draft_id'],
            $revision,
            ['title' => $value],
            $now ?? $this->now,
            static fn (array $binding, mixed $payload): array => DraftProjection::normalize($payload, $binding['fields'])
        );
    }

    private function failure(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected draft failure: ' . $code);
        } catch (AutosaveException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
        }
    }

    public function testIncompletePrivateDraftAndIdenticalRetry(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $first = $this->preserve($draft, 1);
        $retry = $this->preserve($draft, 1, '', new Date('2026-10-11 00:01:00', 'UTC'));
        $this->assertSame(['title' => ''], $retry['payload']);
        $this->assertSame($first['updated_at'], $retry['updated_at']);
        $this->assertSame($first['expires_at'], $retry['expires_at']);
        $this->failure('draft_not_found', fn () => $this->store->read(8, $draft['draft_id'], $this->now));
        $this->failure('draft_not_found', fn () => $this->store->read(8, str_repeat('a', 32), $this->now));
    }

    public function testOrderingCannotReplaceAcknowledgedContent(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 2, 'latest');
        $this->failure('revision_conflict', fn () => $this->preserve($draft, 1, 'older'));
        $this->failure('revision_conflict', fn () => $this->preserve($draft, 2, 'different'));
        $this->assertSame(['title' => 'latest'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    /** @dataProvider successOrderings */
    public function testSuccessRetiresOnlySubmittedSequence(bool $successFirst, bool $creating): void
    {
        $source = $creating ? null : '42';
        $draft  = $this->store->open(7, $this->descriptor($source), $this->now);
        $this->preserve($draft, 1, 'submitted');
        $success = fn () => $this->store->reconcile(
            7,
            $draft['draft_id'],
            1,
            'com_content.article',
            $source,
            [],
            'apply',
            '42',
            'saved',
            $this->now
        );
        if ($successFirst) {
            $success();
        }
        $this->preserve($draft, 2, 'later');
        if (!$successFirst) {
            $success();
        }
        $result = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame(['title' => 'later'], $result['payload']);
        $this->assertSame('42', $result['target_id']);
        $this->assertSame('saved', $result['baseline_revision']);
        $this->assertSame(1, $result['retired_revision']);
        $this->failure('draft_retired', fn () => $this->preserve($draft, 1, 'late old request'));
    }

    public static function successOrderings(): array
    {
        return [[true, false], [false, false], [true, true], [false, true]];
    }

    public function testNativeSubmissionNeedNotHaveAcknowledgedDraft(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->store->reconcile(7, $draft['draft_id'], 4, 'com_content.article', '42', [], 'save', '42', null, $this->now);
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertNull($row['payload']);
        $this->assertSame(4, $row['retired_revision']);
        $this->assertSame('baseline', $row['baseline_revision']);
        $this->failure('draft_retired', fn () => $this->preserve($draft, 4, 'delayed'));
    }

    public function testCopyNeverRebindsLaterSourceEdits(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 2, 'later source');
        $this->store->reconcile(7, $draft['draft_id'], 1, 'com_content.article', '42', [], 'save2copy', '84', 'copy revision', $this->now);
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame('42', $row['target_id']);
        $this->assertSame('baseline', $row['baseline_revision']);
        $this->assertSame(['title' => 'later source'], $row['payload']);
    }

    public function testWrongBindingCannotRetireDraft(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'recoverable');
        $this->failure('scope_conflict', fn () => $this->store->reconcile(
            7,
            $draft['draft_id'],
            1,
            'com_content.article',
            '99',
            [],
            'save',
            '42',
            null,
            $this->now
        ));
        $this->assertSame(['title' => 'recoverable'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    /** @dataProvider discardOrderings */
    public function testDiscardAndDelayedWritesCannotResurrect(bool $discardFirst): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        if (!$discardFirst) {
            $this->preserve($draft, 2, 'before discard');
        }
        $this->store->discard(7, $draft['draft_id'], $this->now);
        $this->store->discard(7, $draft['draft_id'], $this->now);
        $this->failure('draft_retired', fn () => $this->preserve($draft, 3, 'after discard'));
        $this->assertNull($this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    public static function discardOrderings(): array
    {
        return [[true], [false]];
    }

    public function testPurgeCannotRemoveRefreshedPayloadOrRecreateUnknownId(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'refreshed', new Date('2026-10-11 00:59:00', 'UTC'));
        $this->assertSame(0, $this->store->purge(new Date('2026-10-11 01:01:00', 'UTC')));
        $expired = new Date('2026-10-19 00:00:00', 'UTC');
        $this->assertSame(1, $this->store->purge($expired));
        $this->failure('draft_not_found', fn () => $this->preserve($draft, 2, 'cannot upsert', $expired));
    }

    public function testExpiredCleanupBudgetLeavesWorkForNativeContinuation(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $later = new Date('2026-10-19 00:00:00', 'UTC');
        $this->assertSame(0, $this->store->purge($later, DraftStore::PURGE_BATCH_SIZE, hrtime(true) - 1));
        $this->assertTrue($this->store->hasExpired($later));
        $this->assertSame(1, $this->store->purge($later));
        $this->failure('draft_not_found', fn () => $this->store->read(7, $draft['draft_id'], $later));
    }

    public function testCleanupRemovesExpiredDraftsOfDeletedOwners(): void
    {
        $this->store->open(8, $this->descriptor(), $this->now);
        $this->db->setQuery('DELETE FROM #__users WHERE id = 8')->execute();
        $this->assertSame(1, $this->store->purge(new Date('2026-10-11 01:00:00', 'UTC')));
        $this->failure('forbidden', fn () => $this->store->open(8, $this->descriptor(), $this->now));
    }

    public function testEmptyBindingAndAbsoluteLifetime(): void
    {
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->failure('draft_expired', fn () => $this->preserve($draft, 1, '', new Date('2026-10-11 01:00:00', 'UTC')));
        $draft = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'content');
        foreach ([6, 12, 18, 24, 29] as $index => $day) {
            $row = $this->preserve($draft, $index + 2, 'content', new Date('@' . ($this->now->getTimestamp() + $day * 86400), 'UTC'));
        }
        $this->assertSame('2026-11-10 00:00:00', $row['expires_at']);
    }

    public function testFormOpeningsAndRetiredRowsDoNotLimitNewDrafts(): void
    {
        for ($index = 0; $index < 1001; $index++) {
            $empty = $this->store->open(7, $this->descriptor(null), $this->now);
        }
        $this->assertNull($empty['payload']);
        $this->assertSame([], $this->store->find(7, $empty, $this->now)['candidates']);
        $this->store->discard(7, $empty['draft_id'], $this->now);
        $draft = $this->store->open(7, $this->descriptor(null), $this->now);
        $this->preserve($draft, 1, 'recoverable');
        $this->assertSame(['title' => 'recoverable'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    public function testUnexpiredCreationDraftsHaveNoCountCapAndArePagedWithoutPayloads(): void
    {
        $ids = [];
        for ($index = 0; $index < 105; $index++) {
            $source = $this->store->open(7, $this->descriptor(null), $this->now);
            $this->preserve($source, 1, 'creation ' . $index);
            $ids[] = $source['draft_id'];
        }
        $current = $this->store->open(7, $this->descriptor(null), $this->now);
        $first   = $this->store->find(7, $current, $this->now);
        $this->assertCount(100, $first['candidates']);
        $this->assertNotNull($first['next']);
        $second = $this->store->find(7, $current, $this->now, $first['next']);
        $this->assertCount(5, $second['candidates']);
        $this->assertNull($second['next']);
        $found = array_merge($first['candidates'], $second['candidates']);
        $this->assertEqualsCanonicalizing($ids, array_column($found, 'draft_id'));
        foreach ($found as $header) {
            $this->assertArrayNotHasKey('payload', $header);
        }
        $this->assertSame(0, $this->store->purge($this->now));
    }

    public function testDiscoveryCursorCannotCrossOwnersTargetsOrScopes(): void
    {
        $source = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($source, 1, 'private');
        $current = $this->store->open(7, $this->descriptor(), $this->now);
        $this->failure('invalid_request', fn () => $this->store->find(8, $current, $this->now, $source['draft_id']));
        $other = $this->store->open(7, $this->descriptor('84'), $this->now);
        $this->failure('invalid_request', fn () => $this->store->find(7, $other, $this->now, $source['draft_id']));
        $scope          = $this->descriptor();
        $scope['scope'] = ['extension' => 'another'];
        $other          = $this->store->open(7, $scope, $this->now);
        $this->failure('invalid_request', fn () => $this->store->find(7, $other, $this->now, $source['draft_id']));
    }

    public function testEmptyBindingsExpireWithoutEverAppearingInRecovery(): void
    {
        $empty   = $this->store->open(7, $this->descriptor(null), $this->now);
        $current = $this->store->open(7, $this->descriptor(null), $this->now);
        $this->assertSame([], $this->store->find(7, $current, $this->now)['candidates']);
        $this->assertSame(2, $this->store->purge(new Date('2026-10-11 01:00:00', 'UTC')));
        $this->failure('draft_not_found', fn () => $this->store->read(7, $empty['draft_id'], $this->now));
    }

    public function testOversizeAndUnsupportedKeysKeepLastValidDraft(): void
    {
        $policy                      = DraftStore::POLICY;
        $policy['max_payload_bytes'] = 32;
        $this->store                 = new DraftStore($this->db, $policy);
        $draft                       = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($draft, 1, 'last valid');
        $this->failure('payload_too_large', fn () => $this->preserve($draft, 2, str_repeat('x', 100)));
        $this->failure('invalid_payload', fn () => $this->store->preserve(
            7,
            $draft['draft_id'],
            2,
            ['password' => 'secret'],
            $this->now,
            static fn (array $binding, mixed $payload): array => DraftProjection::normalize($payload, $binding['fields'])
        ));
        $this->assertSame(['title' => 'last valid'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }
    /** @dataProvider recoveredTargets */
    public function testSaveRetiresOnlyItsSubmittedAndRecoveredSnapshots(?string $target): void
    {
        $source = $this->store->open(7, $this->descriptor($target), $this->now);
        $this->preserve($source, 1, 'restored');
        $current = $this->store->open(7, $this->descriptor($target), $this->now);
        $this->preserve($current, 1, 'restored');
        $other = $this->store->open(7, $this->descriptor($target), $this->now);
        $this->preserve($other, 1, 'another tab');
        $this->store->reconcile(
            7,
            $current['draft_id'],
            1,
            'com_content.article',
            $target,
            [],
            'save',
            '42',
            null,
            $this->now,
            ['draft_id' => $source['draft_id'], 'revision' => 1]
        );
        $this->assertNull($this->store->read(7, $current['draft_id'], $this->now)['payload']);
        $retired = $this->store->read(7, $source['draft_id'], $this->now);
        $this->assertNull($retired['payload']);
        $this->assertSame(1, $retired['retired_revision']);
        $this->assertSame(['title' => 'another tab'], $this->store->read(7, $other['draft_id'], $this->now)['payload']);
        $this->failure('draft_retired', fn () => $this->preserve($source, 1, 'delayed old write'));
    }

    public static function recoveredTargets(): array
    {
        return [['42'], [null]];
    }

    public function testRecoveredSourceKeepsItsNewerEditsAfterSave(): void
    {
        $source = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($source, 1, 'restored');
        $current = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($current, 1, 'restored');
        $this->preserve($source, 2, 'newer source edits');
        $this->store->reconcile(
            7,
            $current['draft_id'],
            1,
            'com_content.article',
            '42',
            [],
            'apply',
            '42',
            null,
            $this->now,
            ['draft_id' => $source['draft_id'], 'revision' => 1]
        );
        $row = $this->store->read(7, $source['draft_id'], $this->now);
        $this->assertSame(['title' => 'newer source edits'], $row['payload']);
        $this->assertSame(1, $row['retired_revision']);
        $this->assertNull($this->store->read(7, $current['draft_id'], $this->now)['payload']);
    }

    public function testForeignRecoveredBindingCannotRetireEitherSnapshot(): void
    {
        $source = $this->store->open(7, $this->descriptor('84'), $this->now);
        $this->preserve($source, 1, 'different record');
        $current = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($current, 1, 'submitted');
        $this->failure('scope_conflict', fn () => $this->store->reconcile(
            7,
            $current['draft_id'],
            1,
            'com_content.article',
            '42',
            [],
            'save',
            '42',
            null,
            $this->now,
            ['draft_id' => $source['draft_id'], 'revision' => 1]
        ));
        $this->assertSame(['title' => 'submitted'], $this->store->read(7, $current['draft_id'], $this->now)['payload']);
        $this->assertSame(['title' => 'different record'], $this->store->read(7, $source['draft_id'], $this->now)['payload']);
    }

    public function testClaimedFutureSourceSequenceCannotRetireWork(): void
    {
        $source = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($source, 1, 'source');
        $current = $this->store->open(7, $this->descriptor(), $this->now);
        $this->preserve($current, 1, 'submitted');
        $this->failure('invalid_request', fn () => $this->store->reconcile(
            7,
            $current['draft_id'],
            1,
            'com_content.article',
            '42',
            [],
            'save',
            '42',
            null,
            $this->now,
            ['draft_id' => $source['draft_id'], 'revision' => 2]
        ));
        $this->assertSame(['title' => 'submitted'], $this->store->read(7, $current['draft_id'], $this->now)['payload']);
    }

    public function testNativeStringRenamePreservesOnlyActualLaterRevisionsAndOtherInstances(): void
    {
        $descriptor = [
            'context'  => 'com_languages.override', 'target' => 'COM_OLD',
            'scope'    => ['client' => 'site', 'language' => 'en-GB'], 'version' => 1,
            'revision' => 'key-level before', 'fields' => ['override' => ['control' => 'text']],
        ];
        $draft = $this->store->open(7, $descriptor, $this->now);
        $other = $this->store->open(7, $descriptor, $this->now);
        foreach ([$draft, $other] as $row) {
            $this->store->preserve(7, $row['draft_id'], 1, ['override' => 'submitted'], $this->now, static fn ($binding, $values) => $values);
        }
        $this->store->preserve(7, $draft['draft_id'], 2, ['override' => 'later work'], $this->now, static fn ($binding, $values) => $values);
        $this->store->reconcile(
            7,
            $draft['draft_id'],
            1,
            $descriptor['context'],
            'COM_OLD',
            $descriptor['scope'],
            'apply',
            'COM_RENAMED',
            null,
            $this->now,
            null,
            true
        );
        $current = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame('COM_RENAMED', $current['target_id']);
        $this->assertSame(['override' => 'later work'], $current['payload']);
        $this->assertSame(1, $current['retired_revision']);
        $this->assertSame(['override' => 'submitted'], $this->store->read(7, $other['draft_id'], $this->now)['payload']);
        $this->failure('draft_retired', fn () => $this->store->preserve(
            7,
            $draft['draft_id'],
            1,
            ['override' => 'late submitted'],
            $this->now,
            static fn ($binding, $values) => $values
        ));
        $this->store->reconcile(
            7,
            $draft['draft_id'],
            2,
            $descriptor['context'],
            'COM_RENAMED',
            $descriptor['scope'],
            'save',
            'COM_RENAMED',
            null,
            $this->now
        );
        $this->assertNull($this->store->read(7, $draft['draft_id'], $this->now)['payload']);
    }

    public function testStringRenameStillRequiresExactNativeSourceAndScope(): void
    {
        $descriptor = [
            'context'  => 'com_languages.override', 'target' => 'COM_OLD',
            'scope'    => ['client' => 'site', 'language' => 'en-GB'], 'version' => 1,
            'revision' => 'before', 'fields' => ['override' => ['control' => 'text']],
        ];
        $draft = $this->store->open(7, $descriptor, $this->now);
        foreach (
            [
            ['COM_OTHER', $descriptor['scope'], true],
            ['COM_OLD', ['client' => 'administrator', 'language' => 'en-GB'], true],
            ['COM_OLD', $descriptor['scope'], false],
            ] as [$source, $scope, $rename]
        ) {
            $this->failure('scope_conflict', fn () => $this->store->reconcile(
                7,
                $draft['draft_id'],
                1,
                $descriptor['context'],
                $source,
                $scope,
                'apply',
                'COM_RENAMED',
                null,
                $this->now,
                null,
                $rename
            ));
        }
    }
}
