<?php

namespace Joomla\Tests\Unit\Administrator\Components\Contact\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Contact\Administrator\Autosave\ContactAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class ContactAutosaveProviderTest extends UnitTestCase
{
    public function testExactContextIdentityAndExistence(): void
    {
        $provider = new ContactAutosaveProvider($this->databaseReturning($this->contact()));
        $this->assertSame('com_contact.contact', $provider->getContext());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
        $this->assertSame('2147483647', $provider->canonicalizeTargetId('2147483647'));
        $this->assertTrue($provider->targetExists('42'));

        foreach (['', '0', '-1', '01', ' 1', '1.0', '2147483648'] as $invalid) {
            $this->assertSame('invalid_target', $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode());
        }

        $this->assertFalse((new ContactAutosaveProvider($this->databaseReturning()))->targetExists('42'));
    }

    public function testPayloadPreservesExactIncompletePiiEditorialDatesAndMedia(): void
    {
        $provider              = new ContactAutosaveProvider($this->databaseReturning());
        $payload               = $this->payload();
        $payload['email_to']   = 'unfinished@';
        $payload['telephone']  = '+44 (';
        $payload['address']    = "12 Example Street\nUnit";
        $payload['misc']       = '<p>Unfinished biography</p>';
        $payload['publish_up'] = 'Tomorrow';
        $payload['image']      = 'images/contact.jpg#joomlaImage://local-images/contact.jpg';

        $this->assertSame($payload, $provider->normalizePayload($payload, 1));
    }

    public function testRejectsMissingExtraDynamicServerMalformedAndOverBoundValuesWithoutEchoingPii(): void
    {
        $provider = new ContactAutosaveProvider($this->databaseReturning());
        $payload  = $this->payload();
        $invalid  = [
            array_diff_key($payload, ['email_to' => true]),
            [...$payload, 'password' => 'secret'],
            [...$payload, 'com_fields' => ['private' => 'value']],
            [...$payload, 'params' => ['show_email' => 1]],
            [...$payload, 'id' => 42],
            [...$payload, 'email_to' => ['person@example.test']],
            [...$payload, 'name' => "\xB1\x31"],
            [...$payload, 'suburb' => str_repeat('x', 101)],
            [...$payload, 'metadesc' => str_repeat('x', 301)],
        ];

        foreach ($invalid as $candidate) {
            $error = $this->failure(fn () => $provider->normalizePayload($candidate, 1));
            $this->assertSame('invalid_payload', $error->getErrorCode());
            $this->assertStringNotContainsString('secret', $error->getMessage());
            $this->assertStringNotContainsString('person@example.test', $error->getMessage());
        }
    }

    public function testStableMediaReferencesExcludeTransientAndCredentialedValues(): void
    {
        $provider = new ContactAutosaveProvider($this->databaseReturning());

        foreach (['', 'images/contact.jpg', 'https://cdn.example.test/contact.jpg'] as $image) {
            $this->assertSame($image, $provider->normalizePayload([...$this->payload(), 'image' => $image], 1)['image']);
        }

        foreach (['blob:temporary', 'data:image/png;base64,AA', 'file:///tmp/a', '/images/a.jpg', '//example.test/a', 'https://user@example.test/a'] as $image) {
            $this->assertSame('invalid_payload', $this->failure(fn () => $provider->normalizePayload([...$this->payload(), 'image' => $image], 1))->getErrorCode());
        }
    }

    public function testAuthorizationDerivesCategoryOwnerAndCheckoutFromDatabase(): void
    {
        $owner     = $this->createMock(User::class);
        $owner->id = 7;
        $owner->method('authorise')->willReturnCallback(static fn ($action, $asset) => $action === 'core.edit.own' && $asset === 'com_contact.category.3');
        (new ContactAutosaveProvider($this->databaseReturning($this->contact(['created_by' => 7, 'checked_out' => 7]))))
            ->authorize($owner, '42', AutosaveOperation::Preserve);

        $denied     = $this->createMock(User::class);
        $denied->id = 8;
        $denied->method('authorise')->willReturn(false);
        $error = $this->failure(fn () => (new ContactAutosaveProvider($this->databaseReturning($this->contact())))->authorize($denied, '42', AutosaveOperation::Read));
        $this->assertSame('forbidden', $error->getErrorCode());
        $this->assertStringNotContainsString('person@example.test', $error->getMessage());

        $editor     = $this->createMock(User::class);
        $editor->id = 8;
        $editor->method('authorise')->willReturnCallback(static fn ($action) => $action === 'core.edit');
        $this->assertSame('checked_out', $this->failure(fn () => (new ContactAutosaveProvider($this->databaseReturning($this->contact(['checked_out' => 99]))))->authorize($editor, '42', AutosaveOperation::Read))->getErrorCode());
    }

    public function testBaseRevisionIgnoresCheckoutAndChangesWithCanonicalOrRelationData(): void
    {
        $base = new ContactAutosaveProvider($this->databaseReturning($this->contact()));
        $this->assertSame($base->getBaseRevision('42'), (new ContactAutosaveProvider($this->databaseReturning($this->contact(['checked_out' => 99]))))->getBaseRevision('42'));
        $this->assertNotSame($base->getBaseRevision('42'), (new ContactAutosaveProvider($this->databaseReturning($this->contact(['email_to' => 'other@example.test']))))->getBaseRevision('42'));
        $this->assertNotSame($base->getBaseRevision('42'), (new ContactAutosaveProvider($this->databaseReturning($this->contact(['catid' => 4, 'category_id' => 4]))))->getBaseRevision('42'));
    }

    private function payload(): array
    {
        return array_fill_keys([
            'name', 'alias', 'version_note', 'misc', 'image', 'con_position', 'email_to', 'address',
            'suburb', 'state', 'postcode', 'country', 'telephone', 'mobile', 'fax', 'webpage',
            'sortname1', 'sortname2', 'sortname3', 'publish_up', 'publish_up_alt', 'publish_down',
            'publish_down_alt', 'metakey', 'metadesc',
        ], '');
    }

    private function contact(array $replace = []): object
    {
        return (object) array_replace([
            'id'          => 42, 'name' => 'Person', 'alias' => 'person', 'con_position' => '', 'address' => '',
            'suburb'      => '', 'state' => '', 'country' => '', 'postcode' => '', 'telephone' => '', 'fax' => '',
            'misc'        => '', 'image' => '', 'email_to' => 'person@example.test', 'default_con' => 0, 'published' => 1,
            'checked_out' => 0, 'ordering' => 1, 'params' => '{}', 'user_id' => 0, 'catid' => 3, 'access' => 1,
            'mobile'      => '', 'webpage' => '', 'sortname1' => '', 'sortname2' => '', 'sortname3' => '',
            'language'    => '*', 'created' => '2026-01-01 00:00:00', 'created_by' => 7, 'created_by_alias' => '',
            'modified'    => '2026-01-01 00:00:00', 'modified_by' => 7, 'metakey' => '', 'metadesc' => '',
            'metadata'    => '{}', 'featured' => 0, 'publish_up' => null, 'publish_down' => null, 'version' => 1,
            'hits'        => 0, 'category_id' => 3, 'category_extension' => 'com_contact',
        ], $replace);
    }

    private function databaseReturning(?object $contact = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($contact);

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
