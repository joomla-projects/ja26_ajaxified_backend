<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_redirect
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Redirect\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Redirect\Administrator\Autosave\LinkAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Redirect Autosave provider.
 *
 * @since  __DEPLOY_VERSION__
 */
class LinkAutosaveProviderTest extends UnitTestCase
{
    /**
     * @testdox  The provider owns exactly the com_redirect.link context
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReturnsExactContext(): void
    {
        $provider = new LinkAutosaveProvider($this->databaseReturning());

        $this->assertSame('com_redirect.link', $provider->getContext());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    /**
     * @testdox  Only canonical unsigned existing-record identities are accepted
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalTargetContract(): void
    {
        $provider = new LinkAutosaveProvider($this->databaseReturning());

        foreach (['1', '42', '4294967295'] as $valid) {
            $this->assertSame($valid, $provider->canonicalizeTargetId($valid));
        }

        foreach (['', '0', '-1', '+1', '01', '1.0', '1e2', '42link', '4294967296', '10000000000'] as $invalid) {
            $this->assertSame(
                'invalid_target',
                $this->captureFailure(
                    static fn () => $provider->canonicalizeTargetId($invalid)
                )->getErrorCode(),
                $invalid
            );
        }
    }

    /**
     * @testdox  Target existence reflects the Redirect row
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testTargetExistenceReflectsRedirectRow(): void
    {
        $this->assertTrue(
            (new LinkAutosaveProvider($this->databaseReturning($this->link())))->targetExists('42')
        );
        $this->assertFalse(
            (new LinkAutosaveProvider($this->databaseReturning()))->targetExists('42')
        );
    }

    /**
     * @testdox  Component core.edit is rechecked for every protected operation
     *
     * @param   AutosaveOperation  $operation  The protected operation.
     *
     * @dataProvider operationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCoreEditAuthorizesEveryOperation(AutosaveOperation $operation): void
    {
        $provider = new LinkAutosaveProvider($this->databaseReturning($this->link()));
        $calls    = [];

        $provider->authorize($this->user(true, $calls), '42', $operation);

        $this->assertSame([['core.edit', 'com_redirect']], $calls);
    }

    /**
     * @testdox  Missing targets and denied component ACL are distinct failures
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testAuthorizationFailures(): void
    {
        $calls = [];
        $this->assertSame(
            'target_not_found',
            $this->captureFailure(
                fn () => (new LinkAutosaveProvider($this->databaseReturning()))->authorize(
                    $this->user(true, $calls),
                    '42',
                    AutosaveOperation::Preserve
                )
            )->getErrorCode()
        );

        $this->assertSame(
            'forbidden',
            $this->captureFailure(
                fn () => (new LinkAutosaveProvider($this->databaseReturning($this->link())))->authorize(
                    $this->user(false, $calls),
                    '42',
                    AutosaveOperation::Preserve
                )
            )->getErrorCode()
        );
    }

    /**
     * @testdox  Base revisions cover canonical editorial state but ignore hits
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testBaseRevisionProperties(): void
    {
        $base = $this->revision($this->link());

        $this->assertSame($base, $this->revision($this->link()));
        $this->assertMatchesRegularExpression(
            '/^autosave:com_redirect\.link:base-revision:v1:[a-f0-9]{64}$/D',
            $base
        );
        $this->assertSame($base, $this->revision($this->link(['hits' => 999])));

        foreach (
            [
                ['id' => 43],
                ['old_url' => '/changed'],
                ['new_url' => '/changed'],
                ['comment' => 'changed'],
                ['published' => 0],
                ['header' => 410],
                ['modified_date' => '2026-08-12 12:00:00'],
            ] as $change
        ) {
            $this->assertNotSame($base, $this->revision($this->link($change)));
        }
    }

    /**
     * @testdox  Base revision encoding preserves null and empty distinctions
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testBaseRevisionDistinguishesNullAndEmpty(): void
    {
        $this->assertNotSame(
            $this->revision($this->link(['new_url' => null])),
            $this->revision($this->link(['new_url' => '']))
        );
    }

    /**
     * @testdox  The exact three-field payload is preserved without canonical transforms
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNormalizesExactPayloadWithoutTransformation(): void
    {
        $provider = new LinkAutosaveProvider($this->databaseReturning());
        $payload  = [
            'comment' => " comment\t",
            'new_url' => '',
            'old_url' => "  /caf\xC3\xA9%20draft\n",
        ];

        $this->assertSame(
            [
                'old_url' => "  /caf\xC3\xA9%20draft\n",
                'new_url' => '',
                'comment' => " comment\t",
            ],
            $provider->normalizePayload($payload, 1)
        );
    }

    /**
     * @testdox  Missing, extra, non-string, malformed and over-limit payloads are rejected
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsInvalidPayloads(): void
    {
        $provider = new LinkAutosaveProvider($this->databaseReturning());
        $valid    = ['old_url' => '', 'new_url' => '', 'comment' => ''];
        $invalid  = [
            null,
            [],
            array_diff_key($valid, ['comment' => true]),
            $valid + ['published' => 1],
            ['old_url' => [], 'new_url' => '', 'comment' => ''],
            ['old_url' => "bad\xFF", 'new_url' => '', 'comment' => ''],
            ['old_url' => str_repeat('x', 2049), 'new_url' => '', 'comment' => ''],
            ['old_url' => '', 'new_url' => str_repeat('x', 2049), 'comment' => ''],
            ['old_url' => '', 'new_url' => '', 'comment' => str_repeat('x', 256)],
        ];

        foreach ($invalid as $payload) {
            $this->assertSame(
                'invalid_payload',
                $this->captureFailure(
                    static fn () => $provider->normalizePayload($payload, 1)
                )->getErrorCode()
            );
        }

        $this->assertSame(
            'unsupported_schema_version',
            $this->captureFailure(
                static fn () => $provider->normalizePayload($valid, 2)
            )->getErrorCode()
        );
    }

    /**
     * Frozen protected operation cases.
     *
     * @return  array<string, array{AutosaveOperation}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function operationProvider(): array
    {
        $cases = [];

        foreach (AutosaveOperation::cases() as $operation) {
            $cases[$operation->value] = [$operation];
        }

        return $cases;
    }

    /**
     * Build a query-capable database mock.
     *
     * @param   ?object  $link  The row returned by loadObject.
     *
     * @return  DatabaseInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function databaseReturning(?object $link = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(
            static fn ($name, $as = null) => $as === null ? $name : $name . ' AS ' . $as
        );
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($link);

        return $db;
    }

    /**
     * Return a Redirect row fixture.
     *
     * @param   array  $replacements  Fixture replacements.
     *
     * @return  object
     *
     * @since   __DEPLOY_VERSION__
     */
    private function link(array $replacements = []): object
    {
        return (object) array_replace(
            [
                'id'            => 42,
                'old_url'       => '/old',
                'new_url'       => '/new',
                'comment'       => 'comment',
                'published'     => 1,
                'header'        => 301,
                'modified_date' => '2026-08-12 10:00:00',
                'hits'          => 2,
            ],
            $replacements
        );
    }

    /**
     * Return a revision for one row fixture.
     *
     * @param   object  $link  The row fixture.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function revision(object $link): string
    {
        return (new LinkAutosaveProvider($this->databaseReturning($link)))->getBaseRevision((string) $link->id);
    }

    /**
     * Build an identity mock and record authorization calls.
     *
     * @param   boolean  $allowed  Whether core.edit is allowed.
     * @param   array    $calls    Recorded calls.
     *
     * @return  User
     *
     * @since   __DEPLOY_VERSION__
     */
    private function user(bool $allowed, array &$calls): User
    {
        $calls = [];
        $user  = $this->createMock(User::class);
        $user->method('authorise')->willReturnCallback(
            static function (string $action, ?string $asset = null) use ($allowed, &$calls): bool {
                $calls[] = [$action, $asset];

                return $allowed;
            }
        );

        return $user;
    }

    /**
     * Capture an expected provider failure.
     *
     * @param   callable  $callback  The failing operation.
     *
     * @return  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    private function captureFailure(callable $callback): AutosaveException
    {
        try {
            $callback();
        } catch (AutosaveException $exception) {
            return $exception;
        }

        $this->fail('The expected AutosaveException was not thrown.');
    }
}
