<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\UserGroupsHelper;
use Joomla\Database\Mysqli\MysqliQuery;

/**
 * Native registry/layout/ACL reads through a database double, without a live installation.
 * This fixture does not establish database-backed installation or authorization acceptance.
 */
trait NativeReadFixture
{
    private mixed $previousNativeDatabase;

    private function installNativeReadFixture(): void
    {
        $this->previousNativeDatabase = Factory::$database;
        $database = $this->createMock(\Joomla\Database\DatabaseDriver::class);
        $database->method('createQuery')->willReturnCallback(fn () => new MysqliQuery($database));
        $database->method('quoteName')->willReturnCallback(static fn ($name, $alias = null) => $name);
        $database->method('quote')->willReturnCallback(static fn ($value) => "'" . $value . "'");
        $query = '';
        $database->method('setQuery')->willReturnCallback(static function ($value) use (&$query, $database) {
            $query = (string) $value;

            return $database;
        });
        $components = [];
        foreach (glob(JPATH_ADMINISTRATOR . '/components/com_*', GLOB_ONLYDIR) as $path) {
            $components[] = (object) ['id' => \count($components) + 1, 'option' => basename($path), 'params' => '{}', 'enabled' => 1, 'custom_data' => '{}'];
        }
        $database->method('getIterator')->willReturnCallback(static fn () => new \ArrayIterator($components));
        $database->method('loadObjectList')->willReturnCallback(static function () use (&$query): array {
            if (str_contains($query, '#__assets')) {
                return [(object) ['id' => 1, 'name' => 'root.1', 'rules' => '{"core.admin":{"42":true}}', 'parent_id' => 0]];
            }
            if (str_contains($query, '#__usergroups')) {
                return [1 => (object) ['id' => 1, 'parent_id' => 0, 'title' => 'Public', 'lft' => 1, 'rgt' => 4],
                    42 => (object) ['id' => 42, 'parent_id' => 1, 'title' => 'Protected group', 'lft' => 2, 'rgt' => 3]];
            }

            throw new \LogicException('Unexpected native read in the unit fixture: ' . $query);
        });
        Factory::$database = $database;
        Access::clearStatics();
        UserGroupsHelper::getInstance()->setGroups([]);
    }

    private function restoreNativeReadFixture(): void
    {
        Factory::$database = $this->previousNativeDatabase;
        Access::clearStatics();
        UserGroupsHelper::getInstance()->setGroups([]);
    }
}
