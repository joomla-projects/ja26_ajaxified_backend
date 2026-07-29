<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveStorage;
use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for \Joomla\CMS\Autosave\AutosaveStorage.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @testdox     The Autosave storage
 *
 * @since       __DEPLOY_VERSION__
 */
class AutosaveStorageTest extends UnitTestCase
{
    /**
     * @testdox  rejects incomplete or invalid policy values
     *
     * @param   array  $policy  The invalid policy.
     *
     * @return  void
     *
     * @dataProvider invalidPolicyProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInvalidPolicyIsRejected(array $policy): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AutosaveStorage($this->createMock(DatabaseInterface::class), $policy);
    }

    /**
     * Invalid policy cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidPolicyProvider(): array
    {
        return [
            'missing option' => [
                [
                    'idle_ttl'               => 60,
                    'max_lifetime'           => 120,
                    'max_active_generations' => 2,
                ],
            ],
            'unknown option' => [
                $this->policy(['unexpected' => 1]),
            ],
            'non-integer option' => [
                $this->policy(['idle_ttl' => '60']),
            ],
            'zero option' => [
                $this->policy(['tombstone_retention' => 0]),
            ],
            'negative option' => [
                $this->policy(['max_active_generations' => -1]),
            ],
            'idle exceeds hard lifetime' => [
                $this->policy(['idle_ttl' => 121]),
            ],
        ];
    }

    /**
     * @testdox  rejects invalid initialization values before opening a transaction
     *
     * @param   array  $values  Values replacing the valid initialization defaults.
     *
     * @return  void
     *
     * @dataProvider invalidInitializationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInvalidInitializationIsRejectedBeforeTransaction(array $values): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects($this->never())->method('transactionStart');

        $arguments = array_replace($this->initializationArguments(), $values);
        $storage   = new AutosaveStorage($db, $this->policy());

        $this->expectException(\InvalidArgumentException::class);

        $storage->initialize(...array_values($arguments));
    }

    /**
     * Invalid initialization cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidInitializationProvider(): array
    {
        return [
            'anonymous user'                   => [['userId' => 0]],
            'negative user'                    => [['userId' => -1]],
            'empty context'                    => [['context' => '']],
            'component-only context'           => [['context' => 'com_example']],
            'uppercase context'                => [['context' => 'com_example.Record']],
            'unbounded context'                => [['context' => 'com_a.' . str_repeat('b', 250)]],
            'empty target'                     => [['targetId' => '']],
            'whitespace target'                => [['targetId' => '   ']],
            'target control character'         => [['targetId' => "record\x00id"]],
            'target unicode control character' => [['targetId' => "record\u{0085}id"]],
            'invalid UTF-8 target'             => [['targetId' => "record\xFFid"]],
            'unbounded target'                 => [['targetId' => str_repeat('x', 192)]],
            'empty revision'                   => [['baseRevision' => '']],
            'revision control character'       => [['baseRevision' => "revision\x1F"]],
            'unbounded revision'               => [['baseRevision' => str_repeat('x', 256)]],
            'empty key'                        => [['initializationKey' => '']],
            'key control character'            => [['initializationKey' => "key\x7F"]],
            'unbounded key'                    => [['initializationKey' => str_repeat('x', 192)]],
            'non-UTC time'                     => [['now' => new Date('2026-07-29 10:00:00', 'Asia/Kolkata')]],
        ];
    }

    /**
     * @testdox  compares opaque values without normalizing them
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOpaqueValuesAreNotNormalized(): void
    {
        $db = $this->createQueryDatabaseMock();
        $db->expects($this->once())->method('transactionStart');
        $db->expects($this->once())->method('transactionCommit');
        $db->expects($this->never())->method('transactionRollback');
        $db->method('loadAssoc')->willReturn(
            [
                'continuation_id' => str_repeat('a', 64),
                'generation_id'   => str_repeat('b', 64),
                'context'         => 'com_example.record',
                'target_id'       => ' target ',
                'base_revision'   => ' revision ',
            ]
        );

        $result = (new AutosaveStorage($db, $this->policy()))->initialize(
            7,
            'com_example.record',
            ' target ',
            ' revision ',
            ' key ',
            new Date('2026-07-29 10:00:00', 'UTC')
        );

        $this->assertSame(str_repeat('a', 64), $result['continuation_id']);
        $this->assertSame(str_repeat('b', 64), $result['generation_id']);
    }

    /**
     * @testdox  rolls back and preserves a database failure before insertion
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadFailureIsRolledBackWithoutRetry(): void
    {
        $failure = new ExecutionFailureException('SELECT', 'read failed');
        $db      = $this->createQueryDatabaseMock();
        $db->expects($this->once())->method('transactionStart');
        $db->expects($this->once())->method('transactionRollback');
        $db->expects($this->never())->method('transactionCommit');
        $db->method('loadAssoc')->willThrowException($failure);

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The database failure was not rethrown.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * @testdox  bounds insertion retries, rolls each attempt back and preserves the first failure
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInsertFailureRetryIsBoundedAndRolledBack(): void
    {
        $failure      = new ExecutionFailureException('INSERT', 'insert failed');
        $executeCount = 0;
        $db           = $this->createQueryDatabaseMock();

        $db->expects($this->exactly(3))->method('transactionStart');
        $db->expects($this->exactly(3))->method('transactionRollback');
        $db->expects($this->never())->method('transactionCommit');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('getAffectedRows')->willReturn(0);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, $failure): bool {
                $executeCount++;

                if ($executeCount % 2 === 0) {
                    throw $failure;
                }

                return true;
            }
        );

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The database failure was not rethrown.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame(6, $executeCount);
        }
    }

    /**
     * @testdox  rolls back the continuation when generation insertion fails and bounds retries
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testGenerationInsertFailureRetryIsBoundedAndRolledBack(): void
    {
        $failure      = new ExecutionFailureException('INSERT GENERATION', 'generation insert failed');
        $executeCount = 0;
        $events       = [];
        $db           = $this->createQueryDatabaseMock();

        $db->expects($this->exactly(3))->method('transactionStart')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'start';
            }
        );
        $db->expects($this->exactly(3))->method('transactionRollback')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'rollback';
            }
        );
        $db->expects($this->never())->method('transactionCommit');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('getAffectedRows')->willReturn(0);
        $db->method('insertid')->willReturn(42);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, &$events, $failure): bool {
                $executeCount++;
                $phase    = $executeCount % 3;
                $events[] = $phase === 1 ? 'expire' : ($phase === 2 ? 'continuation' : 'generation');

                if ($phase === 0) {
                    throw $failure;
                }

                return true;
            }
        );

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The generation database failure was not rethrown.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame(9, $executeCount);
            $this->assertSame(
                [
                    'start',
                    'expire',
                    'continuation',
                    'generation',
                    'rollback',
                    'start',
                    'expire',
                    'continuation',
                    'generation',
                    'rollback',
                    'start',
                    'expire',
                    'continuation',
                    'generation',
                    'rollback',
                ],
                $events
            );
        }
    }

    /**
     * @testdox  rereads an identical initialization only after rolling back a failed insert
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInsertRaceRetryConvergesAfterRollback(): void
    {
        $failure      = new ExecutionFailureException('INSERT', 'competing insert');
        $executeCount = 0;
        $db           = $this->createQueryDatabaseMock();

        $db->expects($this->exactly(2))->method('transactionStart');
        $db->expects($this->once())->method('transactionRollback');
        $db->expects($this->once())->method('transactionCommit');
        $db->method('loadAssoc')->willReturnOnConsecutiveCalls(
            null,
            [
                'continuation_id' => str_repeat('a', 64),
                'generation_id'   => str_repeat('b', 64),
                'context'         => 'com_example.record',
                'target_id'       => 'record-42',
                'base_revision'   => 'revision-1',
            ]
        );
        $db->method('loadColumn')->willReturn([]);
        $db->method('getAffectedRows')->willReturn(0);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, $failure): bool {
                $executeCount++;

                if ($executeCount === 2) {
                    throw $failure;
                }

                return true;
            }
        );

        $result = (new AutosaveStorage($db, $this->policy()))->initialize(
            ...array_values($this->initializationArguments())
        );

        $this->assertSame(str_repeat('a', 64), $result['continuation_id']);
        $this->assertSame(str_repeat('b', 64), $result['generation_id']);
    }

    /**
     * Create a database mock that accepts Joomla query-builder calls.
     *
     * @return  DatabaseInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function createQueryDatabaseMock(): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);

        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(
            static fn ($name, $as = null) => $as === null ? $name : $name . ' AS ' . $as
        );
        $db->method('setQuery')->willReturnSelf();

        return $db;
    }

    /**
     * Return a complete valid policy with optional replacements.
     *
     * @param   array  $replacements  Replacement options.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function policy(array $replacements = []): array
    {
        return array_replace(
            [
                'idle_ttl'               => 60,
                'max_lifetime'           => 120,
                'tombstone_retention'    => 300,
                'max_active_generations' => 2,
            ],
            $replacements
        );
    }

    /**
     * Return valid initialization arguments with stable associative keys.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function initializationArguments(): array
    {
        return [
            'userId'            => 7,
            'context'           => 'com_example.record',
            'targetId'          => 'record-42',
            'baseRevision'      => 'revision-1',
            'initializationKey' => 'initialization-1',
            'now'               => new Date('2026-07-29 10:00:00', 'UTC'),
        ];
    }
}
