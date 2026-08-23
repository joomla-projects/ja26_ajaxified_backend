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
use Joomla\Component\Guidedtours\Administrator\Autosave\StepAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class StepAutosaveProviderTest extends UnitTestCase
{
    public function testStrictIdentityAndExactTypedPayload(): void
    {
        $provider = new StepAutosaveProvider($this->databaseReturning());
        $payload  = $this->payload();
        $this->assertSame('com_guidedtours.step', $provider->getContext());
        $this->assertSame($payload, $provider->normalizePayload($payload, 1));
        foreach (['', '0', '-1', '01', ' 1', '1 ', '1.0', '4294967296'] as $invalid) {
            $this->assertSame('invalid_target', $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode());
        }
        foreach ([array_diff_key($payload, ['note' => true]), [...$payload, 'tour_id' => 1], [...$payload, 'position' => 'middle'], [...$payload, 'type' => '2'], [...$payload, 'interactive_type' => 7], [...$payload, 'required' => 2], [...$payload, 'target' => str_repeat('x', 256)], [...$payload, 'description' => str_repeat('x', 65536)], [...$payload, 'url' => "\xC3\x28"]] as $invalid) {
            $this->assertSame('invalid_payload', $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode());
        }
    }

    public function testOwningTourAclCheckoutAndRevisionAreAuthoritative(): void
    {
        $step     = $this->step();
        $user     = $this->createMock(User::class);
        $user->id = 7;
        $user->method('authorise')->willReturnMap([['core.edit', 'com_guidedtours', false], ['core.edit.own', 'com_guidedtours', true]]);
        $provider = new StepAutosaveProvider($this->databaseReturning($step));
        foreach (AutosaveOperation::cases() as $operation) {
            $provider->authorize($user, '42', $operation);
        }
        $this->assertSame($provider->getBaseRevision('42'), $provider->getBaseRevision('42'));
        $changed = new StepAutosaveProvider($this->databaseReturning($this->step(['tour_id' => 9])));
        $this->assertNotSame($provider->getBaseRevision('42'), $changed->getBaseRevision('42'));
        $this->assertSame('forbidden', $this->failure(fn () => (new StepAutosaveProvider($this->databaseReturning($this->step(['checked_out' => 99]))))->authorize($user, '42', AutosaveOperation::Read))->getErrorCode());
        $this->assertSame('target_not_found', $this->failure(fn () => (new StepAutosaveProvider($this->databaseReturning()))->authorize($user, '42', AutosaveOperation::Read))->getErrorCode());
    }

    private function payload(): array
    {
        return ['position' => 'center', 'target' => '', 'title' => '', 'description' => '', 'type' => 2, 'url' => '', 'interactive_type' => 1, 'note' => '', 'required' => 1, 'requiredvalue' => ''];
    }

    private function step(array $replace = []): object
    {
        return (object) array_replace(['id' => 42, 'tour_id' => 8, 'title' => 'Step', 'description' => '', 'position' => 'center', 'target' => '', 'type' => 2, 'interactive_type' => 1, 'url' => '', 'note' => '', 'params' => '{"required":1,"requiredvalue":""}', 'published' => 1, 'language' => '*', 'created_by' => 7, 'checked_out' => 0], $replace);
    }

    private function databaseReturning(?object $step = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($step);
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
