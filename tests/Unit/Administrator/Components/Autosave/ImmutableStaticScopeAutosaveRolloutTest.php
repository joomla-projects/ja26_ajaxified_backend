<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveStaticScopeProviderInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Categories\Administrator\Autosave\CategoryAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR34 immutable static creation-scope rollout.
 *
 * @since  __DEPLOY_VERSION__
 */
class ImmutableStaticScopeAutosaveRolloutTest extends UnitTestCase
{
    public function testCategoryProviderExposesTheStaticScopeCapability(): void
    {
        $provider = new CategoryAutosaveProvider($this->database());

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertInstanceOf(AutosaveStaticScopeProviderInterface::class, $provider);
        $this->assertSame('category-scope-v1', $provider->getStaticScopeContractVersion());
        $this->assertSame('category-create-v1', $provider->getCreateContractVersion());
        $this->assertSame('com_categories.category', $provider->getContext());
    }

    public function testCategoryCanonicalizesOnlyPlainSupportedExtensions(): void
    {
        $provider = new CategoryAutosaveProvider($this->database());

        $this->assertSame('com_content', $provider->canonicalizeStaticCreateScope('com_content'));

        foreach (['', 'com_categories', 'content', 'com_', 'com_Content', 'com_content.article', 'com_content;drop', "com_content\nx", 42, null, ['com_content']] as $invalid) {
            $this->assertSame(
                'invalid_scope',
                $this->failure(fn () => $provider->canonicalizeStaticCreateScope($invalid))->getErrorCode()
            );
        }
    }

    public function testCategoryStaticScopeAuthorizationMirrorsNativeAllowAdd(): void
    {
        $provider = new CategoryAutosaveProvider($this->database());

        // Global extension create right.
        $global = $this->createMock(User::class);
        $global->method('authorise')->willReturn(true);
        $provider->authorizeStaticCreateScope($global, 'com_content', AutosaveOperation::Preserve, null);

        // Category-scoped create right only.
        $scoped = $this->createMock(User::class);
        $scoped->method('authorise')->willReturn(false);
        $scoped->method('getAuthorisedCategories')->willReturn([3]);
        $provider->authorizeStaticCreateScope($scoped, 'com_content', AutosaveOperation::Preserve, null);

        // Denied everywhere fails closed on every provisional operation.
        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);
        $denied->method('getAuthorisedCategories')->willReturn([]);

        foreach (AutosaveOperation::cases() as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeStaticCreateScope($denied, 'com_content', $operation, null))->getErrorCode()
            );
        }
    }

    public function testCategoryCannotAuthorizeWithoutAnAnchoredScope(): void
    {
        $provider = new CategoryAutosaveProvider($this->database());
        $user     = $this->createMock(User::class);

        $this->assertSame(
            'scope_required',
            $this->failure(fn () => $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null))->getErrorCode()
        );
    }

    public function testCategoryFinalTargetIsVerifiedAgainstAnchoredScope(): void
    {
        $provider = new CategoryAutosaveProvider($this->database(['extension' => 'com_content']));
        $provider->verifyFinalTargetStaticScope('73', 'com_content');

        $wrong = new CategoryAutosaveProvider($this->database(['extension' => 'com_banners']));
        $this->assertSame(
            'scope_mismatch',
            $this->failure(fn () => $wrong->verifyFinalTargetStaticScope('73', 'com_content'))->getErrorCode()
        );

        $missing = new CategoryAutosaveProvider($this->database(['row' => null]));
        $this->assertSame(
            'scope_mismatch',
            $this->failure(fn () => $missing->verifyFinalTargetStaticScope('73', 'com_content'))->getErrorCode()
        );
    }

    public function testCategoryPayloadKeepsExtensionOutAndAuthorsBoundedParent(): void
    {
        $provider = new CategoryAutosaveProvider($this->database());
        $valid    = ['title' => 'News', 'note' => '', 'description' => '', 'version_note' => '', 'metadesc' => '', 'metakey' => '', 'parent_id' => 1];

        $this->assertSame(2, $provider->getPayloadSchemaVersion());
        $this->assertSame($valid, $provider->normalizePayload($valid, 2));

        // extension is immutable scope and can never ride the mutable draft.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizePayload([...$valid, 'extension' => 'com_banners'], 2))->getErrorCode()
        );

        // parent_id bounds: 0, negatives, strings, overflow and missing keys are invalid.
        foreach (
            [
            [...$valid, 'parent_id' => 0],
            [...$valid, 'parent_id' => -1],
            [...$valid, 'parent_id' => '1'],
            [...$valid, 'parent_id' => 2147483648],
            array_diff_key($valid, ['parent_id' => true]),
            ] as $invalid
        ) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 2))->getErrorCode()
            );
        }
    }

    public function testCategoryParentMustBelongToTheAnchoredExtension(): void
    {
        $allowed     = $this->user(true);
        $payload     = ['title' => 'News', 'note' => '', 'description' => '', 'version_note' => '', 'metadesc' => '', 'metakey' => '', 'parent_id' => 73];
        $payloadRoot = [...$payload, 'parent_id' => 1];

        // Root (the global ROOT row) is legal for every extension and needs no lookup.
        $this->providerForExtension('com_content')->authorizeStaticCreateScope($allowed, 'com_content', AutosaveOperation::Preserve, $payloadRoot);

        // A same-extension parent passes.
        $this->providerForExtension('com_content')->authorizeStaticCreateScope($allowed, 'com_content', AutosaveOperation::Preserve, $payload);

        // A parent from another extension never passes.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $this->providerForExtension('com_banners')->authorizeStaticCreateScope($allowed, 'com_content', AutosaveOperation::Preserve, $payload))->getErrorCode()
        );

        // A deleted/unresolvable parent fails closed.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => (new CategoryAutosaveProvider($this->database(['row' => null])))->authorizeStaticCreateScope($allowed, 'com_content', AutosaveOperation::Preserve, $payload))->getErrorCode()
        );

        // A payload without parent_id is malformed for this schema.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $this->providerForExtension('com_content')->authorizeStaticCreateScope($allowed, 'com_content', AutosaveOperation::Preserve, ['title' => 'News']))->getErrorCode()
        );
    }

    private function providerForExtension(string $extension): CategoryAutosaveProvider
    {
        return new CategoryAutosaveProvider($this->database(['extension' => $extension]));
    }

    public function testLanguageOverrideNowExposesCompositeCreateAndScope(): void
    {
        // PR36 resolves the composite/file-backed Override create path: the provider
        // ships a create contract plus the immutable client/language scope, the view
        // authorizes genuine new records, and the controller opts into the shared
        // non-numeric canonical identity gate.
        $providerSource = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_languages/src/Autosave/OverrideAutosaveProvider.php');
        $viewSource     = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_languages/src/View/Override/HtmlView.php');
        $controllerFile = JPATH_ADMINISTRATOR . '/components/com_languages/src/Controller/OverrideController.php';

        $this->assertStringContainsString('AutosaveCreateProviderInterface', $providerSource);
        $this->assertStringContainsString('AutosaveStaticScopeProviderInterface', $providerSource);
        $this->assertStringContainsString('override-create-v1', $providerSource);
        $this->assertStringContainsString('override-scope-v1', $providerSource);
        $this->assertStringContainsString('authorizeCreate', $viewSource);
        $this->assertStringContainsString('canonicalizeStaticCreateScope', $viewSource);
        $this->assertStringContainsString('AutosaveCompositeCanonicalIdentityInterface', file_get_contents($controllerFile));
    }

    public function testCategoryExistingRecordBehaviorIsUnchanged(): void
    {
        $row      = (object) ['id' => 73, 'extension' => 'com_content', 'title' => 'News', 'checked_out' => 0, 'created_user_id' => 7];
        $provider = new CategoryAutosaveProvider($this->database(['row' => $row]));

        $this->assertTrue($provider->targetExists('73'));
        $this->assertSame('73', $provider->canonicalizeTargetId('73'));

        $editor = $this->createMock(User::class);
        $editor->method('authorise')->willReturn(true);
        $provider->authorize($editor, '73', AutosaveOperation::Read);
        $this->assertStringStartsWith('autosave:com_categories.category:base-revision:v1:', $provider->getBaseRevision('73'));
    }

    private function user(bool $allowed): User
    {
        $user = $this->createMock(User::class);
        $user->method('authorise')->willReturn($allowed);
        $user->method('getAuthorisedCategories')->willReturn([]);

        return $user;
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(
            \array_key_exists('row', $options) ? $options['row'] : (object) ['id' => 73, 'extension' => $options['extension'] ?? 'com_content']
        );
        $db->method('loadResult')->willReturn(1);

        return $db;
    }

    private function failure(callable $callback): AutosaveException
    {
        try {
            $callback();
        } catch (AutosaveException $exception) {
            return $exception;
        }

        $this->fail('Expected AutosaveException was not thrown.');
    }
}
