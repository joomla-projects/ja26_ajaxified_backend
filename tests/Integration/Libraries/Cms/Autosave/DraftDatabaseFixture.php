<?php

/**
 * @package     Joomla.IntegrationTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\DraftStore;
use Joomla\CMS\Date\Date;
use Joomla\Tests\Integration\DBTestTrait;

/**
 * Ephemeral fixtures derive their schema from the real native installation and upgrade SQL.
 */
trait DraftDatabaseFixture
{
    use DBTestTrait;

    private DraftStore $store;
    private Date $now;

    public function getSchemasToLoad(): array
    {
        return ['autosave.sql'];
    }

    private function initializeStore(): void
    {
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
