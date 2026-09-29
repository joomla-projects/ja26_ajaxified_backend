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
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveProvider;
use Joomla\Component\Modules\Administrator\Autosave\ModuleAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR38 com_menus.item and com_modules.module dynamic-host rollout.
 *
 * @since  __DEPLOY_VERSION__
 */
class DynamicHostAutosaveRolloutTest extends UnitTestCase
{
    public function testMenuAndModuleProvidersExposeTheDescriptorCapabilityWithExactContracts(): void
    {
        $item   = new ItemAutosaveProvider($this->database(), static fn (object $record) => new \Joomla\CMS\Autosave\AutosaveDynamicSchema([]));
        $module = new ModuleAutosaveProvider($this->database(), static fn (object $record) => new \Joomla\CMS\Autosave\AutosaveDynamicSchema([]));

        $this->assertInstanceOf(AutosaveDynamicCreateDescriptorProviderInterface::class, $item);
        $this->assertInstanceOf(AutosaveDynamicCreateDescriptorProviderInterface::class, $module);
        $this->assertSame('com_menus.item', $item->getContext());
        $this->assertSame('com_modules.module', $module->getContext());
        $this->assertSame('menu-item-create-v1', $item->getCreateContractVersion());
        $this->assertSame('module-create-v1', $module->getCreateContractVersion());
        $this->assertSame('menu-item-descriptor-v1', $item->getStaticScopeContractVersion());
        $this->assertSame('module-descriptor-v1', $module->getStaticScopeContractVersion());
        $this->assertSame(1, $item->getPayloadSchemaVersion());
        $this->assertSame(1, $module->getPayloadSchemaVersion());
    }

    public function testCreateAuthorizationIsAlwaysDescriptorBoundForBothHosts(): void
    {
        $item   = new ItemAutosaveProvider($this->database(), static fn (object $record) => new \Joomla\CMS\Autosave\AutosaveDynamicSchema([]));
        $module = new ModuleAutosaveProvider($this->database(), static fn (object $record) => new \Joomla\CMS\Autosave\AutosaveDynamicSchema([]));
        $user   = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);

        foreach ([$item, $module] as $provider) {
            $this->assertSame(
                'scope_required',
                $this->failure(fn () => $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null))->getErrorCode()
            );
        }
    }

    public function testProductionWiringPublishesCreateModeThroughTheExactContract(): void
    {
        $itemView         = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/src/View/Item/HtmlView.php');
        $moduleView       = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_modules/src/View/Module/HtmlView.php');
        $itemProvider     = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/src/Autosave/ItemAutosaveProvider.php');
        $moduleProvider   = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_modules/src/Autosave/ModuleAutosaveProvider.php');
        $itemController   = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/src/Controller/ItemController.php');
        $moduleController = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_modules/src/Controller/ModuleController.php');
        $itemManifest     = file_get_contents(JPATH_ROOT . '/media_source/com_menus/joomla.asset.json');
        $moduleManifest   = file_get_contents(JPATH_ROOT . '/media_source/com_modules/joomla.asset.json');
        $itemJs           = file_get_contents(JPATH_ROOT . '/media_source/com_menus/src/item-autosave-controller.es6.js');
        $moduleJs         = file_get_contents(JPATH_ROOT . '/media_source/com_modules/src/module-autosave-controller.es6.js');

        foreach ([$itemProvider, $moduleProvider] as $source) {
            $this->assertStringContainsString('AutosaveDynamicCreateDescriptorProviderInterface', $source);
            $this->assertStringContainsString('canonicalizeStaticCreateScope', $source);
            $this->assertStringContainsString('normalizeCreatePayload', $source);
            $this->assertStringContainsString('descriptor_stale', $source);
            $this->assertStringContainsString('verifyFinalTargetStaticScope', $source);
        }

        $this->assertStringContainsString('createScopeCandidate', $itemProvider);
        $this->assertStringContainsString('createScopeCandidate', $moduleProvider);
        $this->assertStringContainsString('createScopeCandidate(', $itemView);
        $this->assertStringContainsString('createScopeCandidate(', $moduleView);
        $this->assertStringContainsString('canonicalizeStaticCreateScope($candidate)', $itemView);
        $this->assertStringContainsString('canonicalizeStaticCreateScope($candidate)', $moduleView);
        $this->assertStringContainsString('getDynamicSchemaForScope($createScope)', $itemView);
        $this->assertStringContainsString('getDynamicSchemaForScope($createScope)', $moduleView);
        $this->assertStringContainsString('AutosaveFormControllerTrait', $itemController);
        $this->assertStringContainsString('AutosaveFormControllerTrait', $moduleController);
        $this->assertStringContainsString('com_autosave.create-binding', $itemManifest);
        $this->assertStringContainsString('com_autosave.create-binding', $moduleManifest);
        $this->assertStringContainsString('AutosaveCreateBinding', $itemJs);
        $this->assertStringContainsString('AutosaveCreateBinding', $moduleJs);
        $this->assertStringContainsString('createScope', $itemJs);
        $this->assertStringContainsString('createScope', $moduleJs);
    }

    public function testTemplateStyleNewRecordRemainsIntentionallyExcluded(): void
    {
        // PR38 must not enable new-record Autosave for com_templates.style (the
        // intentional ID-0 exclusion). The Style provider keeps no create capability.
        $styleProvider = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_templates/src/Autosave/StyleAutosaveProvider.php');

        $this->assertStringNotContainsString('AutosaveCreateProviderInterface', $styleProvider);
        $this->assertStringNotContainsString('AutosaveDynamicCreateDescriptorProviderInterface', $styleProvider);
    }

    public function testNoNewDynamicHostStorageOrTablesAreIntroduced(): void
    {
        // Both hosts reuse the PR37 create_scope immutable CAS binding; PR38 adds no
        // descriptor columns or storage.
        foreach (
            [
            JPATH_ADMINISTRATOR . '/components/com_menus/src/Autosave/ItemAutosaveProvider.php',
            JPATH_ADMINISTRATOR . '/components/com_modules/src/Autosave/ModuleAutosaveProvider.php',
            JPATH_ADMINISTRATOR . '/components/com_menus/src/View/Item/HtmlView.php',
            JPATH_ADMINISTRATOR . '/components/com_modules/src/View/Module/HtmlView.php',
            ] as $file
        ) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('menu_descriptor', $source);
            $this->assertStringNotContainsString('module_descriptor', $source);
            $this->assertStringNotContainsString('dynamic_host_descriptor', $source);
            $this->assertStringNotContainsString('AutosaveStorage', $source);
            $this->assertStringNotContainsString('create_scope', $source);
        }
    }

    public function testScopeFreeAndStaticProvidersRemainUnaffected(): void
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

    private function database(): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(null);
        $db->method('loadResult')->willReturn(null);

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
