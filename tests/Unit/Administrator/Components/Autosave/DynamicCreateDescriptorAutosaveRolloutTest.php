<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Autosave\FieldAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR37 com_fields.field dynamic creation descriptor rollout.
 *
 * @since  __DEPLOY_VERSION__
 */
class DynamicCreateDescriptorAutosaveRolloutTest extends UnitTestCase
{
    public function testFieldProviderExposesTheDescriptorCapabilityWithExactContracts(): void
    {
        $provider = new FieldAutosaveProvider($this->database());

        $this->assertInstanceOf(AutosaveDynamicCreateDescriptorProviderInterface::class, $provider);
        $this->assertSame('com_fields.field', $provider->getContext());
        $this->assertSame('field-create-v1', $provider->getCreateContractVersion());
        $this->assertSame('field-descriptor-v1', $provider->getStaticScopeContractVersion());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    public function testFieldCreateAuthorizationIsAlwaysDescriptorBound(): void
    {
        $provider = new FieldAutosaveProvider($this->database());
        $user     = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);

        // A Field can never be authorized without its anchored descriptor.
        $this->assertSame(
            'scope_required',
            $this->failure(fn () => $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null))->getErrorCode()
        );
    }

    public function testFieldCreateWiringIsPublishedThroughTheExactContract(): void
    {
        $providerSource   = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_fields/src/Autosave/FieldAutosaveProvider.php');
        $viewSource       = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_fields/src/View/Field/HtmlView.php');
        $controllerPath   = JPATH_ADMINISTRATOR . '/components/com_fields/src/Controller/FieldController.php';
        $controllerSource = (string) file_get_contents($controllerPath);
        $lifecyclePath    = JPATH_LIBRARIES . '/src/Autosave/AutosaveLifecycle.php';
        $interfacePath    = JPATH_LIBRARIES . '/src/Autosave/AutosaveDynamicCreateDescriptorProviderInterface.php';
        $lifecycleSource  = (string) file_get_contents($lifecyclePath);
        $interfaceSource  = (string) file_get_contents($interfacePath);
        $manifest         = file_get_contents(JPATH_ROOT . '/media_source/com_fields/joomla.asset.json');
        $jsController     = file_get_contents(JPATH_ROOT . '/media_source/com_fields/src/field-autosave-controller.es6.js');

        // The provider canonicalizes/authorizes an immutable descriptor token.
        $this->assertStringContainsString('AutosaveDynamicCreateDescriptorProviderInterface', $providerSource);
        $this->assertStringContainsString('field-create-v1', $providerSource);
        $this->assertStringContainsString('field-descriptor-v1', $providerSource);
        $this->assertStringContainsString('normalizeCreatePayload', $providerSource);
        $this->assertStringContainsString('descriptor_stale', $providerSource);
        $this->assertStringContainsString('FieldsHelper::getFieldTypes', $providerSource);
        $this->assertStringContainsString('FieldsServiceInterface', $providerSource);

        // The view publishes create mode only through the descriptor capability.
        $this->assertStringContainsString('canonicalizeStaticCreateScope($context . \'|\' . $type)', $viewSource);
        $this->assertStringContainsString('authorizeStaticCreateScope($app->getIdentity(), $createScope, AutosaveOperation::InitializeCreate, null)', $viewSource);
        $this->assertStringContainsString('getDynamicSchemaForType', $viewSource);
        $this->assertStringContainsString("state->get('field.context'", $viewSource);

        // The lifecycle routes descriptor-bound normalization through the optional
        // capability; the optional interface never touches static providers.
        $this->assertStringContainsString('normalizeProvisionalPayload', $lifecycleSource);
        $this->assertStringContainsString('instanceof AutosaveDynamicCreateDescriptorProviderInterface', $lifecycleSource);
        $this->assertStringContainsString('normalizeCreatePayload(string $descriptor, mixed $payload, int $schemaVersion): array', $interfaceSource);

        // The numeric canonical create gate stays untouched.
        $this->assertStringContainsString('AutosaveFormControllerTrait', $controllerSource);
        $this->assertStringContainsString('com_autosave.create-binding', $manifest);
        $this->assertStringContainsString('AutosaveCreateBinding', $jsController);
        $this->assertStringContainsString('createScope', $jsController);
    }

    public function testScopeFreeProvidersRemainUnaffected(): void
    {
        // PR30-PR36 static/scope-free providers do not implement the descriptor
        // capability, so the lifecycle keeps their scope-free normalization path.
        foreach (
            [
            JPATH_ADMINISTRATOR . '/components/com_content/src/Autosave/ArticleAutosaveProvider.php',
            JPATH_ADMINISTRATOR . '/components/com_users/src/Autosave/NoteAutosaveProvider.php',
            JPATH_ADMINISTRATOR . '/components/com_categories/src/Autosave/CategoryAutosaveProvider.php',
            JPATH_ADMINISTRATOR . '/components/com_languages/src/Autosave/OverrideAutosaveProvider.php',
            ] as $providerFile
        ) {
            $this->assertStringNotContainsString(
                'AutosaveDynamicCreateDescriptorProviderInterface',
                (string) file_get_contents($providerFile)
            );
        }
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(
            \array_key_exists('row', $options) ? $options['row'] : (object) ['id' => 42, 'context' => 'com_content.article', 'type' => 'text', 'created_user_id' => 7, 'checked_out' => 0]
        );

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
