<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Categories\Administrator\Autosave\CategoryAutosaveProvider;
use Joomla\Component\Fields\Administrator\Autosave\GroupAutosaveProvider;
use Joomla\Component\Tags\Administrator\Autosave\TagAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class HierarchicalMetadataProvidersTest extends UnitTestCase
{
    public function testExactContextsIdentityAndPayloadContracts(): void
    {
        $providers = [
            new CategoryAutosaveProvider($this->database()),
            new TagAutosaveProvider($this->database()),
            new GroupAutosaveProvider($this->database()),
        ];
        $rich = ['metakey' => '', 'title' => '', 'note' => '', 'description' => '', 'version_note' => '', 'metadesc' => ''];

        $this->assertSame(['com_categories.category', 'com_tags.tag', 'com_fields.group'], array_map(fn ($provider) => $provider->getContext(), $providers));
        $this->assertSame(['title', 'note', 'description', 'version_note', 'metadesc', 'metakey'], array_keys($providers[0]->normalizePayload($rich, 1)));
        $this->assertSame(['title', 'note', 'description', 'version_note', 'metadesc', 'metakey'], array_keys($providers[1]->normalizePayload($rich, 1)));
        $this->assertSame(['title' => '', 'note' => '', 'description' => ''], $providers[2]->normalizePayload(['description' => '', 'title' => '', 'note' => ''], 1));

        foreach ($providers as $provider) {
            foreach (['0', '-1', '01', ' 1', '1 ', 'abc', '2147483648', '4294967296'] as $target) {
                $this->assertFailure('invalid_target', fn () => $provider->canonicalizeTargetId($target));
            }
        }

        $this->assertFailure('invalid_payload', fn () => $providers[0]->normalizePayload($rich + ['parent_id' => '2'], 1));
        $this->assertFailure('invalid_payload', fn () => $providers[1]->normalizePayload(array_diff_key($rich, ['title' => true]), 1));
        $this->assertFailure('invalid_payload', fn () => $providers[1]->normalizePayload(array_replace($rich, ['title' => []]), 1));
        $this->assertFailure('invalid_payload', fn () => $providers[2]->normalizePayload(['title' => "\xC3\x28", 'note' => '', 'description' => ''], 1));
        $this->assertFailure('invalid_payload', fn () => $providers[2]->normalizePayload(['title' => str_repeat('x', 256), 'note' => '', 'description' => ''], 1));
    }

    /**
     * @dataProvider revisionProvider
     */
    public function testBaseRevisionIsStableCheckoutIndependentAndContentSensitive(string $providerClass, object $record): void
    {
        $first    = new $providerClass($this->databaseReturning($record));
        $checkout = clone $record;
        $changed  = clone $record;

        $checkout->checked_out = 99;
        $changed->title        = 'Changed';

        $this->assertSame($first->getBaseRevision('42'), $first->getBaseRevision('42'));
        $this->assertSame($first->getBaseRevision('42'), (new $providerClass($this->databaseReturning($checkout)))->getBaseRevision('42'));
        $this->assertNotSame($first->getBaseRevision('42'), (new $providerClass($this->databaseReturning($changed)))->getBaseRevision('42'));
    }

    public static function revisionProvider(): array
    {
        return [
            'category' => [CategoryAutosaveProvider::class, (object) ['id' => 42, 'extension' => 'com_content', 'title' => 'Title', 'checked_out' => 0]],
            'tag'      => [TagAutosaveProvider::class, (object) ['id' => 42, 'title' => 'Title', 'checked_out' => 0]],
            'group'    => [GroupAutosaveProvider::class, (object) ['id' => 42, 'context' => 'com_content.article', 'title' => 'Title', 'checked_out' => 0]],
        ];
    }

    /**
     * @dataProvider authorizationProvider
     */
    public function testAuthorizationEnforcesDatabaseAssetExistenceAndCheckout(string $providerClass, object $record, string $asset): void
    {
        $user     = $this->createMock(User::class);
        $user->id = 7;
        $user->method('authorise')->willReturnCallback(static fn ($action, $candidate) => $action === 'core.edit' && $candidate === $asset);

        foreach (AutosaveOperation::cases() as $operation) {
            (new $providerClass($this->databaseReturning($record)))->authorize($user, '42', $operation);
        }

        $checkedOut              = clone $record;
        $checkedOut->checked_out = 99;
        $this->assertFailure('checked_out', fn () => (new $providerClass($this->databaseReturning($checkedOut)))->authorize($user, '42', AutosaveOperation::Preserve));
        $this->assertFailure('target_not_found', fn () => (new $providerClass($this->databaseReturning(null)))->authorize($user, '42', AutosaveOperation::Read));
    }

    public static function authorizationProvider(): array
    {
        return [
            'category' => [CategoryAutosaveProvider::class, (object) ['id' => 42, 'extension' => 'com_content', 'created_user_id' => 7, 'checked_out' => 0], 'com_content.category.42'],
            'tag'      => [TagAutosaveProvider::class, (object) ['id' => 42, 'checked_out' => 0], 'com_tags'],
            'group'    => [GroupAutosaveProvider::class, (object) ['id' => 42, 'context' => 'com_content.article', 'created_by' => 7, 'checked_out' => 0], 'com_content.fieldgroup.42'],
        ];
    }

    private function database(): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();

        return $db;
    }

    private function databaseReturning(?object $record): DatabaseInterface
    {
        $db = $this->database();
        $db->method('loadObject')->willReturn($record);

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
