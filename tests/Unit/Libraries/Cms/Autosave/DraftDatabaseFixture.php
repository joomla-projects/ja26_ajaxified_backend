<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseDriver;

/**
 * Ephemeral fixtures derive their schema from the real native installation and upgrade SQL.
 */
trait DraftDatabaseFixture
{
    private DraftStore $store;
    private Date $now;

    private function installSchema(string $engine): void
    {
        $base   = file_get_contents(JPATH_INSTALLATION . '/sql/' . $engine . '/base.sql');
        $quotes = $engine === 'mysql' ? \chr(96) : '"';
        $table  = preg_quote($quotes . '#__users' . $quotes, '/');
        preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \([\s\S]*?\n\)' . ($engine === 'mysql' ? '[^;]*' : '') . ';/', $base, $match);
        $sql = $match[0];

        // Apply the real upgrade once per native connection, including its PostgreSQL indexes.
        if (!\in_array($this->db->getPrefix() . 'autosave_drafts', $this->db->getTableList(), true)) {
            $update = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_admin/sql/updates/' . $engine . '/6.2.0-2026-10-11.sql');
            foreach (DatabaseDriver::splitSql($update) as $query) {
                if (str_contains($query, '#__autosave_drafts') && preg_match('/\bCREATE (?:TABLE|(?:UNIQUE )?INDEX)\b/', $query)) {
                    $sql .= "\n" . $query . ';';
                }
            }
        }
        if ($this->db->getServerType() === 'sqlite') {
            $sql = preg_replace('/\b(?:bigserial|serial)\b/', 'integer', $sql);
        }
        foreach (DatabaseDriver::splitSql(str_replace('#__', $this->db->getPrefix(), $sql)) as $query) {
            $this->db->setQuery($query)->execute();
        }
        foreach ([7, 8] as $id) {
            if (!$this->db->setQuery('SELECT id FROM #__users WHERE id = ' . $id)->loadResult()) {
                $owner = (object) ['id' => $id, 'username' => 'draft-test-' . $id, 'registerDate' => '2026-10-11 00:00:00', 'params' => '{}'];
                $this->db->insertObject('#__users', $owner);
            }
        }
        $this->db->setQuery('DELETE FROM #__autosave_drafts')->execute();
        $this->now   = new Date('2026-10-11 00:00:00', 'UTC');
        $this->store = new DraftStore($this->db);
    }
}
