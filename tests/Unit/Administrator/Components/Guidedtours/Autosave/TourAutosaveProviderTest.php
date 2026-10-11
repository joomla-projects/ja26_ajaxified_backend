<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_guidedtours
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Guidedtours\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Guidedtours\Administrator\Autosave\TourAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class TourAutosaveProviderTest extends UnitTestCase
{
    public function testOwnsExactContextAndStrictPositiveTarget(): void
    {
        $provider = new TourAutosaveProvider($this->databaseReturning());

        $this->assertSame('com_guidedtours.tour', $provider->getContext());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
        $this->assertSame('4294967295', $provider->canonicalizeTargetId('4294967295'));

        foreach (['', '0', '01', '-1', '1.0', ' 1', '1 ', 'abc', '1a', '4294967296', '999999999999999999999'] as $invalid) {
            $this->assertSame('invalid_target', $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode());
        }
    }

    public function testNormalizesOnlyExactSafePayloadAndPreservesIncompleteValues(): void
    {
        $provider = new TourAutosaveProvider($this->databaseReturning());
        $payload  = $this->payload();

        $this->assertSame($payload, $provider->normalizePayload($payload, 1));

        $invalidPayloads = [
            array_diff_key($payload, ['url' => true]),
            [...$payload, 'published' => 1],
            [...$payload, 'autostart' => '1'],
            [...$payload, 'autostart' => 2],
            [...$payload, 'title' => str_repeat('x', 256)],
            [...$payload, 'uid' => str_repeat('x', 256)],
            [...$payload, 'description' => str_repeat('x', 65536)],
            [...$payload, 'note' => []],
            [...$payload, 'url' => "\xC3\x28"],
        ];

        foreach ($invalidPayloads as $invalid) {
            $this->assertSame('invalid_payload', $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode());
        }
    }

    public function testAuthorizationEnforcesExistenceEditOwnAndCheckoutForEveryOperation(): void
    {
        $owner     = $this->createMock(User::class);
        $owner->id = 7;
        $owner->method('authorise')->willReturnMap([
            ['core.edit', 'com_guidedtours', false],
            ['core.edit.own', 'com_guidedtours', true],
        ]);

        foreach (AutosaveOperation::cases() as $operation) {
            (new TourAutosaveProvider($this->databaseReturning($this->tour(['created_by' => 7, 'checked_out' => 7]))))
                ->authorize($owner, '42', $operation);
        }

        $denied     = $this->createMock(User::class);
        $denied->id = 8;
        $denied->method('authorise')->willReturn(false);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => (new TourAutosaveProvider($this->databaseReturning($this->tour())))
                ->authorize($denied, '42', AutosaveOperation::Read))->getErrorCode()
        );
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => (new TourAutosaveProvider($this->databaseReturning($this->tour(['checked_out' => 99]))))
                ->authorize($owner, '42', AutosaveOperation::Preserve))->getErrorCode()
        );
        $this->assertSame(
            'target_not_found',
            $this->failure(fn () => (new TourAutosaveProvider($this->databaseReturning()))
                ->authorize($owner, '42', AutosaveOperation::Detect))->getErrorCode()
        );
    }

    public function testBaseRevisionIsDeterministicAndCheckoutIndependent(): void
    {
        $first    = new TourAutosaveProvider($this->databaseReturning($this->tour()));
        $checkout = new TourAutosaveProvider($this->databaseReturning($this->tour(['checked_out' => 99])));
        $changed  = new TourAutosaveProvider($this->databaseReturning($this->tour(['description' => 'Changed'])));

        $this->assertSame($first->getBaseRevision('42'), $first->getBaseRevision('42'));
        $this->assertSame($first->getBaseRevision('42'), $checkout->getBaseRevision('42'));
        $this->assertNotSame($first->getBaseRevision('42'), $changed->getBaseRevision('42'));
    }

    private function payload(): array
    {
        return ['title' => '', 'uid' => '', 'description' => '<p>Draft</p>', 'note' => '', 'url' => 'https://', 'autostart' => 0];
    }

    private function tour(array $replace = []): object
    {
        return (object) array_replace([
            'id'         => 42, 'title' => 'Tour', 'uid' => 'tour', 'description' => '<p>Tour</p>', 'note' => '',
            'url'        => 'index.php', 'autostart' => 0, 'published' => 1, 'access' => 1, 'language' => '*',
            'extensions' => '["*"]', 'created_by' => 7, 'checked_out' => 0,
        ], $replace);
    }

    private function databaseReturning(?object $tour = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($tour);

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
