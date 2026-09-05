<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Modules
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Modules\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveProvider;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveSchemaFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR38 com_modules.module dynamic-host create descriptor.
 *
 * @since  __DEPLOY_VERSION__
 */
class ModuleAutosaveCreateDescriptorTest extends UnitTestCase
{
    public function testProviderExposesTheDynamicCreateDescriptorCapability(): void
    {
        $provider = $this->provider();

        $this->assertInstanceOf(AutosaveDynamicCreateDescriptorProviderInterface::class, $provider);
        $this->assertSame('com_modules.module', $provider->getContext());
        $this->assertSame('module-create-v1', $provider->getCreateContractVersion());
        $this->assertSame('module-descriptor-v1', $provider->getStaticScopeContractVersion());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    public function testCanonicalizeAcceptsRawCandidateAndCanonicalTokenIdempotently(): void
    {
        $custom   = $this->customSchema();
        $provider = $this->provider(['mod_custom' => $custom]);

        $raw   = '0|mod_custom';
        $token = $provider->canonicalizeStaticCreateScope($raw);

        $this->assertSame('md1:0:mod_custom:' . $custom->fingerprint(), $token);
        $this->assertSame($token, $provider->canonicalizeStaticCreateScope($token));
    }

    public function testCanonicalizeRejectsInvalidDescriptors(): void
    {
        $provider = $this->provider(['mod_custom' => $this->customSchema()]);

        foreach (['nonsense', '0', '0|mod_custom|extra', 'x|mod_custom', '2|mod_custom', 'md1:0:mod_custom:short', 'md1:0:mod_missing:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'] as $candidate) {
            $this->assertFailure(fn () => $provider->canonicalizeStaticCreateScope($candidate), 'invalid_scope');
        }
    }

    public function testCanonicalizeRejectsUnknownOrUnavailableModuleTypes(): void
    {
        $provider = $this->provider(
            ['mod_custom' => $this->customSchema()],
            static fn (string $module, int $client): bool => $module === 'mod_custom' && $client === 0
        );

        // An extension that is not installed/enabled for the client fails closed.
        $this->assertFailure(fn () => $provider->canonicalizeStaticCreateScope('0|mod_missing'), 'invalid_scope');
        $this->assertFailure(fn () => $provider->canonicalizeStaticCreateScope('1|mod_custom'), 'invalid_scope');
    }

    public function testCreateScopeCandidateGatesTheNewModuleEditor(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->createScopeCandidate(0, ''));
        $this->assertNull($provider->createScopeCandidate(0, 'custom'));
        $this->assertNull($provider->createScopeCandidate(2, 'mod_custom'));
        $this->assertSame('0|mod_custom', $provider->createScopeCandidate(0, 'mod_custom'));
        $this->assertSame('1|mod_custom', $provider->createScopeCandidate(1, 'mod_custom'));
    }

    public function testAuthorizeCreateRequiresAnAnchoredDescriptor(): void
    {
        $provider = $this->provider();
        $user     = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);

        $this->assertSame(
            'scope_required',
            $this->failure(fn () => $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null))->getErrorCode()
        );
    }

    public function testAuthorizeStaticCreateScopeMirrorsNativeModuleCreateAcl(): void
    {
        $provider = $this->provider(['mod_custom' => $this->customSchema()]);
        $token    = $provider->canonicalizeStaticCreateScope('0|mod_custom');

        $allowed = $this->createMock(User::class);
        $allowed->method('authorise')->willReturnCallback(static fn (string $action, string $asset) => $asset === 'com_modules');
        $provider->authorizeStaticCreateScope($allowed, $token, AutosaveOperation::Preserve, null);

        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);
        $this->assertFailure(fn () => $provider->authorizeStaticCreateScope($denied, $token, AutosaveOperation::Preserve, null), 'forbidden');
    }

    public function testAuthorizeStaticCreateScopeFailsClosedOnExtensionRevocation(): void
    {
        $user     = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);
        $token = $this->provider(['mod_custom' => $this->customSchema()])
            ->canonicalizeStaticCreateScope('0|mod_custom');

        $revoked = $this->provider(
            ['mod_custom' => $this->customSchema()],
            static fn (string $module, int $client): bool => false
        );
        $this->assertFailure(fn () => $revoked->authorizeStaticCreateScope($user, $token, AutosaveOperation::Preserve, null), 'invalid_scope');
    }

    public function testVerifyFinalTargetMatchesTheAnchoredDescriptor(): void
    {
        $record = (object) [
            'id'        => 7, 'asset_id' => 0, 'title' => 'Module', 'note' => '', 'content' => '', 'position' => 'position-7',
            'ordering'  => 0, 'checked_out' => 0, 'published' => 1, 'module' => 'mod_custom', 'access' => 1,
            'showtitle' => 1, 'params' => '{}', 'client_id' => 0, 'language' => '*', 'publish_up' => null, 'publish_down' => null,
        ];
        $provider = $this->provider(['mod_custom' => $this->customSchema()], null, ['row' => $record]);
        $token    = $provider->canonicalizeStaticCreateScope('0|mod_custom');

        $provider->verifyFinalTargetStaticScope('7', $token);

        $otherType         = clone $record;
        $otherType->module = 'mod_menu';
        $wrongType         = $this->provider(['mod_custom' => $this->customSchema()], null, ['row' => $otherType]);
        $this->assertFailure(fn () => $wrongType->verifyFinalTargetStaticScope('7', $token), 'scope_mismatch');

        $otherClient            = clone $record;
        $otherClient->client_id = 1;
        $wrongClient            = $this->provider(['mod_custom' => $this->customSchema()], null, ['row' => $otherClient]);
        $this->assertFailure(fn () => $wrongClient->verifyFinalTargetStaticScope('7', $token), 'scope_mismatch');
    }

    public function testCreatePayloadCannotSmuggleOtherModuleParamsIntoTheDraft(): void
    {
        $custom   = $this->customSchema();
        $menu     = $this->menuSchema();
        $provider = $this->provider(['mod_custom' => $custom, 'mod_menu' => $menu]);
        $token    = $provider->canonicalizeStaticCreateScope('0|mod_custom');

        $customPayload = $this->payloadFor($custom);
        $this->assertSame($customPayload, $provider->normalizeCreatePayload($token, $customPayload, 1));

        // A mod_menu-only parameter payload is unknown under the mod_custom descriptor.
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($token, $this->payloadFor($menu), 1), 'invalid_payload');

        // A stale payload fingerprint fails closed.
        $stale = array_replace($customPayload, ['schemaFingerprint' => str_repeat('0', 64)]);
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($token, $stale, 1), 'invalid_payload');
    }

    public function testDescriptorSchemaDriftFailsClosed(): void
    {
        $custom  = $this->customSchema();
        $evolved = new AutosaveDynamicSchema([['path' => ['params', 'prepare_content'], 'id' => 'jform_params_prepare_content', 'kind' => 'enum', 'values' => ['0', '1']]]);
        $token   = $this->provider(['mod_custom' => $custom])->canonicalizeStaticCreateScope('0|mod_custom');

        $drifted = $this->provider(['mod_custom' => $evolved]);
        $this->assertFailure(fn () => $drifted->normalizeCreatePayload($token, $this->payloadFor($evolved), 1), 'descriptor_stale');
    }

    public function testRealCoreModuleTypesProduceIsolatedSchemasAndDescriptors(): void
    {
        $customSchema = $this->realCustomSchema();
        $menuSchema   = $this->realMenuSchema();

        // The two materially different core module types really differ.
        $this->assertNotSame($customSchema->fingerprint(), $menuSchema->fingerprint());
        $this->assertNotSame([], $customSchema->fields());
        $this->assertNotSame([], $menuSchema->fields());

        $resolver = function (object $record) use ($customSchema, $menuSchema): AutosaveDynamicSchema {
            return $record->module === 'mod_menu' ? $menuSchema : $customSchema;
        };

        $provider = $this->provider(null, null, [], $resolver);
        $custom   = $provider->canonicalizeStaticCreateScope('0|mod_custom');
        $menu     = $provider->canonicalizeStaticCreateScope('0|mod_menu');

        $this->assertStringContainsString(':mod_custom:', $custom);
        $this->assertStringContainsString(':mod_menu:', $menu);

        // A mod_menu draft cannot cross into the mod_custom lineage and vice versa.
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($custom, $this->payloadFor($menuSchema), 1), 'invalid_payload');
        $this->assertFailure(fn () => $provider->normalizeCreatePayload($menu, $this->payloadFor($customSchema), 1), 'invalid_payload');
    }

    private function realCustomSchema(): AutosaveDynamicSchema
    {
        return (new ModuleAutosaveSchemaFactory())->fromForm($this->realForm('mod_custom'));
    }

    private function realMenuSchema(): AutosaveDynamicSchema
    {
        return (new ModuleAutosaveSchemaFactory())->fromForm($this->realForm('mod_menu'));
    }

    private function realForm(string $module): Form
    {
        $form = new Form('module', ['control' => 'jform']);
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_modules/forms/module.xml');
        $form->loadFile(JPATH_SITE . '/modules/' . $module . '/' . $module . '.xml', false, '//config');

        return $form;
    }

    private function payloadFor(AutosaveDynamicSchema $schema): array
    {
        $params = [];

        foreach ($schema->fields() as $field) {
            $params[$field['path'][1]] = $this->sample($field);
        }

        return ['title' => 'Draft', 'note' => '', 'version_note' => '', 'showtitle' => '1', 'position' => 'position-7', 'content' => '', 'schemaFingerprint' => $schema->fingerprint(), 'params' => $params];
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

    private function customSchema(): AutosaveDynamicSchema
    {
        return new AutosaveDynamicSchema([
            ['path' => ['params', 'prepare_content'], 'id' => 'jform_params_prepare_content', 'kind' => 'enum', 'values' => ['0', '1']],
            ['path' => ['params', 'moduleclass_sfx'], 'id' => 'jform_params_moduleclass_sfx', 'kind' => 'string', 'maxLength' => 255],
        ]);
    }

    private function menuSchema(): AutosaveDynamicSchema
    {
        return new AutosaveDynamicSchema([
            ['path' => ['params', 'startLevel'], 'id' => 'jform_params_startLevel', 'kind' => 'enum', 'values' => ['0', '1', '2']],
            ['path' => ['params', 'endLevel'], 'id' => 'jform_params_endLevel', 'kind' => 'enum', 'values' => ['0', '1', '2']],
        ]);
    }

    /**
     * Build a provider with deterministic test resolvers. The extension resolver is
     * injected because the production default queries the real extension registry,
     * which is unavailable in the unit environment.
     *
     * @param   callable(object): AutosaveDynamicSchema|null  $resolver
     */
    private function provider(?array $schemas = [], ?callable $extension = null, array $options = [], ?callable $resolver = null): ModuleAutosaveProvider
    {
        return new ModuleAutosaveProvider(
            $this->database($options),
            $resolver ?? function (object $record) use ($schemas): AutosaveDynamicSchema {
                return $schemas[$record->module] ?? new AutosaveDynamicSchema([]);
            },
            $extension ?? static fn (string $module, int $client): bool => true
        );
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();

        if (\array_key_exists('row', $options)) {
            $db->method('loadObject')->willReturn($options['row']);
        } else {
            $db->method('loadObject')->willReturn(null);
        }

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

    private function assertFailure(callable $callback, string $code): void
    {
        $this->assertSame($code, $this->failure($callback)->getErrorCode());
    }
}
