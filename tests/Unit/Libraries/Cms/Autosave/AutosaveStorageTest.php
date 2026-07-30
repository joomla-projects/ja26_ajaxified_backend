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
            'payload storage overflow' => [
                $this->policy(['max_payload_bytes' => 16777216]),
            ],
            'quota storage overflow' => [
                $this->policy(['max_active_generations' => 2147483648]),
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
            'Unicode whitespace key'           => [['initializationKey' => "\u{00A0}\u{2003}"]],
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
                'state'           => 'active',
                'expires_at'      => '2026-07-29 10:01:00',
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
     * @testdox  accepts each opaque identity at its exact character boundary
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOpaqueIdentityLengthBoundariesAreAccepted(): void
    {
        $target   = str_repeat('t', 191);
        $revision = str_repeat('r', 255);
        $key      = str_repeat('k', 191);
        $db       = $this->createQueryDatabaseMock();
        $db->method('loadAssoc')->willReturn(
            [
                'continuation_id' => str_repeat('a', 64),
                'generation_id'   => str_repeat('b', 64),
                'context'         => 'com_example.record',
                'target_id'       => $target,
                'base_revision'   => $revision,
                'state'           => 'active',
                'expires_at'      => '2026-07-29 10:01:00',
            ]
        );

        $result = (new AutosaveStorage($db, $this->policy()))->initialize(
            7,
            'com_example.record',
            $target,
            $revision,
            $key,
            new Date('2026-07-29 10:00:00', 'UTC')
        );

        $this->assertSame(str_repeat('a', 64), $result['continuation_id']);
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
        $failure      = new ExecutionFailureException('INSERT', 'duplicate entry', 1062);
        $executeCount = 0;
        $db           = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');

        $db->expects($this->exactly(4))->method('transactionStart');
        $db->expects($this->exactly(3))->method('transactionRollback');
        $db->expects($this->once())->method('transactionCommit');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('getAffectedRows')->willReturn(0);
        $db->method('loadResult')->willReturn(1);
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
            $this->assertSame(7, $executeCount);
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
        $failure      = new ExecutionFailureException('INSERT GENERATION', 'duplicate entry', 1062);
        $executeCount = 0;
        $events       = [];
        $db           = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');

        $db->expects($this->exactly(4))->method('transactionStart')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'start';
            }
        );
        $db->expects($this->exactly(3))->method('transactionRollback')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'rollback';
            }
        );
        $db->expects($this->once())->method('transactionCommit')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'commit';
            }
        );
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('getAffectedRows')->willReturn(0);
        $db->method('loadResult')->willReturn(1);
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
            $this->assertSame(10, $executeCount);
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
                    'start',
                    'expire',
                    'commit',
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
        $failure      = new ExecutionFailureException('INSERT', 'duplicate entry', 1062);
        $executeCount = 0;
        $db           = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');

        $db->expects($this->exactly(2))->method('transactionStart');
        $db->expects($this->once())->method('transactionRollback');
        $db->expects($this->once())->method('transactionCommit');
        $existing = [
            'continuation_id' => str_repeat('a', 64),
            'generation_id'   => str_repeat('b', 64),
            'context'         => 'com_example.record',
            'target_id'       => 'record-42',
            'base_revision'   => 'revision-1',
            'state'           => 'active',
            'expires_at'      => '2026-07-29 10:01:00',
        ];
        $db->method('loadAssoc')->willReturnOnConsecutiveCalls(
            null,
            $existing,
            $existing
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
     * @testdox  does not retry a non-unique insert failure
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNonUniqueInsertFailureIsNotRetried(): void
    {
        $failure      = new ExecutionFailureException('INSERT', 'deadlock', 1213);
        $executeCount = 0;
        $db           = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');
        $db->expects($this->once())->method('transactionStart');
        $db->expects($this->once())->method('transactionRollback');
        $db->expects($this->never())->method('transactionCommit');
        $db->expects($this->never())->method('loadResult');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, $failure): bool {
                $executeCount++;

                if ($executeCount === 2) {
                    throw $failure;
                }

                return true;
            }
        );

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The non-unique insert failure was not rethrown.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame(2, $executeCount);
        }
    }

    /**
     * @testdox  does not classify an identity-read failure as an insert collision
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testFailureBetweenInsertPhasesIsNotRetried(): void
    {
        $failure = new ExecutionFailureException('LAST INSERT ID', 'connection lost');
        $db      = $this->createQueryDatabaseMock();
        $db->expects($this->once())->method('transactionStart');
        $db->expects($this->once())->method('transactionRollback');
        $db->expects($this->never())->method('transactionCommit');
        $db->expects($this->never())->method('loadResult');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('insertid')->willThrowException($failure);

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The identity-read failure was not rethrown.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * @testdox  propagates a candidate unique failure without committed collision evidence
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testUniqueFailureWithoutEvidenceIsNotRetried(): void
    {
        $failure      = new ExecutionFailureException('INSERT', 'duplicate entry', 1062);
        $executeCount = 0;
        $db           = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');
        $db->expects($this->once())->method('transactionStart');
        $db->expects($this->once())->method('transactionRollback');
        $db->expects($this->never())->method('transactionCommit');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('loadResult')->willReturn(false);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, $failure): bool {
                $executeCount++;

                if ($executeCount === 2) {
                    throw $failure;
                }

                return true;
            }
        );

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The unexplained unique failure was not rethrown.');
        } catch (ExecutionFailureException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame(2, $executeCount);
        }
    }

    /**
     * @testdox  propagates rollback failure before attempting collision classification
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRollbackFailureStopsCollisionClassification(): void
    {
        $insertFailure   = new ExecutionFailureException('INSERT', 'duplicate entry', 1062);
        $rollbackFailure = new \RuntimeException('rollback failed');
        $executeCount    = 0;
        $db              = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');
        $db->expects($this->once())->method('transactionStart');
        $db->expects($this->once())->method('transactionRollback')->willThrowException($rollbackFailure);
        $db->expects($this->never())->method('loadResult');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturn([]);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, $insertFailure): bool {
                $executeCount++;

                if ($executeCount === 2) {
                    throw $insertFailure;
                }

                return true;
            }
        );

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The rollback failure was not propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($rollbackFailure, $exception);
        }
    }

    /**
     * @testdox  classifies a same-key race only after rollback and returns a stable conflict
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testConflictingSameKeyRaceIsResolvedAfterRollback(): void
    {
        $failure      = new ExecutionFailureException('INSERT', 'duplicate entry', 1062);
        $executeCount = 0;
        $readCount    = 0;
        $events       = [];
        $existing     = [
            'continuation_id' => str_repeat('a', 64),
            'generation_id'   => str_repeat('b', 64),
            'context'         => 'com_example.record',
            'target_id'       => 'other-target',
            'base_revision'   => 'revision-1',
            'state'           => 'active',
            'expires_at'      => '2026-07-29 10:01:00',
        ];
        $db = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');
        $db->expects($this->exactly(2))->method('transactionStart')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'start';
            }
        );
        $db->expects($this->once())->method('transactionRollback')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'rollback';
            }
        );
        $db->expects($this->once())->method('transactionCommit');
        $db->method('loadAssoc')->willReturnCallback(
            static function () use (&$readCount, &$events, $existing): ?array {
                $readCount++;
                $events[] = 'read-' . $readCount;

                return $readCount === 1 ? null : $existing;
            }
        );
        $db->method('loadColumn')->willReturn([]);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, $failure): bool {
                $executeCount++;

                if ($executeCount === 2) {
                    throw $failure;
                }

                return true;
            }
        );

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The competing initialization conflict was not reported.');
        } catch (\Joomla\CMS\Autosave\AutosaveException $exception) {
            $this->assertSame('initialization_conflict', $exception->getErrorCode());
            $this->assertLessThan(
                array_search('read-2', $events, true),
                array_search('rollback', $events, true)
            );
            $this->assertSame(['start', 'read-1', 'rollback', 'read-2', 'start', 'read-3'], $events);
        }
    }

    /**
     * @testdox  reports exhausted quota only after the final collision is rechecked transactionally
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCollisionExhaustionRechecksQuota(): void
    {
        $failure      = new ExecutionFailureException('INSERT GENERATION', 'duplicate entry', 1062);
        $executeCount = 0;
        $db           = $this->createQueryDatabaseMock();
        $db->method('getName')->willReturn('mysqli');
        $db->expects($this->exactly(4))->method('transactionStart');
        $db->expects($this->exactly(3))->method('transactionRollback');
        $db->expects($this->once())->method('transactionCommit');
        $db->method('loadAssoc')->willReturn(null);
        $db->method('loadColumn')->willReturnOnConsecutiveCalls([], [], [], [1, 2]);
        $db->method('loadResult')->willReturn(1);
        $db->method('insertid')->willReturn(42);
        $db->method('execute')->willReturnCallback(
            static function () use (&$executeCount, $failure): bool {
                $executeCount++;

                if ($executeCount % 3 === 0) {
                    throw $failure;
                }

                return true;
            }
        );

        try {
            (new AutosaveStorage($db, $this->policy()))->initialize(
                ...array_values($this->initializationArguments())
            );
            $this->fail('The exhausted quota was not reported.');
        } catch (\Joomla\CMS\Autosave\AutosaveException $exception) {
            $this->assertSame('draft_limit_reached', $exception->getErrorCode());
        }
    }

    /**
     * @testdox  generates independent opaque continuation and generation identities
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPublicIdentitiesAreIndependentOpaqueTokens(): void
    {
        $storage                         = new AutosaveStorage($this->createMock(DatabaseInterface::class), $this->policy());
        $method                          = new \ReflectionMethod($storage, 'generatePublicIdentities');
        [$continuationId, $generationId] = $method->invoke($storage);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $continuationId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $generationId);
        $this->assertNotSame($continuationId, $generationId);
    }

    /**
     * @testdox  accepts the authoritative hyphenated component context grammar
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testHyphenatedComponentContextUsesSharedGrammar(): void
    {
        $db = $this->createQueryDatabaseMock();
        $db->expects($this->once())->method('transactionStart');
        $db->expects($this->once())->method('transactionCommit');
        $db->method('loadAssoc')->willReturn(
            [
                'continuation_id' => str_repeat('a', 64),
                'generation_id'   => str_repeat('b', 64),
                'context'         => 'com_example-addon.record',
                'target_id'       => 'record-42',
                'base_revision'   => 'revision-1',
                'state'           => 'active',
                'expires_at'      => '2026-07-29 10:01:00',
            ]
        );

        $result = (new AutosaveStorage($db, $this->policy()))->initialize(
            7,
            'com_example-addon.record',
            'record-42',
            'revision-1',
            'initialization-1',
            new Date('2026-07-29 10:00:00', 'UTC')
        );

        $this->assertSame(str_repeat('a', 64), $result['continuation_id']);
    }

    /**
     * @testdox  recognizes only supported-driver duplicate-key signals
     *
     * @param   string  $driver    The Joomla driver name.
     * @param   integer $code      The exception code.
     * @param   string  $message   The exception message.
     * @param   boolean $expected  Whether this is a unique violation.
     *
     * @return  void
     *
     * @dataProvider uniqueViolationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testUniqueViolationClassification(
        string $driver,
        int $code,
        string $message,
        bool $expected
    ): void {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getName')->willReturn($driver);
        $storage = new AutosaveStorage($db, $this->policy());
        $method  = new \ReflectionMethod($storage, 'isUniqueViolation');

        $this->assertSame(
            $expected,
            $method->invoke($storage, new ExecutionFailureException('INSERT', $message, $code))
        );
    }

    /**
     * Unique-violation classifier cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function uniqueViolationProvider(): array
    {
        return [
            'mysqli duplicate'           => ['mysqli', 1062, 'Duplicate entry', true],
            'mysqli deadlock'            => ['mysqli', 1213, 'Deadlock', false],
            'pdo mysql duplicate'        => ['mysql', 23000, '23000, 1062, Duplicate entry', true],
            'pdo mysql other integrity'  => ['mysql', 23000, '23000, 1048, Column cannot be null', false],
            'pdo mysql malformed prefix' => ['mysql', 23000, 'Duplicate entry 1062', false],
            'postgres duplicate'         => ['pgsql', 23505, 'unique violation', true],
            'postgres foreign key'       => ['pgsql', 23503, 'foreign key violation', false],
            'postgres serialization'     => ['pgsql', 40001, 'serialization failure', false],
            'unsupported driver'         => ['sqlite', 19, 'constraint failed', false],
        ];
    }

    /**
     * @testdox  encodes normalized payloads deterministically while preserving list and scalar semantics
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPayloadEncodingIsDeterministicAndSchemaBound(): void
    {
        $storage = new AutosaveStorage($this->createMock(DatabaseInterface::class), $this->policy());
        $method  = new \ReflectionMethod($storage, 'encodePayload');
        $first   = $method->invoke(
            $storage,
            [
                'z'       => ['b' => 2, 'a' => 1],
                'list'    => [3, 1, 2],
                'html'    => '<p>draft & entity</p>',
                'unicode' => 'é',
                'float'   => 1.0,
                'bool'    => true,
                'null'    => null,
            ],
            1
        );
        $second = $method->invoke(
            $storage,
            [
                'null'    => null,
                'bool'    => true,
                'float'   => 1.0,
                'unicode' => 'é',
                'html'    => '<p>draft & entity</p>',
                'list'    => [3, 1, 2],
                'z'       => ['a' => 1, 'b' => 2],
            ],
            1
        );
        $otherSchema = $method->invoke($storage, ['value' => 1], 2);
        $firstSchema = $method->invoke($storage, ['value' => 1], 1);

        $this->assertSame($first['encoded'], $second['encoded']);
        $this->assertSame($first['digest'], $second['digest']);
        $this->assertStringContainsString('"list":[3,1,2]', $first['encoded']);
        $this->assertStringContainsString('"float":1.0', $first['encoded']);
        $this->assertStringContainsString('<p>draft & entity</p>', $first['encoded']);
        $this->assertStringContainsString('é', $first['encoded']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first['digest']);
        $this->assertNotSame($firstSchema['digest'], $otherSchema['digest']);
    }

    /**
     * @testdox  rejects unsupported normalized payload values
     *
     * @param   mixed  $value  The unsupported nested value.
     *
     * @return  void
     *
     * @dataProvider invalidPayloadValueProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPayloadEncodingRejectsUnsupportedValues(mixed $value): void
    {
        $storage = new AutosaveStorage($this->createMock(DatabaseInterface::class), $this->policy());
        $method  = new \ReflectionMethod($storage, 'encodePayload');

        $this->expectException(\InvalidArgumentException::class);

        $method->invoke($storage, ['value' => $value], 1);
    }

    /**
     * Unsupported normalized payload cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidPayloadValueProvider(): array
    {
        return [
            'object'        => [new \stdClass()],
            'invalid UTF-8' => ["\xFF"],
            'positive INF'  => [INF],
            'negative INF'  => [-INF],
            'NAN'           => [NAN],
        ];
    }

    /**
     * @testdox  rejects resources in a normalized payload
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPayloadEncodingRejectsResources(): void
    {
        $resource = fopen('php://memory', 'rb');

        try {
            $storage = new AutosaveStorage($this->createMock(DatabaseInterface::class), $this->policy());
            $method  = new \ReflectionMethod($storage, 'encodePayload');

            $this->expectException(\InvalidArgumentException::class);
            $method->invoke($storage, ['value' => $resource], 1);
        } finally {
            fclose($resource);
        }
    }

    /**
     * @testdox  enforces the encoded payload byte policy
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPayloadEncodingEnforcesByteLimit(): void
    {
        $storage = new AutosaveStorage(
            $this->createMock(DatabaseInterface::class),
            $this->policy(['max_payload_bytes' => 10])
        );
        $method = new \ReflectionMethod($storage, 'encodePayload');

        try {
            $method->invoke($storage, ['value' => 'long payload'], 1);
            $this->fail('The encoded payload limit was not enforced.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $cause = $exception instanceof \ReflectionException ? $exception->getPrevious() : $exception;
            $this->assertInstanceOf(\Joomla\CMS\Autosave\AutosaveException::class, $cause);
            $this->assertSame('payload_too_large', $cause->getErrorCode());
        }
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
                'max_payload_bytes'      => 1024,
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
