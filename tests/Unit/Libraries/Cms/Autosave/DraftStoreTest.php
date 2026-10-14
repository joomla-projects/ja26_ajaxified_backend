<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Real SQL on an ephemeral native driver, using installation-derived schema.
 */
class DraftStoreTest extends UnitTestCase
{
    use DraftStoreCases;

    private DatabaseInterface $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'draft_test_']);
        $this->installSchema('postgresql');
    }
}
