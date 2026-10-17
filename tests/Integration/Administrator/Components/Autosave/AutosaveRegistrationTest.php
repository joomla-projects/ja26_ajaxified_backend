<?php

/**
 * @package     Joomla.IntegrationTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Administrator\Components\Autosave;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\Database\DatabaseDriver;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/** Actual native table definitions and first-introduction migration on both CI database families. */
class AutosaveRegistrationTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    private mixed $previousDatabase;
    private mixed $previousContainer;
    private mixed $previousApplication;

    public function getSchemasToLoad(): array
    {
        return ['autosave.sql'];
    }

    private function family(): string
    {
        return $this->getDBDriver()->getServerType() === 'mysql' ? 'mysql' : 'postgresql';
    }

    private function execute(string $sql): void
    {
        foreach (DatabaseDriver::splitSql($sql) as $query) {
            $this->getDBDriver()->setQuery($query)->execute();
        }
    }

    private function migration(): string
    {
        return file_get_contents(JPATH_ADMINISTRATOR . '/components/com_admin/sql/updates/' . $this->family() . '/6.2.0-2026-10-11.sql');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db                        = $this->getDBDriver();
        $this->previousDatabase    = Factory::$database;
        Factory::$database         = $db;
        $containerProperty         = new \ReflectionProperty(Factory::class, 'container');
        $this->previousContainer   = $containerProperty->getValue();
        $container                 = new \Joomla\DI\Container();
        $container->share(\Joomla\Database\DatabaseInterface::class, $db);
        $containerProperty->setValue(null, $container);
        $this->previousApplication = Factory::$application;
        $application               = $this->createMock(\Joomla\CMS\Application\CMSApplication::class);
        $application->method('getDispatcher')->willReturn(new \Joomla\Event\Dispatcher());
        Factory::$application = $application;
        $db->setQuery('DELETE FROM #__assets')->execute();
        foreach ([[1, 0, 0, 3, 0, 'root.1'], [73, 1, 1, 2, 1, 'com_scheduler']] as [$id, $parent, $left, $right, $level, $name]) {
            $asset = (object) ['id' => $id, 'parent_id' => $parent, 'lft' => $left, 'rgt' => $right, 'level' => $level, 'name' => $name, 'title' => $name, 'rules' => '{}'];
            $db->insertObject('#__assets', $asset);
        }
        if ($this->family() === 'postgresql') {
            $db->setQuery("SELECT setval('#__assets_id_seq', 1000, false)")->execute();
        }
        $db->setQuery('DELETE FROM #__extensions WHERE element = ' . $db->quote('com_autosave')
            . ' OR (element = ' . $db->quote('autosave') . ' AND folder IN (' . $db->quote('task') . ',' . $db->quote('system') . '))')->execute();
        $db->setQuery('DELETE FROM #__scheduler_tasks WHERE type = ' . $db->quote('autosave.cleanup'))->execute();
        $this->execute($this->migration());
    }

    protected function tearDown(): void
    {
        Factory::$database    = $this->previousDatabase;
        Factory::$application = $this->previousApplication;
        (new \ReflectionProperty(Factory::class, 'container'))->setValue(null, $this->previousContainer);
        parent::tearDown();
    }

    private function updateAssets(): void
    {
        require_once JPATH_ADMINISTRATOR . '/components/com_admin/script.php';
        $installer = $this->createMock(Installer::class);
        $installer->expects($this->never())->method('abort');
        $script = new \JoomlaInstallerScript();
        $script->setErrorCollector(function (string $context, \Throwable $error): void {
            $this->fail($context . ': ' . $error->getMessage());
        });
        $this->assertTrue($script->updateAssets($installer));
    }

    public function testNativeUpgradeCreatesTheTaskAssetUnderTheExistingScheduler(): void
    {
        $db     = $this->getDBDriver();
        $before = $db->setQuery("SELECT * FROM #__scheduler_tasks WHERE type = 'autosave.cleanup'")->loadAssoc();
        $this->updateAssets();
        $after = $db->setQuery("SELECT * FROM #__scheduler_tasks WHERE type = 'autosave.cleanup'")->loadAssoc();
        $this->assertGreaterThan(0, (int) $after['asset_id']);
        $asset = $db->setQuery('SELECT * FROM #__assets WHERE id = ' . (int) $after['asset_id'])->loadAssoc();
        $this->assertSame('com_scheduler.task.' . $after['id'], $asset['name']);
        $this->assertSame(73, (int) $asset['parent_id']);
        $this->assertSame(2, (int) $asset['level']);
        $parent = $db->setQuery('SELECT lft, rgt FROM #__assets WHERE id = 73')->loadAssoc();
        $this->assertGreaterThan((int) $parent['lft'], (int) $asset['lft']);
        $this->assertLessThan((int) $parent['rgt'], (int) $asset['rgt']);
        unset($before['asset_id'], $after['asset_id']);
        $this->assertSame($before, $after, 'Creating permissions must not rewrite task settings or schedule.');
    }

    public function testRepeatedNativeUpgradePreservesTaskRulesAndRepairsOnlyTheAssetLink(): void
    {
        $db = $this->getDBDriver();
        $this->updateAssets();
        $task  = $db->setQuery("SELECT * FROM #__scheduler_tasks WHERE type = 'autosave.cleanup'")->loadAssoc();
        $rules = '{"core.edit":{"3":false}}';
        $db->setQuery('UPDATE #__assets SET rules = ' . $db->quote($rules) . ' WHERE id = ' . (int) $task['asset_id'])->execute();
        $db->setQuery("UPDATE #__scheduler_tasks SET asset_id = 0, state = 0, params = '{\"custom_choice\":true}' WHERE id = " . (int) $task['id'])->execute();
        $this->execute($this->migration());
        $this->updateAssets();
        $this->updateAssets();
        $after = $db->setQuery("SELECT * FROM #__scheduler_tasks WHERE type = 'autosave.cleanup'")->loadAssoc();
        $this->assertSame((int) $task['asset_id'], (int) $after['asset_id']);
        $this->assertSame(0, (int) $after['state']);
        $this->assertSame('{"custom_choice":true}', $after['params']);
        $this->assertSame($rules, $db->setQuery('SELECT rules FROM #__assets WHERE id = ' . (int) $after['asset_id'])->loadResult());
        $this->assertSame(1, (int) $db->setQuery('SELECT COUNT(*) FROM #__assets WHERE name = ' . $db->quote('com_scheduler.task.' . $task['id']))->loadResult());
    }

    public function testRegistrationIsIdempotentAndUsesNativeHourlyTaskDefaults(): void
    {
        $this->execute($this->migration());
        $db = $this->getDBDriver();
        foreach ([['plugin', 'autosave', 'system'], ['plugin', 'autosave', 'task']] as [$type, $element, $folder]) {
            $rows = $db->setQuery('SELECT * FROM #__extensions WHERE type = ' . $db->quote($type)
                . ' AND element = ' . $db->quote($element) . ' AND folder = ' . $db->quote($folder))->loadAssocList();
            $this->assertCount(1, $rows);
            $this->assertSame(1, (int) $rows[0]['enabled']);
            $this->assertSame(1, (int) $rows[0]['locked']);
            $this->assertSame(0, (int) $rows[0]['protected']);
        }
        $this->assertSame(0, (int) $db->setQuery('SELECT COUNT(*) FROM #__extensions WHERE element = ' . $db->quote('com_autosave'))->loadResult());
        $tasks = $db->setQuery('SELECT * FROM #__scheduler_tasks WHERE type = ' . $db->quote('autosave.cleanup'))->loadAssocList();
        $this->assertCount(1, $tasks);
        $this->assertSame(1, (int) $tasks[0]['state']);
        $this->assertSame('PT1H', json_decode($tasks[0]['cron_rules'], true)['exp']);
    }

    public function testExistingAdministratorTaskAndPluginChoicesArePreserved(): void
    {
        $db     = $this->getDBDriver();
        $choice = '{"administrator_choice":true}';
        $db->setQuery('UPDATE #__scheduler_tasks SET state = 0, execution_rules = ' . $db->quote($choice)
            . ' WHERE type = ' . $db->quote('autosave.cleanup'))->execute();
        $db->setQuery('UPDATE #__extensions SET enabled = 0, params = ' . $db->quote($choice)
            . ' WHERE folder IN (' . $db->quote('task') . ',' . $db->quote('system') . ') AND element = ' . $db->quote('autosave'))->execute();
        $this->execute($this->migration());
        $task    = $db->setQuery('SELECT state, execution_rules FROM #__scheduler_tasks WHERE type = ' . $db->quote('autosave.cleanup'))->loadAssoc();
        $plugins = $db->setQuery('SELECT enabled, params FROM #__extensions WHERE folder IN (' . $db->quote('task') . ',' . $db->quote('system')
            . ') AND element = ' . $db->quote('autosave'))->loadAssocList();
        $this->assertSame(0, (int) $task['state']);
        $this->assertSame($choice, $task['execution_rules']);
        $this->assertCount(2, $plugins);
        foreach ($plugins as $plugin) {
            $this->assertSame(0, (int) $plugin['enabled']);
            $this->assertSame($choice, $plugin['params']);
        }
    }

    public function testNativeFreshPluginSeedsMatchUpgradeRegistration(): void
    {
        $db        = $this->getDBDriver();
        $selection = ' FROM #__extensions WHERE element = ' . $db->quote('autosave')
            . ' AND type = ' . $db->quote('plugin') . ' AND folder IN (' . $db->quote('system') . ',' . $db->quote('task') . ')';
        $columns  = 'name, type, element, folder, client_id, enabled, access, protected, locked, params';
        $expected = $db->setQuery('SELECT ' . $columns . $selection . ' ORDER BY folder')->loadAssocList();
        $db->setQuery('DELETE' . $selection)->execute();
        $base = file_get_contents(JPATH_INSTALLATION . '/sql/' . $this->family() . '/base.sql');
        foreach (DatabaseDriver::splitSql($base) as $query) {
            if (!str_contains($query, 'INSERT INTO') || !str_contains($query, "'plg_system_autosave'")) {
                continue;
            }
            [$header, $values] = explode('VALUES', $query, 2);
            $rows              = [];
            foreach (explode("\n", $values) as $row) {
                if (str_contains($row, "'plg_system_autosave'") || str_contains($row, "'plg_task_autosave'")) {
                    $rows[] = rtrim(trim($row), ',;');
                }
            }
            $this->assertCount(2, $rows);
            // Execute the exact native column list and feature seed rows without unrelated seeds.
            $this->execute($header . 'VALUES ' . implode(',', $rows));
        }
        $this->assertCount(2, $expected);
        $this->assertSame($expected, $db->setQuery('SELECT ' . $columns . $selection . ' ORDER BY folder')->loadAssocList());
    }

    public function testFreshInstallationAndUpgradeCreateEquivalentDraftTablesAndIndexes(): void
    {
        $db   = $this->getDBDriver();
        $base = file_get_contents(JPATH_INSTALLATION . '/sql/' . $this->family() . '/extensions.sql');
        foreach (DatabaseDriver::splitSql($base) as $query) {
            if (str_contains($query, '#__autosave_drafts') && preg_match('/\bCREATE (?:TABLE|(?:UNIQUE )?INDEX)\b/', $query)) {
                $this->execute(str_replace('#__autosave_', '#__fresh_autosave_', $query));
            }
        }
        $this->assertSame($db->getTableColumns('#__autosave_drafts'), $db->getTableColumns('#__fresh_autosave_drafts'));
        $indexes = static function (string $table) use ($db): array {
            $result = [];
            foreach ($db->getTableKeys($table) as $key) {
                $values   = array_intersect_key((array) $key, array_flip(['Key_name', 'Column_name', 'Non_unique', 'Seq_in_index', 'Index_type', 'idxName', 'isPrimary', 'isUnique', 'indKey']));
                $result[] = str_replace('fresh_', '', json_encode($values, JSON_THROW_ON_ERROR));
            }
            sort($result);
            return $result;
        };
        $this->assertNotEmpty($indexes('#__autosave_drafts'));
        $this->assertSame($indexes('#__autosave_drafts'), $indexes('#__fresh_autosave_drafts'));
        foreach (['autosave_continuations', 'autosave_generations', 'autosave_canonical_actions'] as $retired) {
            $this->assertNotContains($db->getPrefix() . $retired, $db->getTableList());
        }
    }
}
