<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_menus
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Menus\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Menus\Administrator\Autosave\MenuAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class MenuAutosaveProviderTest extends UnitTestCase
{
    public function testExactContextIdentityAndPayloadContract(): void
    {
        $provider = new MenuAutosaveProvider($this->databaseReturning());
        $payload  = ['title' => '', 'description' => ''];

        $this->assertSame('com_menus.menu', $provider->getContext());
        $this->assertSame('4294967295', $provider->canonicalizeTargetId('4294967295'));
        $this->assertSame($payload, $provider->normalizePayload($payload, 1));

        foreach (['', '0', '-1', '01', ' 1', '1 ', '1.0', '4294967296'] as $invalid) {
            $this->assertSame('invalid_target', $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode());
        }

        foreach ([['title' => 'x'], [...$payload, 'menutype' => 'main'], ['title' => [], 'description' => ''], ['title' => str_repeat('x', 49), 'description' => ''], ['title' => '', 'description' => str_repeat('x', 256)], ['title' => "\xC3\x28", 'description' => '']] as $invalid) {
            $this->assertSame('invalid_payload', $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode());
        }
    }

    public function testExistenceAclAndRevisionUseAuthoritativeRecord(): void
    {
        $menu = (object) ['id' => 42, 'menutype' => 'mainmenu', 'title' => 'Main', 'description' => '', 'client_id' => 0];
        $user = $this->createMock(User::class);
        $user->expects($this->exactly(\count(AutosaveOperation::cases())))->method('authorise')->with('core.edit', 'com_menus.menu.42')->willReturn(true);
        $provider = new MenuAutosaveProvider($this->databaseReturning($menu));

        $this->assertTrue($provider->targetExists('42'));
        foreach (AutosaveOperation::cases() as $operation) {
            $provider->authorize($user, '42', $operation);
        }
        $this->assertSame($provider->getBaseRevision('42'), $provider->getBaseRevision('42'));
        $changed = new MenuAutosaveProvider($this->databaseReturning((object) [...(array) $menu, 'menutype' => 'changed']));
        $this->assertNotSame($provider->getBaseRevision('42'), $changed->getBaseRevision('42'));

        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);
        $this->assertSame('forbidden', $this->failure(fn () => $provider->authorize($denied, '42', AutosaveOperation::Read))->getErrorCode());
        $this->assertSame('target_not_found', $this->failure(fn () => (new MenuAutosaveProvider($this->databaseReturning()))->authorize($user, '42', AutosaveOperation::Read))->getErrorCode());
    }

    private function databaseReturning(?object $menu = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($menu);
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
