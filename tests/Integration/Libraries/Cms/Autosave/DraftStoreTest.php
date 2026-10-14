<?php

/**
 * @package     Joomla.IntegrationTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\DraftStoreCases;

/**
 * Identical contract cases on the native MySQL/PostgreSQL CI matrix.
 */
class DraftStoreTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;
    use DraftStoreCases;

    private DatabaseInterface $db;

    public function getSchemasToLoad(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->getDBDriver();
        $this->installSchema($this->db->getServerType() === 'mysql' ? 'mysql' : 'postgresql');
    }
    public static function competingOperations(): array
    {
        return [['preserve'], ['discard'], ['purge'], ['resume'], ['reconcile']];
    }

    /**
     * Independent connections contend while the first store holds its native owner lock.
     * The short database lock timeout supplies a decisive failure barrier, not a sleep.
     *
     * @dataProvider competingOperations
     */
    public function testOwnerLockOrdersCompetingStoreOperations(string $operation): void
    {
        $peer = (new \Joomla\Database\DatabaseFactory())->getDriver(
            getenv('JTEST_DB_ENGINE') ?: JTEST_DB_ENGINE,
            [
                'database' => getenv('JTEST_DB_NAME') ?: JTEST_DB_NAME,
                'host'     => getenv('JTEST_DB_HOST') ?: JTEST_DB_HOST,
                'user'     => getenv('JTEST_DB_USER') ?: JTEST_DB_USER,
                'password' => getenv('JTEST_DB_PASSWORD') ?: JTEST_DB_PASSWORD,
                'prefix'   => $this->db->getPrefix(),
            ]
        );
        $peer->setQuery($peer->getServerType() === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1000ms'")->execute();
        $competing  = new \Joomla\CMS\Autosave\DraftStore($peer);
        $descriptor = $this->descriptor();
        $draft      = $this->store->open(7, $descriptor, $this->now);
        $admission  = ['draft_id' => $draft['draft_id'], 'owner_id' => 7, 'descriptor' => $descriptor, 'rendered_at' => $draft['created_at']];
        $later      = new \Joomla\CMS\Date\Date('2026-10-11 01:01:00', 'UTC');
        $act        = static function () use ($competing, $draft, $descriptor, $later, $admission, $operation): void {
            switch ($operation) {
                case 'preserve':
                    $competing->preserve(7, $draft['draft_id'], 1, ['title' => 'older'], $later, static fn ($row, $payload) => $payload);
                    break;
                case 'discard':
                    $competing->discard(7, $draft['draft_id'], $later);
                    break;
                case 'purge':
                    $competing->purge($later);
                    break;
                case 'resume':
                    $competing->resume(7, $draft['draft_id'], $admission, $later, static function (): void {
                    });
                    break;
                case 'reconcile':
                    $competing->reconcile(7, $draft['draft_id'], 1, $descriptor['context'], '42', $descriptor['scope'], 'apply', '42', null, $later);
                    break;
            }
        };
        $this->store->preserve(7, $draft['draft_id'], 2, ['title' => 'newer'], new \Joomla\CMS\Date\Date('2026-10-11 00:59:00', 'UTC'), function ($row, $payload) use ($act) {
            try {
                $act();
                $this->fail('A competing operation must not pass the held owner lock.');
            } catch (\Exception $exception) {
                $this->assertMatchesRegularExpression('/lock.*timeout|timeout.*lock/i', $exception->getMessage());
            }

            return $payload;
        });
        $this->assertSame(['title' => 'newer'], $this->store->read(7, $draft['draft_id'], $later)['payload']);
        if ($operation === 'preserve') {
            $this->failure('revision_conflict', $act);
        } elseif ($operation === 'resume') {
            $this->failure('draft_expired', $act);
        } else {
            $act();
            $row = $this->store->read(7, $draft['draft_id'], $later);
            if ($operation === 'discard') {
                $this->assertNull($row['payload']);
                $this->failure('draft_retired', fn () => $this->preserve($draft, 3, 'delayed', $later));
            } else {
                $this->assertSame(['title' => 'newer'], $row['payload']);
                if ($operation === 'reconcile') {
                    $this->assertSame(1, $row['retired_revision']);
                }
            }
        }
    }
}
