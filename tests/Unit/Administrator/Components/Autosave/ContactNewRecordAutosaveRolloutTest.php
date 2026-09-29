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
use Joomla\Component\Contact\Administrator\Autosave\ContactAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR35 new-record Autosave rollout for com_contact.contact.
 *
 * @since  __DEPLOY_VERSION__
 */
class ContactNewRecordAutosaveRolloutTest extends UnitTestCase
{
    public function testContactProviderExposesTheCreateCapability(): void
    {
        $provider = new ContactAutosaveProvider($this->database());

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertSame('com_contact.contact', $provider->getContext());
        $this->assertSame('contact-create-v1', $provider->getCreateContractVersion());
        $this->assertSame(2, $provider->getPayloadSchemaVersion());
    }

    public function testContactCreateAuthorizationMirrorsNativeAllowAdd(): void
    {
        $provider = new ContactAutosaveProvider($this->database());

        // Global component create right.
        $global = $this->createMock(User::class);
        $global->method('authorise')->willReturn(true);
        $provider->authorizeCreate($global, AutosaveOperation::Preserve, null);

        // Category-scoped create right only is enough for the blank form.
        $scoped = $this->createMock(User::class);
        $scoped->method('authorise')->willReturn(false);
        $scoped->method('getAuthorisedCategories')->willReturn([3]);
        $provider->authorizeCreate($scoped, AutosaveOperation::Preserve, null);

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

    public function testContactCategoryAnchoringIsServerVerifiedAndScoped(): void
    {
        $payload = $this->payload();
        $allowed = $this->user(true);

        $provider = new ContactAutosaveProvider($this->database(['result' => 1]));
        $provider->authorizeCreate($allowed, AutosaveOperation::Preserve, $payload);

        // A category that does not exist (or belongs to another extension) is malformed.
        $missing = new ContactAutosaveProvider($this->database(['result' => 0]));
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $missing->authorizeCreate($allowed, AutosaveOperation::Preserve, $payload))->getErrorCode()
        );

        // Category-level create right is required once the draft is anchored.
        $categoryDenied = $this->createMock(User::class);
        $categoryDenied->method('authorise')->willReturnCallback(
            static fn (string $asset) => $asset === 'com_contact'
        );
        $categoryDenied->method('getAuthorisedCategories')->willReturn([]);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $provider->authorizeCreate($categoryDenied, AutosaveOperation::Preserve, $payload))->getErrorCode()
        );

        // Permission revocation after P1 fails closed on every later operation.
        foreach (AutosaveOperation::cases() as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeCreate($categoryDenied, $operation, $payload))->getErrorCode()
            );
        }
    }

    public function testContactPayloadCarriesOnlyTheMinimizedAllowListPlusCategory(): void
    {
        $provider = new ContactAutosaveProvider($this->database());
        $valid    = $this->payload();

        $this->assertSame($valid, $provider->normalizePayload($valid, 2));

        // Version 1 drafts are intentionally not read by the evolved schema.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload($valid, 1))->getErrorCode()
        );

        // Sensitive, stateful and derived values never ride a draft.
        foreach (
            [
            [...$valid, 'user_id' => 7],
            [...$valid, 'published' => 1],
            [...$valid, 'access' => 1],
            [...$valid, 'params' => []],
            [...$valid, 'com_fields' => []],
            ] as $smuggled
        ) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($smuggled, 2))->getErrorCode()
            );
        }

        foreach (
            [
            [...$valid, 'catid' => 0],
            [...$valid, 'catid' => -1],
            [...$valid, 'catid' => '3'],
            [...$valid, 'catid' => 2147483648],
            array_diff_key($valid, ['catid' => true]),
            ] as $invalid
        ) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 2))->getErrorCode()
            );
        }

        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload([...$valid, 'image' => 'blob:temporary'], 2))->getErrorCode()
        );
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload([...$valid, 'suburb' => str_repeat('x', 101)], 2))->getErrorCode()
        );
    }

    public function testContactExistingRecordBehaviorIsUnchanged(): void
    {
        $row      = (object) ['id' => 73, 'name' => 'Person', 'catid' => 3, 'created_by' => 7, 'checked_out' => 0, 'category_id' => 3, 'category_extension' => 'com_contact'];
        $provider = new ContactAutosaveProvider($this->database(['row' => $row, 'result' => 1]));

        $this->assertTrue($provider->targetExists('73'));
        $this->assertSame('73', $provider->canonicalizeTargetId('73'));

        $editor = $this->createMock(User::class);
        $editor->method('authorise')->willReturn(true);
        $provider->authorize($editor, '73', AutosaveOperation::Read);
        $this->assertStringStartsWith('autosave:com_contact.contact:base-revision:v1:', $provider->getBaseRevision('73'));
    }

    private function payload(): array
    {
        return array_merge(array_fill_keys([
            'name', 'alias', 'version_note', 'misc', 'image', 'con_position', 'email_to', 'address',
            'suburb', 'state', 'postcode', 'country', 'telephone', 'mobile', 'fax', 'webpage',
            'sortname1', 'sortname2', 'sortname3', 'publish_up', 'publish_up_alt', 'publish_down',
            'publish_down_alt', 'metakey', 'metadesc',
        ], ''), ['catid' => 3]);
    }

    private function user(bool $allowed): User
    {
        $user = $this->createMock(User::class);
        $user->method('authorise')->willReturn($allowed);
        $user->method('getAuthorisedCategories')->willReturn([]);

        return $user;
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('quote')->willReturnCallback(static fn ($value) => "'" . $value . "'");
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(
            \array_key_exists('row', $options) ? $options['row'] : (object) ['id' => 73, 'name' => 'Person', 'catid' => 3, 'created_by' => 7, 'checked_out' => 0, 'category_id' => 3, 'category_extension' => 'com_contact']
        );
        $db->method('loadResult')->willReturn($options['result'] ?? 1);

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
