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
use Joomla\CMS\Autosave\AutosaveProviderInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Autosave\GroupAutosaveProvider as FieldGroupAutosaveProvider;
use Joomla\Component\Guidedtours\Administrator\Autosave\StepAutosaveProvider;
use Joomla\Component\Menus\Administrator\Autosave\MenuAutosaveProvider;
use Joomla\Component\Users\Administrator\Autosave\GroupAutosaveProvider as UserGroupAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\StageAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\TransitionAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\WorkflowAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the component-local PR33 authority-bound new-record rollout.
 *
 * Every enabled context resolves its creation scope from trusted server-side state, so these
 * tests deliberately concentrate on the authority layer: what the browser cannot smuggle in,
 * and what must fail closed when the scope stops being resolvable.
 *
 * @since  __DEPLOY_VERSION__
 */
class AuthorityBoundNewRecordAutosaveRolloutTest extends UnitTestCase
{
    /**
     * Providers that gained a create contract in PR33, keyed by context.
     */
    private const PROVIDER_FILES = [
        'com_guidedtours.step'    => 'com_guidedtours/src/Autosave/StepAutosaveProvider.php',
        'com_users.group'         => 'com_users/src/Autosave/GroupAutosaveProvider.php',
        'com_workflow.transition' => 'com_workflow/src/Autosave/TransitionAutosaveProvider.php',
        'com_menus.menu'          => 'com_menus/src/Autosave/MenuAutosaveProvider.php',
        'com_workflow.workflow'   => 'com_workflow/src/Autosave/WorkflowAutosaveProvider.php',
        'com_fields.group'        => 'com_fields/src/Autosave/GroupAutosaveProvider.php',
        'com_workflow.stage'      => 'com_workflow/src/Autosave/StageAutosaveProvider.php',
    ];

    /**
     * Contexts PR33 deliberately leaves without a create contract and the repository
     * evidence that justifies the deferral.
     *
     * com_categories.category: the native new-category page never anchors the owning
     * extension in server-side user state - CategoryModel::populateState() reads the
     * "extension" input variable directly (default com_content) and the only stored key
     * (com_categories.categories.filter.extension) is written by the Categories list with
     * context suffixes for modal/forced-language layouts and can be stale or absent for
     * direct, redirected or save2new entry. The Autosave request carries no request scope,
     * so a static provider cannot independently verify the extension of the form the
     * browser actually opened, and the form schema itself is extension-specific
     * (loadForm('com_categories.category' . $extension)). Safe support would require the
     * extension to be immutably bound to the provisional identity - new shared
     * creation-state architecture that is outside the PR30/31/32 contract.
     */
    private const DEFERRED = [
        'com_categories.category' => ['com_categories/src/Autosave/CategoryAutosaveProvider.php', 'com_categories/src/View/Category/HtmlView.php'],
    ];

    /**
     * @dataProvider contextProvider
     */
    public function testProviderExposesBoundedCreateContract(string $context, string $contractVersion, string $component, string $view): void
    {
        $provider = $this->provider($context);

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertSame($context, $provider->getContext());
        $this->assertSame($contractVersion, $provider->getCreateContractVersion());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());

        // A resolvable scope plus the native create permission is all the contract needs.
        $provider->authorizeCreate($this->user(true), AutosaveOperation::InitializeCreate, null);

        $source = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/' . $component . '/src/View/' . $view . '/HtmlView.php'
        );

        $this->assertStringContainsString('AutosaveCreateProviderInterface', $source);
        $this->assertStringContainsString('AutosaveOperation::InitializeCreate', $source);
        $this->assertMatchesRegularExpression('/\$target(?:Id)?\s*=\s*null/', $source);
        $this->assertStringContainsString('$this->item->id', $source);
    }

    /**
     * @dataProvider contextProvider
     */
    public function testCreateAuthorizationFailsClosedWhenPermissionIsDenied(string $context): void
    {
        $provider = $this->provider($context);

        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $provider->authorizeCreate($this->user(false), AutosaveOperation::InitializeCreate, null))->getErrorCode()
        );
    }

    /**
     * @dataProvider contextProvider
     */
    public function testCreateAuthorizationIsReEvaluatedForEveryProvisionalOperation(string $context): void
    {
        $provider = $this->provider($context);
        $denied   = $this->user(false);

        // Nothing about the contract caches an earlier decision: a revoked permission fails
        // closed on the operations that mutate or read a provisional generation as well.
        foreach ([AutosaveOperation::InitializeCreate, AutosaveOperation::Preserve, AutosaveOperation::Read, AutosaveOperation::PrepareCanonicalAction] as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeCreate($denied, $operation, null))->getErrorCode()
            );
        }
    }

    /**
     * @dataProvider contextProvider
     */
    public function testExistingRecordTargetHandlingIsUnchanged(string $context): void
    {
        $provider = $this->provider($context);

        $this->assertSame('7', $provider->canonicalizeTargetId('7'));

        // A provisional identity is never a canonical target, so the browser cannot present
        // one where an existing record is expected.
        foreach (['', '0', '-1', '07', ' 7', '7 ', '7.0', 'p1:' . str_repeat('a', 64)] as $invalid) {
            $this->assertSame(
                'invalid_target',
                $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode()
            );
        }

        $this->assertFalse($this->provider($context, ['row' => null])->targetExists('7'));
    }

    public function testCreateAuthorizationNeverReadsBrowserSuppliedState(): void
    {
        foreach (self::PROVIDER_FILES as $context => $file) {
            $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/' . $file);

            // Immutable creation scope is read from plain server-side user state only. None of
            // these providers may reach into the request, and none may write session state as a
            // side effect of an Autosave call.
            foreach (['getUserStateFromRequest', 'getInput', 'setUserState', '$_GET', '$_POST', '$_REQUEST'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $context . ' must not consult ' . $forbidden . '.');
            }
        }
    }

    public function testGuidedTourStepRefusesAnUnresolvableOwningTour(): void
    {
        $allowed = $this->user(true);

        // Missing and out-of-range Tour identities never reach the database.
        foreach ([0, -1, 4294967296] as $tourId) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $this->provider('com_guidedtours.step', ['tour_id' => $tourId])->authorizeCreate($allowed, AutosaveOperation::Preserve, null))->getErrorCode()
            );
        }

        // A Tour deleted after the draft lineage started fails closed as well.
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $this->provider('com_guidedtours.step', ['count' => 0])->authorizeCreate($allowed, AutosaveOperation::Preserve, null))->getErrorCode()
        );

        // The relation may still change between generations while it stays resolvable.
        $this->provider('com_guidedtours.step', ['tour_id' => 12])->authorizeCreate($allowed, AutosaveOperation::Preserve, null);
    }

    public function testWorkflowTransitionScopesCreationToTheStoredOwningWorkflow(): void
    {
        $payload = ['title' => 'Publish', 'description' => '', 'from_stage_id' => 3, 'to_stage_id' => 5];

        // The component is taken from the stored Workflow row, so a user holding the permission
        // in a different extension cannot create here.
        $elsewhere = $this->createMock(User::class);
        $elsewhere->method('authorise')->willReturnMap([['core.create', 'com_banners.workflow.4', true]]);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $this->provider('com_workflow.transition')->authorizeCreate($elsewhere, AutosaveOperation::Preserve, $payload))->getErrorCode()
        );

        $owner = $this->createMock(User::class);
        $owner->method('authorise')->willReturnMap([['core.create', 'com_content.workflow.4', true]]);
        $this->provider('com_workflow.transition')->authorizeCreate($owner, AutosaveOperation::Preserve, $payload);

        // A stage owned by another Workflow is not a member of the resolved one.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $this->provider('com_workflow.transition', ['count' => 0])->authorizeCreate($owner, AutosaveOperation::Preserve, $payload))->getErrorCode()
        );

        // "Any" is a legal source stage, but the destination is still verified.
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $this->provider('com_workflow.transition', ['count' => 0])->authorizeCreate($owner, AutosaveOperation::Preserve, ['title' => '', 'description' => '', 'from_stage_id' => -1, 'to_stage_id' => 5]))->getErrorCode()
        );

        // An unresolvable owning Workflow fails closed before any permission is consulted.
        foreach ([['workflow_id' => 0], ['row' => null], ['row' => (object) ['id' => 4, 'extension' => '']]] as $options) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $this->provider('com_workflow.transition', $options)->authorizeCreate($owner, AutosaveOperation::Preserve, $payload))->getErrorCode()
            );
        }
    }

    public function testWorkflowRefusesAnUnresolvableOwningExtension(): void
    {
        $allowed = $this->user(true);

        foreach (['', 'content', 'com_', 'com_Content', 'com_content;drop', '../com_content', 'com_' . str_repeat('x', 60)] as $extension) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $this->provider('com_workflow.workflow', ['extension' => $extension])->authorizeCreate($allowed, AutosaveOperation::Preserve, null))->getErrorCode()
            );
        }

        // The component half of the resolved extension selects the asset, nothing else does.
        $owner = $this->createMock(User::class);
        $owner->method('authorise')->willReturnMap([['core.create', 'com_content', true]]);
        $this->provider('com_workflow.workflow', ['extension' => 'com_content.article'])->authorizeCreate($owner, AutosaveOperation::Preserve, null);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $this->provider('com_workflow.workflow', ['extension' => 'com_banners.banner'])->authorizeCreate($owner, AutosaveOperation::Preserve, null))->getErrorCode()
        );
    }

    public function testFieldGroupRefusesAnUnresolvableOwningContext(): void
    {
        $allowed = $this->user(true);

        // 'com_fields' is the bare native default and resolves to no component/section pair -
        // the same nonsense situation the native view refuses to render.
        foreach (['', 'com_fields', 'com_content', 'article', 'com_Content.article', '.article', 'com_content.'] as $context) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $this->provider('com_fields.group', ['fields_context' => $context])->authorizeCreate($allowed, AutosaveOperation::Preserve, null))->getErrorCode()
            );
        }

        $owner = $this->createMock(User::class);
        $owner->method('authorise')->willReturnMap([['core.create', 'com_content', true]]);
        $this->provider('com_fields.group', ['fields_context' => 'com_content.article'])->authorizeCreate($owner, AutosaveOperation::Preserve, null);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $this->provider('com_fields.group', ['fields_context' => 'com_banners.banner'])->authorizeCreate($owner, AutosaveOperation::Preserve, null))->getErrorCode()
        );
    }

    public function testWorkflowStageScopesCreationToTheStoredOwningWorkflow(): void
    {
        $payload = ['title' => 'Published', 'description' => ''];

        // The component is taken from the stored Workflow row, so a user holding the
        // permission in a different extension cannot create a Stage here.
        $elsewhere = $this->createMock(User::class);
        $elsewhere->method('authorise')->willReturnMap([['core.create', 'com_banners.workflow.4', true]]);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $this->provider('com_workflow.stage')->authorizeCreate($elsewhere, AutosaveOperation::Preserve, $payload))->getErrorCode()
        );

        $owner = $this->createMock(User::class);
        $owner->method('authorise')->willReturnMap([['core.create', 'com_content.workflow.4', true]]);
        $this->provider('com_workflow.stage')->authorizeCreate($owner, AutosaveOperation::Preserve, $payload);

        // An unresolvable owning Workflow fails closed before any permission is consulted.
        foreach ([['workflow_id' => 0], ['workflow_id' => -1], ['workflow_id' => 2147483648], ['row' => null], ['row' => (object) ['id' => 4, 'extension' => '']]] as $options) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $this->provider('com_workflow.stage', $options)->authorizeCreate($owner, AutosaveOperation::Preserve, $payload))->getErrorCode()
            );
        }

        // A Stage cannot smuggle its owning Workflow into the draft payload: the schema
        // authors title/description only, so placement stays entirely with the native model.
        $provider = $this->provider('com_workflow.stage');
        $this->assertSame($payload, $provider->normalizePayload($payload, 1));
        foreach ([['title' => 'Published', 'description' => '', 'workflow_id' => 9], ['workflow_id' => 9], ['title' => 'Published']] as $invalid) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode()
            );
        }
    }

    public function testUserGroupRequiresComponentAdministrationAndAuthorsNoHierarchy(): void
    {
        $provider = $this->provider('com_users.group');

        foreach ([['core.admin', 'com_users'], ['core.create', 'com_users']] as $granted) {
            $partial = $this->createMock(User::class);
            $partial->method('authorise')->willReturnMap([[...$granted, true]]);
            $partial->method('getAuthorisedCategories')->willReturn([]);
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeCreate($partial, AutosaveOperation::InitializeCreate, null))->getErrorCode()
            );
        }

        $administrator = $this->createMock(User::class);
        $administrator->method('authorise')->willReturnMap([['core.admin', 'com_users', true], ['core.create', 'com_users', true]]);
        $provider->authorizeCreate($administrator, AutosaveOperation::InitializeCreate, null);

        // Placement stays entirely with the native model: a parent relation cannot even be
        // expressed in the draft payload, so Autosave cannot reach a privileged group.
        $this->assertSame(['title' => 'Editors'], $provider->normalizePayload(['title' => 'Editors'], 1));
        foreach ([['title' => 'Editors', 'parent_id' => 1], ['parent_id' => 1], ['title' => 'Editors', 'lft' => 2]] as $invalid) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode()
            );
        }
    }

    public function testMenuAuthorsNeitherClientNorMenuType(): void
    {
        $provider = $this->provider('com_menus.menu');

        $this->assertSame(['title' => 'Main', 'description' => ''], $provider->normalizePayload(['title' => 'Main', 'description' => ''], 1));

        // client_id is immutable creation scope and menutype is the natural key the native
        // controller guards; neither is part of the bounded draft payload.
        foreach ([['title' => 'Main', 'description' => '', 'client_id' => 1], ['title' => 'Main', 'description' => '', 'menutype' => 'mainmenu'], ['title' => 'Main']] as $invalid) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode()
            );
        }
    }

    public function testDeferredContextsRemainWithoutCreateSupport(): void
    {
        foreach (self::DEFERRED as $context => [$providerFile, $viewFile]) {
            $providerSource = file_get_contents(JPATH_ADMINISTRATOR . '/components/' . $providerFile);
            $viewSource     = file_get_contents(JPATH_ADMINISTRATOR . '/components/' . $viewFile);

            $this->assertStringNotContainsString('AutosaveCreateProviderInterface', $providerSource, $context . ' is deferred.');
            $this->assertStringNotContainsString('authorizeCreate', $providerSource, $context . ' is deferred.');
            $this->assertStringNotContainsString('authorizeCreate', $viewSource, $context . ' is deferred.');
            $this->assertStringNotContainsString('AutosaveOperation::InitializeCreate', $viewSource, $context . ' is deferred.');
        }
    }

    public static function contextProvider(): array
    {
        return [
            'guided tour step'    => ['com_guidedtours.step', 'guided-tour-step-create-v1', 'com_guidedtours', 'Step'],
            'user group'          => ['com_users.group', 'user-group-create-v1', 'com_users', 'Group'],
            'workflow transition' => ['com_workflow.transition', 'workflow-transition-create-v1', 'com_workflow', 'Transition'],
            'menu'                => ['com_menus.menu', 'menu-create-v1', 'com_menus', 'Menu'],
            'workflow'            => ['com_workflow.workflow', 'workflow-create-v1', 'com_workflow', 'Workflow'],
            'field group'         => ['com_fields.group', 'field-group-create-v1', 'com_fields', 'Group'],
            'workflow stage'      => ['com_workflow.stage', 'workflow-stage-create-v1', 'com_workflow', 'Stage'],
        ];
    }

    /**
     * Builds a provider whose server-side scope resolver is injected rather than read from a
     * live application, mirroring how the component service provider wires the default.
     */
    private function provider(string $context, array $options = []): AutosaveProviderInterface
    {
        $db = $this->database($options);

        return match ($context) {
            'com_guidedtours.step'    => new StepAutosaveProvider($db, static fn (): int => $options['tour_id'] ?? 8),
            'com_users.group'         => new UserGroupAutosaveProvider($db),
            'com_workflow.transition' => new TransitionAutosaveProvider($db, static fn (): int => $options['workflow_id'] ?? 4),
            'com_menus.menu'          => new MenuAutosaveProvider($db),
            'com_workflow.workflow'   => new WorkflowAutosaveProvider($db, static fn (): string => $options['extension'] ?? 'com_content.article'),
            'com_fields.group'        => new FieldGroupAutosaveProvider($db, static fn (): string => $options['fields_context'] ?? 'com_content.article'),
            'com_workflow.stage'      => new StageAutosaveProvider($db, static fn (): int => $options['workflow_id'] ?? 4),
            default                   => throw new \InvalidArgumentException($context . ' is not part of the PR33 rollout.'),
        };
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(
            \array_key_exists('row', $options) ? $options['row'] : (object) ['id' => 4, 'extension' => 'com_content.article']
        );
        $db->method('loadResult')->willReturn($options['count'] ?? 1);

        return $db;
    }

    private function user(bool $allowed): User
    {
        $user = $this->createMock(User::class);
        $user->method('authorise')->willReturn($allowed);
        $user->method('getAuthorisedCategories')->willReturn([]);

        return $user;
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
