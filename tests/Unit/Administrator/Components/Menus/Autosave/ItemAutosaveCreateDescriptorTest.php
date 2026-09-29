<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Menus
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Menus\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveProvider;
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveSchemaFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR38 com_menus.item dynamic-host create descriptor.
 *
 * @since  __DEPLOY_VERSION__
 */
class ItemAutosaveCreateDescriptorTest extends UnitTestCase
{
    public function testProviderExposesTheDynamicCreateDescriptorCapability(): void
    {
        $provider = new ItemAutosaveProvider($this->database(), $this->resolver([]));

        $this->assertInstanceOf(AutosaveDynamicCreateDescriptorProviderInterface::class, $provider);
        $this->assertSame('com_menus.item', $provider->getContext());
        $this->assertSame('menu-item-create-v1', $provider->getCreateContractVersion());
        $this->assertSame('menu-item-descriptor-v1', $provider->getStaticScopeContractVersion());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    public function testCanonicalizeAcceptsRawCandidateAndCanonicalTokenIdempotently(): void
    {
        $article  = $this->articleSchema();
        $provider = $this->provider(['article' => $article]);

        $raw   = '0|mainmenu|component.com_content.article.default';
        $token = $provider->canonicalizeStaticCreateScope($raw);

        $this->assertSame('mi1:0:mainmenu:component.com_content.article.default:' . $article->fingerprint(), $token);
        $this->assertSame($token, $provider->canonicalizeStaticCreateScope($token));
    }

    public function testCanonicalizeRejectsInvalidDescriptors(): void
    {
        $provider = $this->provider(['article' => $this->articleSchema()]);

        foreach (['nonsense', '0|mainmenu', 'x|mainmenu|component.com_content.article.default', '0|bad menu|component.com_content.article.default', 'mi1:0:mainmenu:component.com_content.article.default:short', 'mi1:0:mainmenu:bogus:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'] as $candidate) {
            $this->assertFailure(fn () => $provider->canonicalizeStaticCreateScope($candidate), 'invalid_scope');
        }
    }

    public function testCanonicalizeRejectsUnavailableRoutesAndUnknownIdentities(): void
    {
        $provider = $this->provider(
            ['article' => $this->articleSchema()],
            static fn (array $shape, int $client): bool => $shape['option'] === 'com_content' && $shape['view'] === 'article'
        );

        // A routed type of a disabled/missing component fails closed.
        $this->assertFailure(fn () => $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_disabled.article.default'), 'invalid_scope');

        // A route without a resolvable form definition never becomes a descriptor.
        $this->assertFailure(fn () => $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.missing.default'), 'invalid_scope');

        // Unknown type identities are refused.
        $this->assertFailure(fn () => $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_content..default'), 'invalid_scope');
    }

    public function testCreateScopeCandidateGatesTheNewItemEditor(): void
    {
        $provider = new ItemAutosaveProvider($this->database(), $this->resolver([]));

        // Before a type is genuinely chosen the model carries an empty type.
        $this->assertNull($provider->createScopeCandidate(0, 'mainmenu', '', ''));

        // A component type without a routed link is still the type-chooser state.
        $this->assertNull($provider->createScopeCandidate(0, 'mainmenu', 'component', ''));

        // A genuine routed type yields a bounded candidate.
        $this->assertSame('0|mainmenu|component.com_content.article.default', $provider->createScopeCandidate(0, 'mainmenu', 'component', 'index.php?option=com_content&view=article'));

        // Special types are recognized without a routed link.
        $this->assertSame('0|mainmenu|url', $provider->createScopeCandidate(0, 'mainmenu', 'url', ''));

        // Unknown types and invalid menus are never candidates.
        $this->assertNull($provider->createScopeCandidate(0, 'mainmenu', 'nonsense', ''));
        $this->assertNull($provider->createScopeCandidate(0, 'bad menu', 'url', ''));
    }

    public function testAuthorizeCreateRequiresAnAnchoredDescriptor(): void
    {
        $provider = new ItemAutosaveProvider($this->database(), $this->resolver([]));
        $user     = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);

        $this->assertSame(
            'scope_required',
            $this->failure(fn () => $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null))->getErrorCode()
        );
    }

    public function testAuthorizeStaticCreateScopeMirrorsNativeMenuCreateAcl(): void
    {
        $provider = $this->provider(['article' => $this->articleSchema()], null, ['menuTypeId' => 7]);
        $token    = $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.article.default');

        $allowed = $this->createMock(User::class);
        $allowed->method('authorise')->willReturnCallback(static fn (string $action, string $asset) => $asset === 'com_menus.menu.7');
        $provider->authorizeStaticCreateScope($allowed, $token, AutosaveOperation::Preserve, null);

        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);
        $this->assertFailure(fn () => $provider->authorizeStaticCreateScope($denied, $token, AutosaveOperation::Preserve, null), 'forbidden');
    }

    public function testAuthorizeStaticCreateScopeFailsClosedOnMenuOrRouteRevocation(): void
    {
        $user     = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);

        // The anchored menu disappears: the create authority is unavailable.
        $missingMenu = $this->provider(['article' => $this->articleSchema()], null, ['menuTypeId' => null]);
        $token       = $missingMenu->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.article.default');
        $this->assertFailure(fn () => $missingMenu->authorizeStaticCreateScope($user, $token, AutosaveOperation::Preserve, null), 'forbidden');

        // The routed component is no longer supported: the descriptor no longer
        // parses to a supported identity and fails closed before authorization.
        $token = $this->provider(['article' => $this->articleSchema()])
            ->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.article.default');
        $revoked = $this->provider(
            ['article' => $this->articleSchema()],
            static fn (array $shape, int $client): bool => $shape['option'] !== 'com_content',
            ['menuTypeId' => 7]
        );
        $this->assertFailure(fn () => $revoked->authorizeStaticCreateScope($user, $token, AutosaveOperation::Preserve, null), 'invalid_scope');
    }

    public function testVerifyFinalTargetMatchesTheAnchoredDescriptor(): void
    {
        $record = (object) [
            'id'         => 42, 'menu_type_id' => 7, 'menutype' => 'mainmenu', 'title' => 'Item', 'alias' => 'item', 'note' => '',
            'link'       => 'index.php?option=com_content&view=article&id=3', 'type' => 'component', 'component_id' => 22,
            'browserNav' => 0, 'params' => '{}', 'checked_out' => 0, 'published' => 1, 'parent_id' => 1,
            'access'     => 1, 'language' => '*', 'home' => 0, 'client_id' => 0,
        ];
        $provider = $this->provider(['article' => $this->articleSchema()], null, ['row' => $record]);
        $token    = $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.article.default');

        $provider->verifyFinalTargetStaticScope('42', $token);

        // A record that left the anchored menu or route fails closed.
        $otherMenu           = clone $record;
        $otherMenu->menutype = 'othermenu';
        $wrongMenu           = $this->provider(['article' => $this->articleSchema()], null, ['row' => $otherMenu]);
        $this->assertFailure(fn () => $wrongMenu->verifyFinalTargetStaticScope('42', $token), 'scope_mismatch');

        $otherRoute       = clone $record;
        $otherRoute->link = 'index.php?option=com_content&view=category&layout=blog';
        $wrongRoute       = $this->provider(['article' => $this->articleSchema()], null, ['row' => $otherRoute]);
        $this->assertFailure(fn () => $wrongRoute->verifyFinalTargetStaticScope('42', $token), 'scope_mismatch');

        $otherClient            = clone $record;
        $otherClient->client_id = 1;
        $wrongClient            = $this->provider(['article' => $this->articleSchema()], null, ['row' => $otherClient]);
        $this->assertFailure(fn () => $wrongClient->verifyFinalTargetStaticScope('42', $token), 'scope_mismatch');
    }

    public function testCreatePayloadCannotSmuggleOtherRoutesIntoTheDraft(): void
    {
        $article  = $this->articleSchema();
        $blog     = $this->blogSchema();
        $provider = $this->provider(['article' => $article, 'blog' => $blog]);
        $token    = $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.article.default');

        $articlePayload = $this->payloadFor($article);
        $this->assertSame($articlePayload, $provider->normalizeCreatePayload($token, $articlePayload, 1));

        // A Category Blog payload is unknown under the Single Article descriptor.
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($token, $this->payloadFor($blog), 1), 'invalid_payload');

        // A stale payload fingerprint fails closed.
        $stale = array_replace($articlePayload, ['schemaFingerprint' => str_repeat('0', 64)]);
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($token, $stale, 1), 'invalid_payload');
    }

    public function testDescriptorSchemaDriftFailsClosed(): void
    {
        $article  = $this->articleSchema();
        $evolved  = new AutosaveDynamicSchema([['path' => ['params', 'show_intro'], 'id' => 'jform_params_show_intro', 'kind' => 'enum', 'values' => ['0', '1']]]);
        $provider = $this->provider(['article' => $article]);
        $token    = $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.article.default');

        $drifted = $this->provider(['article' => $evolved]);
        $this->assertFailure(fn () => $drifted->normalizeCreatePayload($token, $this->payloadFor($evolved), 1), 'descriptor_stale');
    }

    public function testRealCoreMenuTypesProduceIsolatedSchemasAndDescriptors(): void
    {
        $articleSchema = $this->realArticleSchema();
        $urlSchema     = $this->realUrlSchema();

        // The two materially different core types really differ: the routed Single
        // Article form carries its routed component params while the External URL
        // special type stays on the static base schema. Category Blog and other
        // large routed forms exceed the bounded Autosave field limit by design and
        // therefore never gain a dynamic schema.
        $this->assertNotSame($articleSchema->fingerprint(), $urlSchema->fingerprint());
        $this->assertNotSame([], $articleSchema->fields());
        $this->assertNotSame([], $urlSchema->fields());

        $resolver = function (object $record) use ($articleSchema, $urlSchema): AutosaveDynamicSchema {
            if ($record->type === 'url') {
                return $urlSchema;
            }

            return $articleSchema;
        };

        $provider = $this->provider(null, null, [], $resolver);
        $article  = $provider->canonicalizeStaticCreateScope('0|mainmenu|component.com_content.article.default');
        $url      = $provider->canonicalizeStaticCreateScope('0|mainmenu|url');

        $this->assertStringContainsString('component.com_content.article.default', $article);
        $this->assertStringContainsString(':url:', $url);

        // A Single Article draft cannot cross into the External URL lineage and vice versa.
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($article, $this->payloadFor($urlSchema), 1), 'invalid_payload');
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($url, $this->payloadFor($articleSchema), 1), 'invalid_payload');
    }

    public function testGenuineNewSingleArticleEditorEnablesCreateModeWithNullTarget(): void
    {
        $article  = $this->articleSchema();
        $provider = $this->provider(['article' => $article], null, ['menuTypeId' => 7]);

        // The HtmlView create-mode candidate derives the exact values a genuine
        // Single Article editor binds: a routed component type carrying the article
        // view link inside the anchored menu (see the view-level fallback contract).
        $candidate = $provider->createScopeCandidate(
            0,
            'mainmenu',
            'component',
            'index.php?option=com_content&view=article'
        );

        $this->assertSame('0|mainmenu|component.com_content.article.default', $candidate);

        $createScope = $provider->canonicalizeStaticCreateScope($candidate);
        $this->assertStringStartsWith('mi1:0:mainmenu:component.com_content.article.default:', $createScope);

        // The create editor receives the routed scope schema and create payload
        // contract. The configurator emits mode=create with targetId=null whenever
        // the view passes a scope and no canonical target (covered by the JS suite);
        // the provider side only ever anchors the scope, never a record id.
        $schema = $provider->getDynamicSchemaForScope($createScope);
        $this->assertSame($article->fingerprint(), $schema->fingerprint());
        $this->assertSame($article->fields(), $schema->fields());

        $allowed = $this->createMock(User::class);
        $allowed->method('authorise')->willReturnCallback(static fn (string $action, string $asset) => $asset === 'com_menus.menu.7');
        $provider->authorizeStaticCreateScope($allowed, $createScope, AutosaveOperation::InitializeCreate, null);

        $payload = $this->payloadFor($article);
        $this->assertSame($payload, $provider->normalizeCreatePayload($createScope, $payload, 1));
    }

    public function testSingleArticleEditorBoundOnlyInFormDataStillResolvesCreateMode(): void
    {
        // Regression guard: the com_menus native type selection stores the routed
        // identity in the session form data merged into the editor form. When the
        // parallel model-state keys were not re-seeded (add()/edit() reset them),
        // the previous state-only view integration silently disabled Autosave even
        // though the editor was a genuine type-bound Single Article form. The
        // provider-level candidate must be derivable from those same bound values.
        $article  = $this->articleSchema();
        $provider = $this->provider(['article' => $article], null, ['menuTypeId' => 7]);

        $candidate = $provider->createScopeCandidate(
            0,
            'mainmenu',
            'component',
            'index.php?option=com_content&view=article'
        );

        $this->assertNotNull($candidate);

        $createScope = $provider->canonicalizeStaticCreateScope($candidate);
        $schema      = $provider->getDynamicSchemaForScope($createScope);

        $this->assertSame($article->fingerprint(), $schema->fingerprint());
    }

    public function testViewSourceFallsBackFromStateToItemAndFormForTheCreateCandidate(): void
    {
        // The com_menus view derives the create candidate from the model state first
        // and then falls back to the loaded item and the bound form values - the same
        // server-owned carriers the working com_modules and com_fields integrations
        // read. A regression to a state-only read would silently disable Autosave on
        // re-rendered create editors whose state keys were not re-seeded.
        $view = (string) file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/src/View/Item/HtmlView.php');
        $view = substr($view, (int) strpos($view, 'private function prepareAutosave'));

        $this->assertStringContainsString('createScopeCandidate(', $view);
        $this->assertStringContainsString('candidateClientId()', $view);
        $this->assertStringContainsString('candidateString(\'item.menutype\', \'menutype\')', $view);
        $this->assertStringContainsString('candidateString(\'item.type\', \'type\', false)', $view);
        $this->assertStringContainsString('candidateString(\'item.link\', \'link\')', $view);
        $this->assertStringNotContainsString('$this->state->get(\'item.link\', \'\')', $view);
    }

    private function realArticleSchema(): AutosaveDynamicSchema
    {
        return (new ItemAutosaveSchemaFactory())->fromForm($this->realForm('article'));
    }

    private function realUrlSchema(): AutosaveDynamicSchema
    {
        return (new ItemAutosaveSchemaFactory())->fromForm($this->realForm('url'));
    }

    private function realForm(string $type): Form
    {
        $form = new Form('item', ['control' => 'jform']);
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_menus/forms/item.xml');
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_menus/forms/item_component.xml', true);

        if ($type === 'article') {
            $form->loadFile(JPATH_SITE . '/components/com_content/tmpl/article/default.xml', true, '/metadata');
        } else {
            $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_menus/forms/item_url.xml', true);
        }

        return $form;
    }

    private function payloadFor(AutosaveDynamicSchema $schema): array
    {
        $params = [];

        foreach ($schema->fields() as $field) {
            $params[$field['path'][1]] = $this->sample($field);
        }

        return ['title' => 'Draft', 'alias' => 'draft', 'note' => '', 'browserNav' => '0', 'schemaFingerprint' => $schema->fingerprint(), 'params' => $params];
    }

    private function sample(array $field): mixed
    {
        if ($field['kind'] === 'boolean') {
            return false;
        }

        if ($field['kind'] === 'enum') {
            return $field['values'][0];
        }

        if ($field['kind'] === 'strings') {
            return [$field['values'][0]];
        }

        return 'value';
    }

    private function articleSchema(): AutosaveDynamicSchema
    {
        return new AutosaveDynamicSchema([
            ['path' => ['params', 'show_title'], 'id' => 'jform_params_show_title', 'kind' => 'enum', 'values' => ['0', '1']],
            ['path' => ['params', 'show_intro'], 'id' => 'jform_params_show_intro', 'kind' => 'enum', 'values' => ['0', '1']],
        ]);
    }

    private function blogSchema(): AutosaveDynamicSchema
    {
        return new AutosaveDynamicSchema([
            ['path' => ['params', 'show_child_category_articles'], 'id' => 'jform_params_show_child_category_articles', 'kind' => 'enum', 'values' => ['0', '1']],
            ['path' => ['params', 'num_leading_articles'], 'id' => 'jform_params_num_leading_articles', 'kind' => 'string', 'maxLength' => 4],
        ]);
    }

    /**
     * Build a provider with deterministic test resolvers. The support resolver is
     * injected because the production default mirrors native availability through
     * the CMS component cache, which is unavailable in the unit environment.
     *
     * @param   callable(object): AutosaveDynamicSchema|null  $resolver
     */
    private function provider(?array $schemas = [], ?callable $support = null, array $options = [], ?callable $resolver = null): ItemAutosaveProvider
    {
        return new ItemAutosaveProvider(
            $this->database($options),
            $resolver ?? $this->resolver($schemas ?? []),
            $support ?? static fn (array $shape, int $client): bool => true
        );
    }

    /** @return callable(object): AutosaveDynamicSchema */
    private function resolver(array $schemas): callable
    {
        return function (object $record) use ($schemas): AutosaveDynamicSchema {
            if (isset($record->type) && \in_array($record->type, ['alias', 'url', 'separator', 'heading', 'container'], true)) {
                return new AutosaveDynamicSchema([]);
            }

            $key = str_contains($record->link, 'view=category') ? 'blog' : 'article';

            return $schemas[$key] ?? new AutosaveDynamicSchema([]);
        };
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();

        if (\array_key_exists('row', $options)) {
            $db->method('loadObject')->willReturn($options['row']);
        } else {
            $db->method('loadObject')->willReturn(null);
        }

        $db->method('loadResult')->willReturn(\array_key_exists('menuTypeId', $options) ? $options['menuTypeId'] : null);

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

    private function assertFailure(callable $callback, string $code): void
    {
        $this->assertSame($code, $this->failure($callback)->getErrorCode());
    }
}
