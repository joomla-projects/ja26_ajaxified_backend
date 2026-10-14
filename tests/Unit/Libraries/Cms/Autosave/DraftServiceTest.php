<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Autosave\ArticleAutosaveProvider;
use Joomla\Component\Content\Administrator\Extension\ContentComponent;
use Joomla\Component\Content\Administrator\Model\ArticleModel;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Native prepared Article projection and actual draft SQL through the public service.
 */
class DraftServiceTest extends UnitTestCase
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
        $this->db = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'draft_service_']);
        $this->installSchema('postgresql');
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
        $this->service = new DraftService($this->resolver, $this->store);
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
        return $this->service->execute('preserve', [
            'draft_id' => $draft['draft_id'], 'client_revision' => $revision, 'schema_version' => 1, 'payload' => ['title' => $title],
        ], $this->user, $this->now);
    }

    /** @dataProvider incompatibleRecoveryControls */
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
        $this->service->reconcile($this->user, ['draft_id' => $fresh['draft_id'], 'revision' => '1',
            'source_id'                                    => $source['draft_id'], 'source_revision' => '1'], 'com_content.article', '42', [], 'save', '42', null, $this->now);
        $next = $this->open();
        $this->assertSame([], $this->service->execute('detect', ['draft_id' => $next['draft_id']], $this->user, $this->now)['candidates']);
        $this->assertSame('saved', $this->item->title);
    }

    public function testDetectionAllowsAllRetainedRecordDraftsThroughBoundedPages(): void
    {
        $ids = [];
        for ($index = 0; $index < 103; $index++) {
            $draft = $this->open();
            $this->write($draft, 1, 'unfinished ' . $index);
            $ids[] = $draft['draft_id'];
        }
        $fresh = $this->open();
        $first = $this->service->execute('detect', ['draft_id' => $fresh['draft_id']], $this->user, $this->now);
        $this->assertCount(100, $first['candidates']);
        $second = $this->service->execute('detect', ['draft_id' => $fresh['draft_id'], 'cursor' => $first['next']], $this->user, $this->now);
        $this->assertCount(3, $second['candidates']);
        $this->assertNull($second['next']);
        $this->assertEqualsCanonicalizing($ids, array_column(array_merge($first['candidates'], $second['candidates']), 'draft_id'));
    }

    public function testFreshCreationNeverOffersAnotherCreationsDraft(): void
    {
        $this->item->id = 0;
        $source         = $this->open();
        $this->write($source, 1, 'another creation');
        $fresh = $this->open();
        $found = $this->service->execute('detect', ['draft_id' => $fresh['draft_id']], $this->user, $this->now);
        $this->assertSame([], $found['candidates']);
        $this->assertNull($found['next']);
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
        $found = $this->service->execute('detect', ['draft_id' => $fresh['draft_id'],
            'creation_id'                                      => $source['draft_id'], 'acknowledged_id' => $source['draft_id']], $this->user, $this->now);
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
        $found   = $this->service->execute('detect', ['draft_id' => $fresh['draft_id'],
            'creation_id'                                        => $pending['draft_id'], 'acknowledged_id' => $acknowledged['draft_id']], $this->user, $this->now);
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
        $found = $this->service->execute('detect', ['draft_id' => $fresh['draft_id'],
            'creation_id'                                      => $pending['draft_id'], 'acknowledged_id' => $acknowledged['draft_id']], $this->user, $this->now);
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
        $found          = $this->service->execute('detect', ['draft_id' => $fresh['draft_id'],
            'creation_id'                                               => $otherOwner['draft_id']], $this->user, $this->now);
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
        $this->assertNull($this->store->read(7, $draft['draft_id'], $this->now)['payload']);
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
    /** Server-owned render metadata, never a browser-supplied descriptor. */
    private function rendered(): array
    {
        $values = [];
        $session = $this->createMock(\Joomla\Session\SessionInterface::class);
        $session->method('get')->willReturnCallback(static function ($name, $default = null) use (&$values) {
            return $values[$name] ?? $default;
        });
        $session->method('set')->willReturnCallback(static function ($name, $value) use (&$values) {
            $values[$name] = $value;
        });
        $draft = $this->service->open($this->user, $this->provider->describe($this->form(), $this->item, $this->user), $this->now, $session);

        return [$draft, $values[DraftService::RENDER_ADMISSIONS][$draft['draft_id']]];
    }

    public static function emptyExpiration(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider emptyExpiration */
    public function testAuthorizedFirstEditResumesLogicalExpiryAndPhysicalPurge(bool $purged): void
    {
        [$draft, $admission] = $this->rendered();
        $now = new \Joomla\CMS\Date\Date('2026-10-11 01:01:00', 'UTC');
        if ($purged) {
            $this->assertSame(1, $this->store->purge($now));
        }
        $result = $this->service->execute('resume', ['draft_id' => $draft['draft_id'], 'schema_version' => 1], $this->user, $now, $admission);
        $this->assertSame(['resumed' => true, 'draft_id' => $draft['draft_id'], 'schema_version' => 1], $result);
        $binding = $this->store->read(7, $draft['draft_id'], $now);
        $this->assertNull($binding['payload']);
        $this->assertSame($draft['created_at'], $binding['created_at']);
        $this->service->execute('preserve', ['draft_id' => $draft['draft_id'], 'schema_version' => 1, 'client_revision' => 1, 'payload' => ['title' => 'first edit after expiry']], $this->user, $now);
        $this->assertSame(['title' => 'first edit after expiry'], $this->store->read(7, $draft['draft_id'], $now)['payload']);
    }

    public function testRenderAdmissionStoresOnlyIdentityAndProjectionMetadata(): void
    {
        [$draft, $admission] = $this->rendered();
        $this->assertSame($draft['draft_id'], $admission['draft_id']);
        $this->assertEqualsCanonicalizing(['context', 'target', 'scope', 'version', 'revision', 'fields'], array_keys($admission['descriptor']));
        $this->assertArrayNotHasKey('values', $admission['descriptor']);
        $this->assertArrayNotHasKey('payload', $admission['descriptor']);
    }

    public static function rejectedResumptions(): array
    {
        return [
            ['missing', 'render_admission_missing'],
            ['owner', 'render_admission_missing'],
            ['identity', 'render_admission_missing'],
            ['scope', 'scope_conflict'],
            ['schema', 'unsupported_schema_version'],
            ['control', 'schema_version_conflict'],
            ['checkout', 'checkout_conflict'],
        ];
    }

    /** @dataProvider rejectedResumptions */
    public function testResumeRejectsInvalidAuthorityWithoutRenewingExpiry(string $change, string $code): void
    {
        [$draft, $admission] = $this->rendered();
        $request = ['draft_id' => $draft['draft_id'], 'schema_version' => 1];
        switch ($change) {
            case 'missing':
                $admission = null;
                break;
            case 'owner':
                $this->user->id = 8;
                break;
            case 'identity':
                $admission['draft_id'] = str_repeat('a', 32);
                break;
            case 'scope':
                $admission['descriptor']['scope'] = ['forged' => 'scope'];
                break;
            case 'schema':
                $request['schema_version'] = 2;
                break;
            case 'control':
                $this->formMutations = [['type' => 'list']];
                break;
            case 'checkout':
                $this->item->checked_out = 8;
                break;
        }
        $now = new \Joomla\CMS\Date\Date('2026-10-11 01:01:00', 'UTC');
        try {
            $this->service->execute('resume', $request, $this->user, $now, $admission);
            $this->fail('Invalid resumption must be rejected.');
        } catch (AutosaveException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
        }
        $this->assertSame(1, $this->store->purge($now), 'Rejected admission must not renew the expired empty row.');
    }

    public function testResumeEnvelopeCannotSupplyBrowserAdmission(): void
    {
        [$draft, $admission] = $this->rendered();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute('resume', ['draft_id' => $draft['draft_id'], 'schema_version' => 1, 'descriptor' => $admission['descriptor']], $this->user, $this->now, $admission);
    }

    public function testResumeCannotAdoptChangedCanonicalBase(): void
    {
        [$draft, $admission] = $this->rendered();
        $this->item->version++;
        $this->expectException(AutosaveException::class);
        $this->expectExceptionMessage('saved item changed');
        $this->service->execute('resume', ['draft_id' => $draft['draft_id'], 'schema_version' => 1], $this->user, new \Joomla\CMS\Date\Date('2026-10-11 01:01:00', 'UTC'), $admission);
    }

    public function testResumeRechecksRevokedAuthorityAfterPurge(): void
    {
        [$draft, $admission] = $this->rendered();
        $now = new \Joomla\CMS\Date\Date('2026-10-11 01:01:00', 'UTC');
        $this->store->purge($now);
        $this->allowed = false;
        $this->expectException(AutosaveException::class);
        $this->service->execute('resume', ['draft_id' => $draft['draft_id'], 'schema_version' => 1], $this->user, $now, $admission);
    }

    /** @dataProvider emptyExpiration */
    public function testTerminalDiscardAndNativeRetirementOutliveRenderAdmission(bool $nativeSave): void
    {
        [$draft, $admission] = $this->rendered();
        $now = new \Joomla\CMS\Date\Date('2026-10-11 01:01:00', 'UTC');
        $this->store->purge($now);
        if ($nativeSave) {
            $this->service->reconcile($this->user, ['draft_id' => $draft['draft_id'], 'revision' => '1'], 'com_content.article', '42', [], 'save', '42', null, $now, false, null, $admission);
        } else {
            $this->service->execute('discard', ['draft_id' => $draft['draft_id']], $this->user, $now, $admission);
        }
        try {
            $this->service->execute('resume', ['draft_id' => $draft['draft_id'], 'schema_version' => 1], $this->user, $now, $admission);
            $this->fail('Terminally retired admission must not resume.');
        } catch (AutosaveException $exception) {
            $this->assertSame('draft_retired', $exception->getErrorCode());
        }
        $later = new \Joomla\CMS\Date\Date('2026-10-12 01:02:00', 'UTC');
        $this->assertSame(1, $this->store->purge($later));
        $this->expectException(AutosaveException::class);
        $this->expectExceptionMessage('admission has expired');
        $this->service->execute('resume', ['draft_id' => $draft['draft_id'], 'schema_version' => 1], $this->user, $later, $admission);
    }
}
