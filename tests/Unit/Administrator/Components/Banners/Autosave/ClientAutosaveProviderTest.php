<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_banners
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Banners\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Banners\Administrator\Autosave\ClientAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class ClientAutosaveProviderTest extends UnitTestCase
{
    public function testOwnsTheExactContractAndCanonicalTarget(): void
    {
        $provider = new ClientAutosaveProvider($this->databaseReturning());
        $this->assertSame('com_banners.client', $provider->getContext());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
        $this->assertSame('4294967295', $provider->canonicalizeTargetId('4294967295'));

        foreach (['', '0', '01', '-1', '4294967296'] as $invalid) {
            $this->assertSame('invalid_target', $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode());
        }
    }

    public function testNormalizesOnlyTheStrictAllowListWhileRetainingIncompleteBusinessValues(): void
    {
        $provider = new ClientAutosaveProvider($this->databaseReturning());
        $payload  = $this->payload();
        $this->assertSame($payload, $provider->normalizePayload($payload, 1));

        foreach (
            [
                array_diff_key($payload, ['email' => true]),
                [...$payload, 'state' => 1],
                [...$payload, 'own_prefix' => '1'],
                [...$payload, 'purchase_type' => 9],
                [...$payload, 'name' => str_repeat('x', 256)],
            ] as $invalid
        ) {
            $this->assertSame('invalid_payload', $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode());
        }
    }

    public function testBaseRevisionIgnoresCheckoutAndChangesForCanonicalContent(): void
    {
        $first    = new ClientAutosaveProvider($this->databaseReturning($this->client()));
        $checkout = new ClientAutosaveProvider($this->databaseReturning($this->client(['checked_out' => 99])));
        $changed  = new ClientAutosaveProvider($this->databaseReturning($this->client(['contact' => 'Changed'])));
        $this->assertSame($first->getBaseRevision('42'), $checkout->getBaseRevision('42'));
        $this->assertNotSame($first->getBaseRevision('42'), $changed->getBaseRevision('42'));
    }

    public function testAuthorizationEnforcesCoreEditAndCheckoutOwnership(): void
    {
        $allowed     = $this->createMock(User::class);
        $allowed->id = 7;
        $allowed->method('authorise')->with('core.edit', 'com_banners')->willReturn(true);
        (new ClientAutosaveProvider($this->databaseReturning($this->client(['checked_out' => 7]))))
            ->authorize($allowed, '42', AutosaveOperation::Preserve);

        $denied     = $this->createMock(User::class);
        $denied->id = 7;
        $denied->method('authorise')->willReturn(false);
        $this->assertSame(
            'forbidden',
            $this->failure(
                fn () => (new ClientAutosaveProvider($this->databaseReturning($this->client())))
                    ->authorize($denied, '42', AutosaveOperation::Read)
            )->getErrorCode()
        );

        $this->assertSame(
            'forbidden',
            $this->failure(
                fn () => (new ClientAutosaveProvider($this->databaseReturning($this->client(['checked_out' => 99]))))
                    ->authorize($allowed, '42', AutosaveOperation::Detect)
            )->getErrorCode()
        );
    }

    private function payload(): array
    {
        return ['name'       => '', 'contact' => '', 'email' => 'invalid@', 'extrainfo' => '', 'metakey' => '',
            'metakey_prefix' => '', 'version_note' => '', 'purchase_type' => 0, 'track_impressions' => -1,
            'track_clicks'   => 1, 'own_prefix' => 0];
    }

    private function client(array $replace = []): object
    {
        return (object) array_replace(['id' => 42, 'name' => 'Client', 'contact' => 'Contact', 'email' => 'a@example.test',
            'extrainfo'                     => '', 'state' => 1, 'checked_out' => 0, 'metakey' => '', 'own_prefix' => 0,
            'metakey_prefix'                => '', 'purchase_type' => 0, 'track_clicks' => 0, 'track_impressions' => 0], $replace);
    }

    private function databaseReturning(?object $client = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($client);
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
