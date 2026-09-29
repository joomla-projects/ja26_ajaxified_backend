<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Users\Administrator\Autosave\NoteAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR35 com_users.note new-record Autosave rollout.
 *
 * Note drafts deliberately carry only the authored subject/body. The required
 * target user and note category relations never enter a draft: their widgets are
 * ACL-gated and the native model re-primes them from the routed request state,
 * so no relation/permission smuggling surface exists and no per-relation payload
 * authorization is needed.
 *
 * @since  __DEPLOY_VERSION__
 */
class NoteNewRecordAutosaveRolloutTest extends UnitTestCase
{
    public function testNoteProviderExposesTheCreateCapabilityWithoutSchemaChange(): void
    {
        $provider = new NoteAutosaveProvider($this->database());

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertSame('com_users.note', $provider->getContext());
        $this->assertSame('user-note-create-v1', $provider->getCreateContractVersion());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    public function testNoteCreateAuthorizationMirrorsNativeAllowAdd(): void
    {
        $provider = new NoteAutosaveProvider($this->database());

        // Component-wide core.create right.
        $global = $this->createMock(User::class);
        $global->method('authorise')->willReturn(true);
        $provider->authorizeCreate($global, AutosaveOperation::InitializeCreate, null);

        // Category-scoped create rights only.
        $scoped = $this->createMock(User::class);
        $scoped->method('authorise')->willReturn(false);
        $scoped->method('getAuthorisedCategories')->willReturn([4]);
        $provider->authorizeCreate($scoped, AutosaveOperation::InitializeCreate, null);

        // Denied everywhere fails closed on every provisional operation.
        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);
        $denied->method('getAuthorisedCategories')->willReturn([]);

        foreach (AutosaveOperation::cases() as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeCreate($denied, $operation, null))->getErrorCode()
            );
        }
    }

    public function testNotePayloadCannotSmuggleRelationsOrUserData(): void
    {
        $provider = new NoteAutosaveProvider($this->database());
        $valid    = ['subject' => 'Follow up', 'body' => '<p>Remind about the audit.</p>'];

        $this->assertSame($valid, $provider->normalizePayload($valid, 1));

        // Relations, state and user PII must never ride the draft.
        foreach (
            [
            [...$valid, 'user_id' => 7],
            [...$valid, 'catid' => 3],
            [...$valid, 'state' => 1],
            [...$valid, 'review_time' => '2026-09-01 00:00:00'],
            [...$valid, 'username' => 'admin'],
            [...$valid, 'email' => 'admin@example.test'],
            array_diff_key($valid, ['subject' => true]),
            array_diff_key($valid, ['body' => true]),
            ['subject' => 'x'],
            ] as $invalid
        ) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode()
            );
        }

        // Wrong schema versions and malformed shapes are rejected.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload($valid, 2))->getErrorCode()
        );
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload(['subject', 'body'], 1))->getErrorCode()
        );

        // Bounds are enforced.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload(['subject' => str_repeat('x', 101), 'body' => ''], 1))->getErrorCode()
        );
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload(['subject' => 'x', 'body' => str_repeat('x', 65536)], 1))->getErrorCode()
        );
    }

    public function testNoteExistingRecordBehaviorIsUnchanged(): void
    {
        $row      = (object) [
            'id'           => 5, 'user_id' => 3, 'catid' => 1, 'subject' => 'Follow up', 'body' => '<p>Note</p>',
            'state'        => 1, 'checked_out' => 0, 'checked_out_time' => null, 'created_user_id' => 3,
            'created_time' => '2026-01-01 00:00:00', 'modified_user_id' => null, 'modified_time' => null,
            'review_time'  => null, 'publish_up' => null, 'publish_down' => null,
        ];
        $provider = new NoteAutosaveProvider($this->database(['row' => $row]));

        $this->assertTrue($provider->targetExists('5'));
        $this->assertSame('5', $provider->canonicalizeTargetId('5'));

        $editor = $this->createMock(User::class);
        $editor->method('authorise')->willReturn(true);
        $provider->authorize($editor, '5', AutosaveOperation::Read);

        $this->assertStringStartsWith('autosave:com_users.note:base-revision:v1:', $provider->getBaseRevision('5'));

        // Checked-out by another user fails closed.
        $checkedOut              = clone $row;
        $checkedOut->checked_out = 99;
        $locked                  = new NoteAutosaveProvider($this->database(['row' => $checkedOut]));
        $other                   = $this->createMock(User::class);
        $other->method('authorise')->willReturn(true);
        $this->assertSame(
            'checked_out',
            $this->failure(fn () => $locked->authorize($other, '5', AutosaveOperation::Preserve))->getErrorCode()
        );
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(
            \array_key_exists('row', $options) ? $options['row'] : (object) ['id' => 5, 'catid' => 1]
        );
        $db->method('loadResult')->willReturn(1);

        return $db;
    }

    private function failure(callable $callback): AutosaveException
    {
        try {
            $callback();
        } catch (AutosaveException $exception) {
            return $exception;
        }

        $this->fail('Expected AutosaveException was not thrown.');
    }
}
