<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Newsfeeds\Administrator\Autosave\NewsfeedAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\TransitionAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class CanonicalRelationsProvidersTest extends UnitTestCase
{
    public function testExactContextsIdentitiesAndPayloads(): void
    {
        $newsfeed          = new NewsfeedAutosaveProvider($this->database());
        $transition        = new TransitionAutosaveProvider($this->database());
        $newsPayload       = ['metakey' => '', 'name' => '', 'description' => '', 'link' => '', 'version_note' => '', 'numarticles' => 5, 'cache_time' => 3600, 'metadesc' => ''];
        $transitionPayload = ['to_stage_id' => 2, 'description' => '', 'title' => '', 'from_stage_id' => -1];

        $this->assertSame('com_newsfeeds.newsfeed', $newsfeed->getContext());
        $this->assertSame('com_workflow.transition', $transition->getContext());
        $this->assertSame(['name', 'description', 'link', 'version_note', 'numarticles', 'cache_time', 'metadesc', 'metakey'], array_keys($newsfeed->normalizePayload($newsPayload, 1)));
        $this->assertSame(['title', 'description', 'from_stage_id', 'to_stage_id'], array_keys($transition->normalizePayload($transitionPayload, 1)));

        foreach (['0', '-1', '01', ' 1', '1 ', '1.0', '2147483648'] as $invalid) {
            $this->assertFailure('invalid_target', fn () => $newsfeed->canonicalizeTargetId($invalid));
            $this->assertFailure('invalid_target', fn () => $transition->canonicalizeTargetId($invalid));
        }

        $this->assertFailure('invalid_payload', fn () => $newsfeed->normalizePayload(array_replace($newsPayload, ['numarticles' => '5']), 1));
        $this->assertFailure('invalid_payload', fn () => $newsfeed->normalizePayload($newsPayload + ['catid' => 2], 1));
        $this->assertFailure('invalid_payload', fn () => $transition->normalizePayload(array_replace($transitionPayload, ['from_stage_id' => -2]), 1));
        $this->assertFailure('invalid_payload', fn () => $transition->normalizePayload(array_replace($transitionPayload, ['workflow_id' => 3]), 1));
    }

    public function testTransitionValidatesStagesAgainstCanonicalWorkflow(): void
    {
        $record = (object) ['id' => 42, 'workflow_id' => 7, 'extension' => 'com_content.article', 'title' => 'T', 'description' => '', 'from_stage_id' => 1, 'to_stage_id' => 2, 'published' => 1, 'ordering' => 1, 'options' => '{}', 'checked_out' => 0];
        $valid  = ['title' => '', 'description' => '', 'from_stage_id' => -1, 'to_stage_id' => 2];
        $db     = $this->database();
        $db->method('loadObject')->willReturn($record);
        $db->method('loadResult')->willReturn(1);

        $this->assertSame($valid, (new TransitionAutosaveProvider($db))->normalizePayloadForTarget('42', $valid, 1));

        $invalid = $this->database();
        $invalid->method('loadObject')->willReturn($record);
        $invalid->method('loadResult')->willReturn(0);
        $this->assertFailure('invalid_payload', fn () => (new TransitionAutosaveProvider($invalid))->normalizePayloadForTarget('42', array_replace($valid, ['from_stage_id' => 8]), 1));
    }

    public function testNewsfeedAuthorizationUsesCanonicalCategoryOwnerAndCheckout(): void
    {
        $record   = (object) ['id' => 42, 'catid' => 9, 'category_id' => 9, 'category_extension' => 'com_newsfeeds', 'created_by' => 7, 'checked_out' => 0];
        $user     = $this->createMock(User::class);
        $user->id = 7;
        $user->method('authorise')->willReturnCallback(static fn ($action, $asset) => $action === 'core.edit.own' && $asset === 'com_newsfeeds.category.9');
        $db = $this->database();
        $db->method('loadObject')->willReturn($record);

        (new NewsfeedAutosaveProvider($db))->authorize($user, '42', AutosaveOperation::Preserve);

        $record->checked_out = 99;
        $this->assertFailure('checked_out', fn () => (new NewsfeedAutosaveProvider($db))->authorize($user, '42', AutosaveOperation::Read));
    }

    public function testTransitionAuthorizationDerivesExtensionAndEnforcesCheckout(): void
    {
        $record   = (object) ['id' => 42, 'workflow_id' => 7, 'extension' => 'com_content.article', 'checked_out' => 0];
        $user     = $this->createMock(User::class);
        $user->id = 7;
        $user->method('authorise')->willReturnCallback(static fn ($action, $asset) => $action === 'core.edit' && $asset === 'com_content.transition.42');
        $db = $this->database();
        $db->method('loadObject')->willReturn($record);

        (new TransitionAutosaveProvider($db))->authorize($user, '42', AutosaveOperation::Preserve);

        $record->checked_out = 99;
        $this->assertFailure('checked_out', fn () => (new TransitionAutosaveProvider($db))->authorize($user, '42', AutosaveOperation::Read));
    }

    private function database(): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();

        return $db;
    }

    private function assertFailure(string $code, callable $callback): void
    {
        try {
            $callback();
        } catch (AutosaveException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
            return;
        }
        $this->fail('Expected AutosaveException.');
    }
}
