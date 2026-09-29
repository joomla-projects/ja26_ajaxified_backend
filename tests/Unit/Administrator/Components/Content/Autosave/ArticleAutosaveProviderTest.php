<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Content\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Autosave\ArticleAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the read-only Article Autosave provider.
 *
 * @since  __DEPLOY_VERSION__
 */
class ArticleAutosaveProviderTest extends UnitTestCase
{
    /**
     * @testdox  The provider owns exactly the com_content.article context
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReturnsExactContext(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning());

        $this->assertSame('com_content.article', $provider->getContext());
    }

    /**
     * @testdox  The provider declares one bounded versioned create contract
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDeclaresTheBoundedArticleCreateContract(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning());

        $this->assertSame('article-create-v1', $provider->getCreateContractVersion());
    }

    /**
     * @testdox  Create authorization uses the normalized category relation
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCreateAuthorizationUsesTheNormalizedCategory(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $calls    = [];
        $user     = $this->user(7, ['core.create' => true], $calls);

        $provider->authorizeCreate($user, AutosaveOperation::Preserve, $this->validPayload());

        $this->assertSame([['core.create', 'com_content.category.2']], $calls);
    }

    /**
     * @testdox  Create initialization requires component or category create access
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCreateInitializationFailsClosedWithoutCreateAccess(): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning());
        $user      = $this->createMock(User::class);
        $user->method('authorise')->willReturn(false);
        $user->method('getAuthorisedCategories')->willReturn([]);
        $exception = $this->captureFailure(
            fn () => $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null)
        );

        $this->assertSame('forbidden', $exception->getErrorCode());
    }

    /**
     * @testdox  Create authorization denies an unauthorized normalized category
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCreateAuthorizationRejectsAnUnauthorizedCategory(): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $calls     = [];
        $user      = $this->user(7, [], $calls);
        $exception = $this->captureFailure(
            fn () => $provider->authorizeCreate($user, AutosaveOperation::Preserve, $this->validPayload())
        );

        $this->assertSame('forbidden', $exception->getErrorCode());
        $this->assertSame([['core.create', 'com_content.category.2']], $calls);
    }

    /**
     * @testdox  Create authorization rejects malformed normalized category state
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCreateAuthorizationRejectsMalformedNormalizedCategory(): void
    {
        $provider         = new ArticleAutosaveProvider($this->databaseReturning());
        $calls            = [];
        $user             = $this->user(7, ['core.create' => true], $calls);
        $payload          = $this->validPayload();
        $payload['catid'] = '2';
        $exception        = $this->captureFailure(
            fn () => $provider->authorizeCreate($user, AutosaveOperation::Preserve, $payload)
        );

        $this->assertSame('invalid_payload', $exception->getErrorCode());
        $this->assertSame([], $calls);
    }

    /**
     * @testdox  Category authorization is reevaluated for every create generation
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCreateAuthorizationIsReevaluatedForEveryNormalizedGeneration(): void
    {
        $provider    = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $calls       = [];
        $permissions = ['core.create' => true];
        $user        = $this->createMock(User::class);
        $user->method('authorise')->willReturnCallback(
            static function (string $action, ?string $asset = null) use (&$permissions, &$calls): bool {
                $calls[] = [$action, $asset];

                return $permissions[$action] ?? false;
            }
        );

        $provider->authorizeCreate($user, AutosaveOperation::Preserve, $this->validPayload());
        $permissions      = [];
        $changed          = $this->validPayload();
        $changed['catid'] = 3;
        $exception        = $this->captureFailure(
            fn () => $provider->authorizeCreate($user, AutosaveOperation::PrepareCanonicalAction, $changed)
        );

        $this->assertSame('forbidden', $exception->getErrorCode());
        $this->assertSame(
            [
                ['core.create', 'com_content.category.2'],
                ['core.create', 'com_content.category.3'],
            ],
            $calls
        );
    }

    /**
     * @testdox  Canonical Article identities are returned unchanged
     *
     * @param   string  $targetId  The canonical Article identity.
     *
     * @return  void
     *
     * @dataProvider validTargetProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanonicalizesValidTarget(string $targetId): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning());

        $this->assertSame($targetId, $provider->canonicalizeTargetId($targetId));
    }

    /**
     * Valid canonical Article identities.
     *
     * @return  array<string, array{string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function validTargetProvider(): array
    {
        return [
            'minimum'  => ['1'],
            'ordinary' => ['42'],
            'maximum'  => ['4294967295'],
        ];
    }

    /**
     * @testdox  Noncanonical and out-of-range Article identities are rejected
     *
     * @param   string  $targetId  The invalid Article identity.
     *
     * @return  void
     *
     * @dataProvider invalidTargetProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsInvalidTarget(string $targetId): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning());
        $exception = $this->captureFailure(
            static fn () => $provider->canonicalizeTargetId($targetId)
        );

        $this->assertSame('invalid_target', $exception->getErrorCode());
        $this->assertSame('The Article target is invalid.', $exception->getMessage());
    }

    /**
     * Invalid Article identities.
     *
     * @return  array<string, array{string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidTargetProvider(): array
    {
        return [
            'empty'               => [''],
            'zero'                => ['0'],
            'negative'            => ['-1'],
            'explicit plus'       => ['+1'],
            'leading whitespace'  => [' 1'],
            'trailing whitespace' => ['1 '],
            'internal whitespace' => ['1 2'],
            'decimal'             => ['1.0'],
            'exponent'            => ['1e2'],
            'mixed'               => ['12article'],
            'leading zero'        => ['01'],
            'unicode digits'      => ['١٢'],
            'overflow'            => ['4294967296'],
            'eleven digits'       => ['10000000000'],
        ];
    }

    /**
     * @testdox  Every target-dependent method validates direct callers
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testTargetDependentMethodsRejectInvalidDirectUse(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning($this->article()));
        $calls    = [];
        $user     = $this->user(7, ['core.edit' => true], $calls);
        $methods  = [
            'targetExists'    => static fn () => $provider->targetExists('01'),
            'authorize'       => static fn () => $provider->authorize($user, '01', AutosaveOperation::Initialize),
            'getBaseRevision' => static fn () => $provider->getBaseRevision('01'),
        ];

        foreach ($methods as $method => $callback) {
            $exception = $this->captureFailure($callback);
            $this->assertSame('invalid_target', $exception->getErrorCode(), $method);
        }
    }

    /**
     * @testdox  Target existence reflects the Article row
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testTargetExistenceReflectsArticleRow(): void
    {
        $existing = new ArticleAutosaveProvider($this->databaseReturning($this->article()));
        $missing  = new ArticleAutosaveProvider($this->databaseReturning());

        $this->assertTrue($existing->targetExists('42'));
        $this->assertFalse($missing->targetExists('42'));
    }

    /**
     * @testdox  core.edit authorizes each frozen Autosave operation
     *
     * @param   AutosaveOperation  $operation  The operation.
     *
     * @return  void
     *
     * @dataProvider operationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCoreEditAuthorizesEveryOperation(AutosaveOperation $operation): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning($this->article()));
        $calls    = [];
        $user     = $this->user(8, ['core.edit' => true], $calls);

        $provider->authorize($user, '42', $operation);

        $this->assertSame([['core.edit', 'com_content.article.42']], $calls);
    }

    /**
     * @testdox  core.edit.own authorizes the creator for each frozen operation
     *
     * @param   AutosaveOperation  $operation  The operation.
     *
     * @return  void
     *
     * @dataProvider operationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testEditOwnAuthorizesCreatorForEveryOperation(AutosaveOperation $operation): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning($this->article(['created_by' => 7])));
        $calls    = [];
        $user     = $this->user(7, ['core.edit.own' => true], $calls);

        $provider->authorize($user, '42', $operation);

        $this->assertSame(
            [
                ['core.edit', 'com_content.article.42'],
                ['core.edit.own', 'com_content.article.42'],
            ],
            $calls
        );
    }

    /**
     * @testdox  core.edit.own rejects a different creator for each frozen operation
     *
     * @param   AutosaveOperation  $operation  The operation.
     *
     * @return  void
     *
     * @dataProvider operationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testEditOwnRejectsNonCreatorForEveryOperation(AutosaveOperation $operation): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning($this->article(['created_by' => 7])));
        $calls     = [];
        $user      = $this->user(8, ['core.edit.own' => true], $calls);
        $exception = $this->captureFailure(
            static fn () => $provider->authorize($user, '42', $operation)
        );

        $this->assertSame('forbidden', $exception->getErrorCode());
    }

    /**
     * @testdox  A user without edit permissions is rejected for each frozen operation
     *
     * @param   AutosaveOperation  $operation  The operation.
     *
     * @return  void
     *
     * @dataProvider operationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testMissingEditPermissionRejectsEveryOperation(AutosaveOperation $operation): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning($this->article()));
        $calls     = [];
        $user      = $this->user(7, [], $calls);
        $exception = $this->captureFailure(
            static fn () => $provider->authorize($user, '42', $operation)
        );

        $this->assertSame('forbidden', $exception->getErrorCode());
    }

    /**
     * @testdox  Unchecked and current-user checkouts are allowed for every operation
     *
     * @param   mixed              $checkedOut  The checkout owner.
     * @param   AutosaveOperation  $operation   The operation.
     *
     * @return  void
     *
     * @dataProvider allowedCheckoutOperationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testAllowedCheckoutStatesForEveryOperation(
        mixed $checkedOut,
        AutosaveOperation $operation
    ): void {
        $provider = new ArticleAutosaveProvider(
            $this->databaseReturning($this->article(['checked_out' => $checkedOut]))
        );
        $calls = [];
        $user  = $this->user(7, ['core.edit' => true], $calls);

        $provider->authorize($user, '42', $operation);

        $this->addToAssertionCount(1);
    }

    /**
     * @testdox  A foreign checkout is rejected for every operation
     *
     * @param   AutosaveOperation  $operation  The operation.
     *
     * @return  void
     *
     * @dataProvider operationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testForeignCheckoutRejectsEveryOperation(AutosaveOperation $operation): void
    {
        $provider  = new ArticleAutosaveProvider(
            $this->databaseReturning($this->article(['checked_out' => 8]))
        );
        $calls     = [];
        $user      = $this->user(7, ['core.edit' => true], $calls);
        $exception = $this->captureFailure(
            static fn () => $provider->authorize($user, '42', $operation)
        );

        $this->assertSame('checkout_conflict', $exception->getErrorCode());
    }

    /**
     * @testdox  A missing target cannot be authorized for any operation
     *
     * @param   AutosaveOperation  $operation  The operation.
     *
     * @return  void
     *
     * @dataProvider operationProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testMissingTargetRejectsEveryOperation(AutosaveOperation $operation): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning());
        $calls     = [];
        $user      = $this->user(7, ['core.edit' => true], $calls);
        $exception = $this->captureFailure(
            static fn () => $provider->authorize($user, '42', $operation)
        );

        $this->assertSame('target_not_found', $exception->getErrorCode());
    }

    /**
     * Frozen Autosave operations.
     *
     * Returning the enum cases makes a future addition enter every authorization
     * data set and fail the provider's exhaustive match until handled deliberately.
     *
     * @return  array<string, array{AutosaveOperation}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function operationProvider(): array
    {
        $operations = [];

        foreach (AutosaveOperation::cases() as $operation) {
            $operations[$operation->value] = [$operation];
        }

        return $operations;
    }

    /**
     * Allowed checkout states crossed with every frozen operation.
     *
     * @return  array<string, array{mixed, AutosaveOperation}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function allowedCheckoutOperationProvider(): array
    {
        $cases = [];

        foreach (
            [
                'null'         => null,
                'zero'         => 0,
                'current user' => 7,
            ] as $checkoutName => $checkedOut
        ) {
            foreach (AutosaveOperation::cases() as $operation) {
                $cases[$checkoutName . ' ' . $operation->value] = [$checkedOut, $operation];
            }
        }

        return $cases;
    }

    /**
     * @testdox  Base revisions are stable, opaque, versioned and title-sensitive
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testBaseRevisionProperties(): void
    {
        $unchanged = new ArticleAutosaveProvider(
            $this->databaseReturning($this->article(['title' => 'Private title', 'version' => 3]))
        );
        $newId = new ArticleAutosaveProvider(
            $this->databaseReturning($this->article(['id' => 43, 'title' => 'Private title', 'version' => 3]))
        );
        $newVersion = new ArticleAutosaveProvider(
            $this->databaseReturning($this->article(['title' => 'Private title', 'version' => 4]))
        );
        $newTitle = new ArticleAutosaveProvider(
            $this->databaseReturning($this->article(['title' => 'Changed title', 'version' => 3]))
        );

        $first  = $unchanged->getBaseRevision('42');
        $second = $unchanged->getBaseRevision('42');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression(
            '/^autosave:com_content\.article:base-revision:v1:[a-f0-9]{64}$/D',
            $first
        );
        $this->assertLessThanOrEqual(255, \strlen($first));
        $this->assertStringNotContainsString('Private title', $first);
        $this->assertNotSame($first, $newId->getBaseRevision('43'));
        $this->assertNotSame($first, $newVersion->getBaseRevision('42'));
        $this->assertNotSame($first, $newTitle->getBaseRevision('42'));
    }

    /**
     * @testdox  Base revisions do not depend on draft normalization
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testBaseRevisionIsIndependentOfDraftPayload(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning($this->article(), 1));
        $before   = $provider->getBaseRevision('42');

        $provider->normalizePayload(
            [
                'title'       => 'Draft title',
                'alias'       => 'draft-title',
                'articletext' => '<p>Draft</p>',
                'catid'       => 2,
            ],
            1
        );

        $this->assertSame($before, $provider->getBaseRevision('42'));
    }

    /**
     * @testdox  A missing target has no base revision
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testBaseRevisionRejectsMissingTarget(): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning());
        $exception = $this->captureFailure(
            static fn () => $provider->getBaseRevision('42')
        );

        $this->assertSame('target_not_found', $exception->getErrorCode());
    }

    /**
     * @testdox  The initial Article payload schema is version one
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPayloadSchemaVersionIsOne(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning());

        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    /**
     * @testdox  Unsupported payload schemas have a stable failure identifier
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsUnsupportedPayloadSchema(): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning());
        $exception = $this->captureFailure(
            static fn () => $provider->normalizePayload([], 2)
        );

        $this->assertSame('unsupported_schema_version', $exception->getErrorCode());
    }

    /**
     * @testdox  Valid Article drafts normalize to one deterministic four-field shape
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNormalizesPayloadInCanonicalOrder(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $payload  = [
            'catid'       => '2',
            'articletext' => '<p>Draft</p>',
            'alias'       => 'draft-alias',
            'title'       => 'Draft title',
        ];

        $normalized = $provider->normalizePayload($payload, 1);

        $this->assertSame(
            [
                'title'       => 'Draft title',
                'alias'       => 'draft-alias',
                'articletext' => '<p>Draft</p>',
                'catid'       => 2,
            ],
            $normalized
        );
        $this->assertSame(['title', 'alias', 'articletext', 'catid'], array_keys($normalized));
    }

    /**
     * @testdox  Empty and temporarily long draft strings remain preservable
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreservesIncompleteDraftStrings(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $title    = str_repeat('t', 300);
        $payload  = [
            'title'       => $title,
            'alias'       => '',
            'articletext' => '',
            'catid'       => 2,
        ];

        $this->assertSame($payload, $provider->normalizePayload($payload, 1));
    }

    /**
     * @testdox  Draft whitespace and HTML are preserved byte for byte
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPreservesWhitespaceAndArticleHtmlExactly(): void
    {
        $provider    = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $title       = "  Unfinished café title\n";
        $alias       = " draft alias\t";
        $articletext = "<p data-value=\"&amp;\">  Draft&nbsp;text </p>\n"
            . "<hr id=\"system-readmore\">\n<script>untrusted()</script>";
        $payload = [
            'title'       => $title,
            'alias'       => $alias,
            'articletext' => $articletext,
            'catid'       => 2,
        ];

        $this->assertSame($payload, $provider->normalizePayload($payload, 1));
    }

    /**
     * @testdox  Invalid payload containers and shapes are rejected
     *
     * @param   mixed  $payload  The invalid payload.
     *
     * @return  void
     *
     * @dataProvider invalidPayloadShapeProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsInvalidPayloadShape(mixed $payload): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $exception = $this->captureFailure(
            static fn () => $provider->normalizePayload($payload, 1)
        );

        $this->assertSame('invalid_payload', $exception->getErrorCode());
    }

    /**
     * Invalid payload containers and shapes.
     *
     * @return  array<string, array{mixed}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidPayloadShapeProvider(): array
    {
        $valid = [
            'title'       => 'Draft',
            'alias'       => 'draft',
            'articletext' => '<p>Draft</p>',
            'catid'       => 2,
        ];

        return [
            'null container'   => [null],
            'string container' => ['draft'],
            'object container' => [new \stdClass()],
            'list'             => [['Draft', 'draft', '<p>Draft</p>', 2]],
            'empty array'      => [[]],
            'missing title'    => [array_diff_key($valid, ['title' => true])],
            'extra field'      => [$valid + ['state' => 1]],
        ];
    }

    /**
     * @testdox  Resource payload containers are rejected
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsResourcePayload(): void
    {
        $provider = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $resource = fopen('php://memory', 'r');

        try {
            $exception = $this->captureFailure(
                static fn () => $provider->normalizePayload($resource, 1)
            );
            $this->assertSame('invalid_payload', $exception->getErrorCode());
        } finally {
            fclose($resource);
        }
    }

    /**
     * @testdox  String fields reject non-string, nested, null and malformed UTF-8 values
     *
     * @param   string  $field  The field to replace.
     * @param   mixed   $value  The invalid value.
     *
     * @return  void
     *
     * @dataProvider invalidStringFieldProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsInvalidStringField(string $field, mixed $value): void
    {
        $provider        = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $payload         = $this->validPayload();
        $payload[$field] = $value;
        $exception       = $this->captureFailure(
            static fn () => $provider->normalizePayload($payload, 1)
        );

        $this->assertSame('invalid_payload', $exception->getErrorCode());
    }

    /**
     * Invalid string field values.
     *
     * @return  array<string, array{string, mixed}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidStringFieldProvider(): array
    {
        return [
            'numeric title'       => ['title', 123],
            'boolean alias'       => ['alias', true],
            'nested article text' => ['articletext', ['nested']],
            'object title'        => ['title', new \stdClass()],
            'null alias'          => ['alias', null],
            'invalid UTF-8 title' => ['title', "bad\xFF"],
        ];
    }

    /**
     * @testdox  Integer and canonical string categories normalize to integers
     *
     * @param   int|string  $categoryId  The category identity.
     * @param   int         $expected    The normalized identity.
     *
     * @return  void
     *
     * @dataProvider validCategoryProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNormalizesValidCategory(int|string $categoryId, int $expected): void
    {
        $provider         = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $payload          = $this->validPayload();
        $payload['catid'] = $categoryId;

        $normalized = $provider->normalizePayload($payload, 1);

        $this->assertSame($expected, $normalized['catid']);
    }

    /**
     * Valid category identities.
     *
     * @return  array<string, array{int|string, int}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function validCategoryProvider(): array
    {
        return [
            'integer'          => [2, 2],
            'canonical string' => ['2', 2],
            'maximum integer'  => [4294967295, 4294967295],
            'maximum string'   => ['4294967295', 4294967295],
        ];
    }

    /**
     * @testdox  Invalid category identities are rejected
     *
     * @param   mixed  $categoryId  The invalid category identity.
     *
     * @return  void
     *
     * @dataProvider invalidCategoryProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsInvalidCategoryIdentity(mixed $categoryId): void
    {
        $provider         = new ArticleAutosaveProvider($this->databaseReturning(null, 1));
        $payload          = $this->validPayload();
        $payload['catid'] = $categoryId;
        $exception        = $this->captureFailure(
            static fn () => $provider->normalizePayload($payload, 1)
        );

        $this->assertSame('invalid_payload', $exception->getErrorCode());
    }

    /**
     * Invalid category identities.
     *
     * @return  array<string, array{mixed}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function invalidCategoryProvider(): array
    {
        return [
            'null'             => [null],
            'boolean'          => [true],
            'float'            => [2.0],
            'zero integer'     => [0],
            'zero string'      => ['0'],
            'negative integer' => [-1],
            'negative string'  => ['-1'],
            'explicit plus'    => ['+2'],
            'leading space'    => [' 2'],
            'trailing space'   => ['2 '],
            'leading zero'     => ['02'],
            'decimal'          => ['2.0'],
            'exponent'         => ['2e1'],
            'overflow integer' => [4294967296],
            'overflow string'  => ['4294967296'],
            'nested'           => [['2']],
            'object'           => [new \stdClass()],
        ];
    }

    /**
     * @testdox  A missing category is invalid draft data
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsMissingArticleCategory(): void
    {
        $provider  = new ArticleAutosaveProvider($this->databaseReturning(null, null));
        $exception = $this->captureFailure(
            fn () => $provider->normalizePayload($this->validPayload(), 1)
        );

        $this->assertSame('invalid_payload', $exception->getErrorCode());
    }

    /**
     * @testdox  Category lookup requires ownership by com_content
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRejectsCategoryOutsideContentExtension(): void
    {
        $query     = null;
        $provider  = new ArticleAutosaveProvider($this->databaseCapturingCategoryQuery($query));
        $exception = $this->captureFailure(
            fn () => $provider->normalizePayload($this->validPayload(), 1)
        );

        $this->assertSame('invalid_payload', $exception->getErrorCode());
        $this->assertInstanceOf(QueryInterface::class, $query);
        $this->assertSame('com_content', $query->getBounded(':extension')->value);
    }

    /**
     * @testdox  Article database failures remain infrastructure failures
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testArticleDatabaseFailureIsNotReportedAsMissingTarget(): void
    {
        $db = $this->databaseThrowingOnArticleLoad();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        (new ArticleAutosaveProvider($db))->targetExists('42');
    }

    /**
     * @testdox  Category database failures remain infrastructure failures
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCategoryDatabaseFailureIsNotReportedAsInvalidPayload(): void
    {
        $db = $this->databaseThrowingOnCategoryLoad();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        (new ArticleAutosaveProvider($db))->normalizePayload($this->validPayload(), 1);
    }

    /**
     * Build a query-capable database mock.
     *
     * @param   ?object  $article        The Article row returned by loadObject.
     * @param   mixed    $categoryResult The category result returned by loadResult.
     *
     * @return  DatabaseInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function databaseReturning(?object $article = null, mixed $categoryResult = 1): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(
            static fn ($name, $as = null) => $as === null ? $name : $name . ' AS ' . $as
        );
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($article);
        $db->method('loadResult')->willReturn($categoryResult);

        return $db;
    }

    /**
     * Build a database mock which fails while loading an Article.
     *
     * @return  DatabaseInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function databaseThrowingOnArticleLoad(): DatabaseInterface
    {
        $db = $this->databaseReturning();
        $db->method('loadObject')->willThrowException(new \RuntimeException('database unavailable'));

        return $db;
    }

    /**
     * Build a database mock which captures a category lookup.
     *
     * @param   ?QueryInterface  $query  The captured query.
     *
     * @return  DatabaseInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function databaseCapturingCategoryQuery(?QueryInterface &$query): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(
            static fn ($name, $as = null) => $as === null ? $name : $name . ' AS ' . $as
        );
        $db->method('setQuery')->willReturnCallback(
            static function (QueryInterface $candidate) use ($db, &$query): DatabaseInterface {
                $query = $candidate;

                return $db;
            }
        );
        $db->method('loadResult')->willReturn(null);

        return $db;
    }

    /**
     * Build a database mock which fails while loading a category.
     *
     * @return  DatabaseInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function databaseThrowingOnCategoryLoad(): DatabaseInterface
    {
        $db = $this->databaseReturning();
        $db->method('loadResult')->willThrowException(new \RuntimeException('database unavailable'));

        return $db;
    }

    /**
     * Return an Article row fixture.
     *
     * @param   array  $replacements  Fixture replacements.
     *
     * @return  object
     *
     * @since   __DEPLOY_VERSION__
     */
    private function article(array $replacements = []): object
    {
        return (object) array_replace(
            [
                'id'          => 42,
                'title'       => 'Stored title',
                'version'     => 3,
                'created_by'  => 7,
                'checked_out' => 0,
            ],
            $replacements
        );
    }

    /**
     * Return a valid draft payload.
     *
     * @return  array{title: string, alias: string, articletext: string, catid: int}
     *
     * @since   __DEPLOY_VERSION__
     */
    private function validPayload(): array
    {
        return [
            'title'       => 'Draft title',
            'alias'       => 'draft-title',
            'articletext' => '<p>Draft</p>',
            'catid'       => 2,
        ];
    }

    /**
     * Build an identity mock with recorded authorization calls.
     *
     * @param   integer  $id           The user ID.
     * @param   array    $permissions  Authorization answers by action.
     * @param   array    $calls        Recorded authorization calls.
     *
     * @return  User
     *
     * @since   __DEPLOY_VERSION__
     */
    private function user(int $id, array $permissions, array &$calls): User
    {
        $calls    = [];
        $user     = $this->createMock(User::class);
        $user->id = $id;
        $user->method('authorise')->willReturnCallback(
            static function (string $action, ?string $asset = null) use ($permissions, &$calls): bool {
                $calls[] = [$action, $asset];

                return $permissions[$action] ?? false;
            }
        );

        return $user;
    }

    /**
     * Capture an expected Autosave domain failure.
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
