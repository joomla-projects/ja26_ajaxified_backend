<?php

/**
 * @package     Joomla.IntegrationTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Administrator\Components\Autosave;

use Joomla\Database\DatabaseDriver;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/** Actual native table definitions and first-introduction migration on both CI database families. */
class AutosaveRegistrationTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

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
        $db = $this->getDBDriver();
        $db->setQuery('DELETE FROM #__extensions WHERE element = ' . $db->quote('com_autosave')
            . ' OR (element = ' . $db->quote('autosave') . ' AND folder = ' . $db->quote('task') . ')')->execute();
        $db->setQuery('DELETE FROM #__scheduler_tasks WHERE type = ' . $db->quote('autosave.cleanup'))->execute();
        $this->execute($this->migration());
    }

    public function testRegistrationIsIdempotentAndUsesNativeHourlyTaskDefaults(): void
    {
        $this->execute($this->migration());
        $db = $this->getDBDriver();
        foreach ([['component', 'com_autosave', ''], ['plugin', 'autosave', 'task']] as [$type, $element, $folder]) {
            $rows = $db->setQuery('SELECT * FROM #__extensions WHERE type = ' . $db->quote($type)
                . ' AND element = ' . $db->quote($element) . ' AND folder = ' . $db->quote($folder))->loadAssocList();
            $this->assertCount(1, $rows);
            $this->assertSame(1, (int) $rows[0]['enabled']);
            $this->assertSame(1, (int) $rows[0]['locked']);
            $this->assertSame($type === 'component' ? 1 : 0, (int) $rows[0]['protected']);
        }
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
            . ' WHERE folder = ' . $db->quote('task') . ' AND element = ' . $db->quote('autosave'))->execute();
        $this->execute($this->migration());
        $task   = $db->setQuery('SELECT state, execution_rules FROM #__scheduler_tasks WHERE type = ' . $db->quote('autosave.cleanup'))->loadAssoc();
        $plugin = $db->setQuery('SELECT enabled, params FROM #__extensions WHERE folder = ' . $db->quote('task')
            . ' AND element = ' . $db->quote('autosave'))->loadAssoc();
        $this->assertSame(0, (int) $task['state']);
        $this->assertSame($choice, $task['execution_rules']);
        $this->assertSame(0, (int) $plugin['enabled']);
        $this->assertSame($choice, $plugin['params']);
    }

    public function testFreshInstallationAndUpgradeCreateEquivalentDraftTablesAndIndexes(): void
    {
        $db   = $this->getDBDriver();
        $base = file_get_contents(JPATH_INSTALLATION . '/sql/' . $this->family() . '/base.sql');
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
