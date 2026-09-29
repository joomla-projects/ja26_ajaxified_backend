<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Administrator\Components\Autosave;

use Joomla\Database\DatabaseDriver;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Executes the core update registration on each supported integration database family.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveRegistrationTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    public function getSchemasToLoad(): array
    {
        return ['autosave_registration.sql'];
    }

    public function testUpdateRegistersExactlyOneEnabledProtectedAdministratorComponent(): void
    {
        $serverType = $this->getDBDriver()->getServerType();
        $folder     = match ($serverType) {
            'mysql', 'mysqli' => 'mysql',
            'postgresql', 'pgsql' => 'postgresql',
            default => throw new \RuntimeException('Unsupported Autosave integration database family.'),
        };
        $sql = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/com_admin/sql/updates/' . $folder . '/6.2.0-2026-07-31.sql'
        );

        $this->assertIsString($sql);

        foreach ([$sql, $sql] as $pass) {
            foreach (DatabaseDriver::splitSql($pass) as $query) {
                $this->getDBDriver()->setQuery($query)->execute();
            }
        }

        $query = $this->getDBDriver()->createQuery()
            ->select(['COUNT(*) AS total', 'MAX(' . $this->getDBDriver()->quoteName('enabled') . ') AS enabled'])
            ->select('MAX(' . $this->getDBDriver()->quoteName('protected') . ') AS protected')
            ->select('MAX(' . $this->getDBDriver()->quoteName('locked') . ') AS locked')
            ->from($this->getDBDriver()->quoteName('#__extensions'))
            ->where($this->getDBDriver()->quoteName('type') . ' = ' . $this->getDBDriver()->quote('component'))
            ->where($this->getDBDriver()->quoteName('element') . ' = ' . $this->getDBDriver()->quote('com_autosave'))
            ->where($this->getDBDriver()->quoteName('client_id') . ' = 1');
        $row = $this->getDBDriver()->setQuery($query)->loadAssoc();

        $this->assertSame(1, (int) $row['total']);
        $this->assertSame(1, (int) $row['enabled']);
        $this->assertSame(1, (int) $row['protected']);
        $this->assertSame(1, (int) $row['locked']);
    }
}
