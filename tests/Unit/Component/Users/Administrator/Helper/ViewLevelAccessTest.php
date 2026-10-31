<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Users\Administrator\Helper;

use Joomla\CMS\User\User;
use Joomla\Component\Users\Administrator\Helper\UsersHelper;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;
use Joomla\Tests\Unit\UnitTestCase;

/** Native view-level policy is independent of the ordering of group IDs. */
class ViewLevelAccessTest extends UnitTestCase
{
    use NativeReadFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        \Joomla\CMS\Helper\UserGroupsHelper::getInstance()->loadAll();
    }

    protected function tearDown(): void
    {
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    /** @dataProvider levels */
    public function testProtectedGroupsAreCheckedAtEveryPosition(array $rules, bool $administrator, bool $allowed): void
    {
        $user = $this->createMock(User::class);
        $user->method('authorise')->willReturn($administrator);
        $this->assertSame($allowed, UsersHelper::canEditViewLevel($user, $rules));
    }

    public static function levels(): array
    {
        return [[[1], false, true], [[42, 1], false, false], [[1, 42], false, false], [[1, 42], true, true], [[], false, true]];
    }
}
