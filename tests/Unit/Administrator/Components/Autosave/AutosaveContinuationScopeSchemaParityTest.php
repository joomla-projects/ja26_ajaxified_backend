<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards parity between the Autosave storage SQL and the install schema.
 *
 * The PR34 static creation scope is anchored in a continuation column that storage reads
 * and writes. An install whose #__autosave_continuations table predates that column fails
 * every scope-bound provisional operation with a server error, which the browser surfaces
 * as a retryable "Draft save interrupted" state. This test keeps the install schema and
 * the storage queries in lock-step so the migration requirement cannot silently drift.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveContinuationScopeSchemaParityTest extends UnitTestCase
{
    public function testMysqlAndPostgresInstallSchemaContainTheContinuationScopeColumn(): void
    {
        foreach (['mysql', 'postgresql'] as $driver) {
            $sql = file_get_contents(JPATH_ROOT . '/installation/sql/' . $driver . '/base.sql');
            $this->assertIsString($sql, $driver . ' base schema is readable.');

            $this->assertMatchesRegularExpression(
                '/CREATE TABLE IF NOT EXISTS (?:`|")#__autosave_continuations(?:`|")[\s\S]*?create_scope[\s\S]*?;/',
                $sql,
                $driver . ' install schema must define create_scope on #__autosave_continuations.'
            );
        }
    }

    public function testContinuationScopeColumnIsDeclaredBeforeTheActivityTimestamp(): void
    {
        // The column belongs to the bounded identity block of the continuation row, so a
        // live install applies the same ALTER that the fresh-install DDL encodes.
        $mysql = file_get_contents(JPATH_ROOT . '/installation/sql/mysql/base.sql');
        $block = $this->continuationBlock($mysql);

        $this->assertStringContainsString('`create_scope` varbinary(1020)', $block);
        $this->assertLessThan(
            strpos($block, '`last_activity_at`'),
            strpos($block, '`create_scope`')
        );

        $postgres = file_get_contents(JPATH_ROOT . '/installation/sql/postgresql/base.sql');
        $pBlock   = $this->continuationBlock($postgres);

        $this->assertStringContainsString('"create_scope" varchar(255)', $pBlock);
        $this->assertLessThan(
            strpos($pBlock, '"last_activity_at"'),
            strpos($pBlock, '"create_scope"')
        );
    }

    public function testStorageBindsAndReadsExactlyTheDeclaredColumn(): void
    {
        $storage = file_get_contents(JPATH_ROOT . '/libraries/src/Autosave/AutosaveStorage.php');
        $this->assertIsString($storage);

        $this->assertStringContainsString("quoteName('create_scope')", $storage);
        $this->assertStringContainsString('bindContinuationStaticScope', $storage);
        $this->assertStringContainsString('getContinuationStaticScope', $storage);
    }

    public function testExistingInstallsReceiveTheColumnThroughCoreUpdateSql(): void
    {
        // Joomla core delivers schema changes to existing installs from
        // administrator/components/com_admin/sql/updates/<driver>/<version>.sql. The PR34
        // column must ship there for both drivers, otherwise fresh installs diverge from
        // upgraded installs and every scope-bound operation fails with a server error.
        $mysql = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/com_admin/sql/updates/mysql/6.2.0-2026-09-03.sql'
        );
        $this->assertIsString($mysql, 'The MySQL core update file is missing.');
        $this->assertMatchesRegularExpression(
            '/ALTER TABLE `#__autosave_continuations`\s+ADD COLUMN `create_scope` varbinary\(1020\) NULL AFTER `initialization_key`;/',
            $mysql
        );

        $postgres = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/com_admin/sql/updates/postgresql/6.2.0-2026-09-03.sql'
        );
        $this->assertIsString($postgres, 'The PostgreSQL core update file is missing.');
        $this->assertMatchesRegularExpression(
            '/ALTER TABLE "#__autosave_continuations"\s+ADD COLUMN "create_scope" varchar\(255\);/',
            $postgres
        );
    }

    private function continuationBlock(string $sql): string
    {
        preg_match('/CREATE TABLE IF NOT EXISTS (?:`|")#__autosave_continuations(?:`|")[\s\S]*?\)[^;]*;/', $sql, $matches);

        $this->assertNotEmpty($matches, 'The #__autosave_continuations DDL block is missing.');

        return $matches[0];
    }
}
