<?php

/**
 * @package   Joomla.IntegrationTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Libraries\Cms\Autosave;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Autosave\ArticleAutosaveProvider;
use Joomla\Component\Content\Administrator\Extension\ContentComponent;
use Joomla\Component\Content\Administrator\Model\ArticleModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Prepared Article fields and draft storage through the public service.
 */
class DraftServiceTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use DraftDatabaseFixture;

    private DatabaseInterface $db;
    private DraftService $service;
    private AutosaveContextResolver $resolver;
    private ArticleAutosaveProvider $provider;
    private User $user;
    private object $item;
    private bool $allowed        = true;
    private array $formMutations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->getDBDriver();
        $this->initializeStore();
        $this->user        = $this->createMock(User::class);
        $this->user->id    = 7;
        $this->user->guest = false;
        $this->user->method('authorise')->willReturnCallback(fn (): bool => $this->allowed);
        $this->user->method('getAuthorisedCategories')->willReturn([]);
        $this->item = (object) [
            'id'    => 42, 'title' => 'saved', 'alias' => 'saved', 'articletext' => 'saved body',
            'catid' => 2, 'version' => 5, 'created_by' => 7, 'checked_out' => 0,
        ];
        $model = $this->createMock(ArticleModel::class);
        $model->expects($this->never())->method('save');
        $model->method('getItem')->willReturnCallback(fn () => clone $this->item);
        $model->method('getForm')->willReturnCallback(fn () => $this->form());
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $this->provider = new ArticleAutosaveProvider($factory);
        $component      = new ContentComponent($this->createMock(ComponentDispatcherFactoryInterface::class));
        $component->setAutosaveProvider('com_content.article', $this->provider);
        $app = $this->createMock(CMSApplicationInterface::class);
        $app->method('bootComponent')->willReturn($component);
        $this->resolver = new AutosaveContextResolver($app, static fn (): bool => true);
        $this->service  = new DraftService($this->resolver, $this->store);
    }

    private function form(): Form
    {
        $form = new Form('com_content.article', ['control' => 'jform']);
        $form->setCurrentUser($this->user);
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_content/forms/article.xml');
        foreach (array_shift($this->formMutations) ?? [] as $attribute => $value) {
            if ($attribute === 'removed') {
                $form->removeField('title');
            } else {
                $form->setFieldAttribute('title', $attribute, $value);
            }
        }

        $form->bind(['title' => 'native error values', 'catid' => '2']);

        return $form;
    }

    private function open(): array
    {
        return $this->service->open($this->user, $this->provider->describe($this->form(), $this->item, $this->user), $this->now);
    }

    private function write(array $draft, int $revision, string $title): array
    {
        return $this->service->execute(
            'preserve',
            [
            'draft_id' => $draft['draft_id'], 'client_revision' => $revision, 'schema_version' => 1, 'payload' => ['title' => $title],
            ],
            $this->user,
            $this->now
        );
    }

    public function testQuotaMetadataIsOwnerOnlyWithoutBootingDisabledOrRevokedComponents(): void
    {
        $draft      = $this->open();
        $descriptor = $this->provider->describe($this->form(), $this->item, $this->user);
        $this->store->open(8, $descriptor, $this->now);
        $user        = $this->createMock(User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturnCallback(static fn (string $action): bool => $action === 'core.login.admin');
        $app = $this->createMock(CMSApplicationInterface::class);
        $app->expects($this->never())->method('bootComponent');
        $service = new DraftService(new AutosaveContextResolver($app, static fn (): bool => false), $this->store);
        $headers = $service->allocations($user);
        $this->assertSame([$draft['draft_id']], array_column($headers, 'draft_id'));
        $this->assertSame(
            [
            'draft_id', 'context', 'target_id', 'created_at', 'updated_at', 'expires_at', 'has_preserved_values', 'has_save_marker',
            ],
            array_keys($headers[0])
        );
        $this->assertSame('2026-10-11T00:00:00Z', $headers[0]['created_at']);
        $this->assertSame('2026-10-11T00:00:00Z', $headers[0]['updated_at']);
        $this->assertSame('2026-10-12T00:00:00Z', $headers[0]['expires_at']);
        $this->assertFalse($headers[0]['has_preserved_values']);
        $this->assertFalse($headers[0]['has_save_marker']);
        // This deletion metadata gives no additional content access.
        $this->allowed = false;
        try {
            $this->write($draft, 1, 'not authorised');
            $this->fail('Revoked editing must remain forbidden.');
        } catch (AutosaveException $exception) {
            $this->assertSame('forbidden', $exception->getErrorCode());
        }
        $this->assertSame(['discarded' => true], $service->execute('discard', ['draft_id' => $draft['draft_id']], $user, $this->now));
    }

    /**
     * @dataProvider unauthorisedMetadataUsers
     */
    public function testQuotaMetadataRequiresAnAuthenticatedAdministrator(int $id, bool $guest, bool $allowed): void
    {
        $user        = $this->createMock(User::class);
        $user->id    = $id;
        $user->guest = $guest;
        $user->method('authorise')->willReturn($allowed);
        $this->expectException(AutosaveException::class);
        $this->expectExceptionMessage('Private draft allocation metadata requires an authenticated administrator.');
        $this->service->allocations($user);
    }

    public static function unauthorisedMetadataUsers(): array
    {
        return [[7, true, true], [0, false, true], [7, false, false]];
    }

    /**
     * @dataProvider providerIdentities
     */
    public function testProviderReadsReceiveOnlyIdentityAndSeparatePayload(?string $target, array $scope): void
    {
        $descriptor             = $this->provider->describe($this->form(), $this->item, $this->user);
        $descriptor['target']   = $target;
        $descriptor['scope']    = $scope;
        $descriptor['revision'] = $target === null ? null : $descriptor['revision'];
        $identity               = ['context' => $descriptor['context'], 'target_id' => $target, 'scope' => $scope];
        $payloads               = [['title' => 'recoverable'], null, null, ['title' => 'recoverable']];
        $reads                  = 0;
        $provider               = $this->createMock(DraftProviderInterface::class);
        $provider->method('getContext')->willReturn($descriptor['context']);
        $provider->expects($this->exactly(4))->method('read')->willReturnCallback(
            function (array $binding, User $user, ?array $payload) use ($identity, $descriptor, $payloads, &$reads): array {
                $this->assertSame($identity, $binding);
                $this->assertSame($this->user, $user);
                $this->assertSame($payloads[$reads++], $payload);

                return $descriptor;
            }
        );
        $component = new ContentComponent($this->createMock(ComponentDispatcherFactoryInterface::class));
        $component->setAutosaveProvider($descriptor['context'], $provider);
        $app = $this->createMock(CMSApplicationInterface::class);
        $app->method('bootComponent')->willReturn($component);
        $this->service = new DraftService(new AutosaveContextResolver($app, static fn (): bool => true), $this->store);

        $source = $this->service->open($this->user, $descriptor, $this->now);
        $this->assertSame(1, $this->write($source, 1, 'recoverable')['client_revision']);
        $fresh   = $this->service->open($this->user, $descriptor, $this->now);
        $request = ['draft_id' => $fresh['draft_id']];
        if ($target === null) {
            $request['creation_id'] = $source['draft_id'];
        }
        $found = $this->service->execute('detect', $request, $this->user, $this->now);
        $this->assertSame([$source['draft_id']], array_column($found['candidates'], 'draft_id'));
        $read = $this->service->execute('read', ['draft_id' => $fresh['draft_id'], 'source_id' => $source['draft_id']], $this->user, $this->now);
        $this->assertSame(['title' => 'recoverable'], $read['payload']);
        $this->assertSame(['title' => 'saved'], $read['canonical']);
        $stored = $this->store->read(7, $source['draft_id'], $this->now);
        $this->assertSame(7, $stored['owner_id']);
        $this->assertSame($descriptor['fields'], $stored['fields']);
        $this->assertSame($descriptor['revision'], $stored['baseline_revision']);
        $this->assertSame(['discarded' => true], $this->service->execute('discard', ['draft_id' => $source['draft_id']], $this->user, $this->now));
    }

    public static function providerIdentities(): array
    {
        return [
            'saved item'    => ['42', []],
            'new item'      => [null, []],
            'scoped item'   => ['42', ['extension' => 'com_content']],
            'text identity' => ['COM_EXAMPLE', ['client' => 'site', 'language' => 'en-GB']],
        ];
    }

    /**
     * @dataProvider incompatibleRecoveryControls
     */
    public function testRecoveryOmitsCurrentIncompatibleControlsWithoutRetiringItsSource(array $mutation, bool $sourceOnly): void
    {
        $source = $this->open();
        $this->write($source, 1, 'recoverable');
        $fresh               = $this->open();
        $this->formMutations = $sourceOnly ? [[], $mutation] : [$mutation, []];
        $read                = $this->service->execute('read', ['draft_id' => $fresh['draft_id'], 'source_id' => $source['draft_id']], $this->user, $this->now);
        $this->assertSame([], $read['payload']);
        $this->assertSame(['title'], $read['omitted']);
        $this->assertSame(['title' => 'recoverable'], $this->store->read(7, $source['draft_id'], $this->now)['payload']);
        $this->assertSame(0, $this->store->read(7, $source['draft_id'], $this->now)['retired_revision']);
    }

    public static function incompatibleRecoveryControls(): array
    {
        $cases = [];
        foreach ([['type' => 'list'], ['type' => 'list', 'multiple' => 'true'], ['type' => 'editor'], ['readonly' => 'true'], ['disabled' => 'true'], ['removed' => true]] as $mutation) {
            foreach ([false, true] as $sourceOnly) {
                $cases[] = [$mutation, $sourceOnly];
            }
        }
        return $cases;
    }


    public function testDetectionAndReviewLeaveNativeCanonicalAndErrorStateValuesUntouched(): void
    {
        $source = $this->open();
        $this->write($source, 1, 'unfinished draft');
        $form  = $this->form();
        $fresh = $this->service->open($this->user, $this->provider->describe($form, $this->item, $this->user), $this->now);
        $found = $this->service->execute('detect', ['draft_id' => $fresh['draft_id']], $this->user, $this->now);
        $this->assertSame($source['draft_id'], $found['candidates'][0]['draft_id']);
        $this->assertSame('saved', $this->item->title);
        $this->assertSame('native error values', $form->getValue('title'));
        $read = $this->service->execute('read', ['draft_id' => $fresh['draft_id'], 'source_id' => $source['draft_id']], $this->user, $this->now);
        $this->assertSame(['title' => 'saved'], $read['canonical']);
        $this->assertSame(['title' => 'unfinished draft'], $read['payload']);
        $this->assertSame('native error values', $form->getValue('title'));
        $this->service->reconcile(
            $this->user,
            ['draft_id' => $fresh['draft_id'], 'revision' => '1',
            'source_id'                                    => $source['draft_id'], 'source_revision' => '1'],
            'com_content.article',
            '42',
            [],
            'save',
            '42',
            null,
            $this->now
        );
        $next = $this->open();
        $this->assertSame([], $this->service->execute('detect', ['draft_id' => $next['draft_id']], $this->user, $this->now)['candidates']);
        $this->assertSame('saved', $this->item->title);
    }

    public function testDetectionReturnsAllEligibleDraftsWithinTheOwnerLimit(): void
    {
        $ids = [];
        for ($index = 0; $index < \Joomla\CMS\Autosave\DraftStore::MAX_OWNER_ROWS - 1; $index++) {
            $draft = $this->open();
            $this->write($draft, 1, 'unfinished ' . $index);
            $ids[] = $draft['draft_id'];
        }
        $fresh = $this->open();
        $first = $this->service->execute('detect', ['draft_id' => $fresh['draft_id']], $this->user, $this->now);
        $this->assertCount(63, $first['candidates']);
        $this->assertSame(['candidates', 'conflict'], array_keys($first));
        $this->assertEqualsCanonicalizing($ids, array_column($first['candidates'], 'draft_id'));
    }

    public function testFreshCreationNeverOffersAnotherCreationsDraft(): void
    {
        $this->item->id = 0;
        $source         = $this->open();
        $this->write($source, 1, 'another creation');
        $fresh = $this->open();
        $found = $this->service->execute('detect', ['draft_id' => $fresh['draft_id']], $this->user, $this->now);
        $this->assertSame([], $found['candidates']);
        $this->assertSame(['candidates', 'conflict'], array_keys($found));
        $this->assertSame(['title' => 'another creation'], $this->store->read(7, $source['draft_id'], $this->now)['payload']);
    }

    public function testCreationReloadOffersOnlyItsOwnReferencedUnfinishedWork(): void
    {
        $this->item->id = 0;
        $source         = $this->open();
        $this->write($source, 1, 'this creation');
        $other = $this->open();
        $this->write($other, 1, 'another tab');
        $fresh = $this->open();
        $found = $this->service->execute(
            'detect',
            ['draft_id' => $fresh['draft_id'],
            'creation_id'                                      => $source['draft_id'], 'acknowledged_id' => $source['draft_id']],
            $this->user,
            $this->now
        );
        $this->assertSame([$source['draft_id']], array_column($found['candidates'], 'draft_id'));
        $this->assertTrue($found['candidates'][0]['uncertain_creation']);
        $this->assertNull($this->store->read(7, $fresh['draft_id'], $this->now)['payload']);
    }

    public function testCreationReloadCanRecoverAcknowledgedWorkAfterAnUncertainWrite(): void
    {
        $this->item->id = 0;
        $acknowledged   = $this->open();
        $this->write($acknowledged, 1, 'last acknowledged');
        $pending = $this->open();
        $fresh   = $this->open();
        $found   = $this->service->execute(
            'detect',
            ['draft_id' => $fresh['draft_id'],
            'creation_id'                                        => $pending['draft_id'], 'acknowledged_id' => $acknowledged['draft_id']],
            $this->user,
            $this->now
        );
        $this->assertSame([$acknowledged['draft_id']], array_column($found['candidates'], 'draft_id'));
    }

    public function testReloadPrefersItsLatestAttemptOverTheAcknowledgedFallback(): void
    {
        $this->item->id = 0;
        $acknowledged   = $this->open();
        $this->write($acknowledged, 1, 'previous work');
        $pending = $this->open();
        $this->write($pending, 1, 'latest work with lost response');
        $fresh = $this->open();
        $found = $this->service->execute(
            'detect',
            ['draft_id' => $fresh['draft_id'],
            'creation_id'                                      => $pending['draft_id'], 'acknowledged_id' => $acknowledged['draft_id']],
            $this->user,
            $this->now
        );
        $this->assertSame([$pending['draft_id']], array_column($found['candidates'], 'draft_id'));
    }

    public function testCreationReferencesCannotExposeForeignSavedOrRetiredWork(): void
    {
        $this->item->id = 0;
        $retired        = $this->open();
        $this->write($retired, 1, 'discarded');
        $this->service->execute('discard', ['draft_id' => $retired['draft_id']], $this->user, $this->now);
        $saved = $this->open();
        $this->write($saved, 1, 'manually saved');
        $this->service->reconcile(
            $this->user,
            ['draft_id' => $saved['draft_id'], 'revision' => '1'],
            'com_content.article',
            null,
            [],
            'save',
            '42',
            null,
            $this->now
        );
        $fresh = $this->open();
        foreach ([$saved['draft_id'], $retired['draft_id'], str_repeat('a', 32)] as $id) {
            $found = $this->service->execute('detect', ['draft_id' => $fresh['draft_id'], 'creation_id' => $id], $this->user, $this->now);
            $this->assertSame([], $found['candidates']);
        }
        $this->user->id = 8;
        $otherOwner     = $this->open();
        $this->write($otherOwner, 1, 'foreign private work');
        $this->user->id = 7;
        $found          = $this->service->execute(
            'detect',
            ['draft_id' => $fresh['draft_id'],
            'creation_id'                                               => $otherOwner['draft_id']],
            $this->user,
            $this->now
        );
        $this->assertSame([], $found['candidates']);
    }

    public function testLatePreserveCannotSilentlyReplaceRenderBaseline(): void
    {
        $draft = $this->open();
        $this->item->version++;
        try {
            $this->write($draft, 1, 'draft');
            $this->fail('Expected explicit conflict');
        } catch (AutosaveException $exception) {
            $this->assertSame('base_revision_conflict', $exception->getErrorCode());
        }
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame($draft['baseline_revision'], $row['baseline_revision']);
        $this->assertNull($row['payload']);
    }

    public function testExplicitRecoveryUsesFreshBaselineAndKeepsSource(): void
    {
        $source = $this->open();
        $this->write($source, 1, 'recoverable');
        $this->item->version++;
        $this->item->title = 'newer saved';
        $fresh             = $this->open();
        $read              = $this->service->execute('read', ['draft_id' => $fresh['draft_id'], 'source_id' => $source['draft_id']], $this->user, $this->now);
        $this->assertTrue($read['conflict']);
        $this->assertSame(['title' => 'recoverable'], $read['payload']);
        $this->assertSame(['title' => 'newer saved'], $read['canonical']);
        $this->write($fresh, 1, $read['payload']['title']);
        $this->assertSame($fresh['baseline_revision'], $this->store->read(7, $fresh['draft_id'], $this->now)['baseline_revision']);
        $this->assertSame(['title' => 'recoverable'], $this->store->read(7, $source['draft_id'], $this->now)['payload']);
    }

    public function testConfirmedSaveRetainsLaterValuesWithoutGuessingAnotherWritersRevision(): void
    {
        $draft = $this->open();
        $this->write($draft, 2, 'later acknowledged');
        $this->service->reconcile(
            $this->user,
            ['draft_id' => $draft['draft_id'], 'revision' => '1'],
            'com_content.article',
            '42',
            [],
            'apply',
            '42',
            null,
            $this->now
        );
        $this->item->version++;
        $result = $this->write($draft, 3, 'after success');
        $this->assertTrue($result['conflict']);
        $row = $this->store->read(7, $draft['draft_id'], $this->now);
        $this->assertSame(['title' => 'after success'], $row['payload']);
        $this->assertSame($draft['baseline_revision'], $row['baseline_revision']);
    }

    public function testUncertainCreationRemainsUnboundRecoverableAndDoesNotCreateBusinessRows(): void
    {
        $this->item->id = 0;
        $source         = $this->open();
        $this->write($source, 1, '');
        $fresh  = $this->open();
        $result = $this->service->execute('read', ['draft_id' => $fresh['draft_id'], 'source_id' => $source['draft_id']], $this->user, $this->now);
        $this->assertTrue($result['uncertain_creation']);
        $this->assertNull($this->store->read(7, $source['draft_id'], $this->now)['target_id']);
        $this->assertSame(['title' => ''], $result['payload']);
    }

    public function testOwnerDiscardDoesNotRequireCurrentEditAuthority(): void
    {
        $draft = $this->open();
        $this->write($draft, 1, 'private');
        $this->allowed = false;
        $this->service->execute('discard', ['draft_id' => $draft['draft_id']], $this->user, $this->now);
        $this->expectException(AutosaveException::class);
        $this->expectExceptionMessage('not found');
        $this->store->read(7, $draft['draft_id'], $this->now);
    }

    public function testPrivateReadCannotCrossTargetsOrOwners(): void
    {
        $source = $this->open();
        $this->write($source, 1, 'private');
        $this->item->id = 84;
        $fresh          = $this->open();
        try {
            $this->service->execute('read', ['draft_id' => $fresh['draft_id'], 'source_id' => $source['draft_id']], $this->user, $this->now);
            $this->fail('Expected target isolation');
        } catch (AutosaveException $exception) {
            $this->assertSame('scope_conflict', $exception->getErrorCode());
        }
        $this->user->id = 8;
        $this->expectException(AutosaveException::class);
        $this->service->execute('detect', ['draft_id' => $fresh['draft_id']], $this->user, $this->now);
    }

    public function testMalformedMetadataCannotRetireRecoverableContent(): void
    {
        $draft = $this->open();
        $this->write($draft, 1, 'retained');
        try {
            $this->service->reconcile(
                $this->user,
                ['draft_id' => $draft['draft_id'], 'revision' => '999999999999999999'],
                'com_content.article',
                '42',
                [],
                'save',
                '42',
                null,
                $this->now
            );
            $this->fail('Expected invalid optional metadata');
        } catch (\InvalidArgumentException) {
            $this->assertSame(['title' => 'retained'], $this->store->read(7, $draft['draft_id'], $this->now)['payload']);
        }
    }
    public function testCompleteRestorationCarriesSourceSequenceIntoNativeSuccess(): void
    {
        $source = $this->open();
        $this->write($source, 1, 'restored');
        $fresh = $this->open();
        $read  = $this->service->execute('read', ['draft_id' => $fresh['draft_id'], 'source_id' => $source['draft_id']], $this->user, $this->now);
        $this->assertSame(1, $read['source_revision']);
        $this->write($fresh, 1, $read['payload']['title']);
        $this->service->reconcile(
            $this->user,
            ['draft_id' => $fresh['draft_id'], 'revision' => '1', 'source_id' => $source['draft_id'], 'source_revision' => '1'],
            'com_content.article',
            '42',
            [],
            'save',
            '42',
            null,
            $this->now
        );
        $this->assertNull($this->store->read(7, $fresh['draft_id'], $this->now)['payload']);
        $this->assertNull($this->store->read(7, $source['draft_id'], $this->now)['payload']);
        $this->assertSame([], $this->service->execute('detect', ['draft_id' => $this->open()['draft_id']], $this->user, $this->now)['candidates']);
    }

    public function testIncompleteRecoveryMetadataKeepsBothSnapshots(): void
    {
        $source = $this->open();
        $this->write($source, 1, 'source');
        $fresh = $this->open();
        $this->write($fresh, 1, 'submitted');
        try {
            $this->service->reconcile(
                $this->user,
                ['draft_id' => $fresh['draft_id'], 'revision' => '1', 'source_id' => $source['draft_id']],
                'com_content.article',
                '42',
                [],
                'save',
                '42',
                null,
                $this->now
            );
            $this->fail('Expected invalid source metadata');
        } catch (\InvalidArgumentException) {
            $this->assertSame(['title' => 'source'], $this->store->read(7, $source['draft_id'], $this->now)['payload']);
            $this->assertSame(['title' => 'submitted'], $this->store->read(7, $fresh['draft_id'], $this->now)['payload']);
        }
    }
    public function testExpiredOrPurgedBindingsAreNeverRecreated(): void
    {
        $draft = $this->open();
        $later = new \Joomla\CMS\Date\Date('2026-10-12 00:00:00', 'UTC');
        foreach (['draft_expired', 'draft_not_found'] as $code) {
            try {
                $this->service->execute('preserve', ['draft_id' => $draft['draft_id'], 'client_revision' => 1, 'schema_version' => 1, 'payload' => ['title' => 'local work']], $this->user, $later);
                $this->fail('An expired or purged binding must not be recreated.');
            } catch (AutosaveException $exception) {
                $this->assertSame($code, $exception->getErrorCode());
            }
            $this->store->purge($later);
        }
    }

    public function testRemovedResumeOperationCannotSupplyANewBinding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute('resume', ['draft_id' => str_repeat('a', 32), 'schema_version' => 1], $this->user, $this->now);
    }
}
