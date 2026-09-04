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
use Joomla\CMS\User\User;
use Joomla\Component\Users\Administrator\Autosave\LevelAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR35 new-record rollout for com_users.level.
 *
 * @since  __DEPLOY_VERSION__
 */
class LevelNewRecordAutosaveRolloutTest extends UnitTestCase
{
    public function testLevelProviderExposesTheCreateCapability(): void
    {
        $provider = new LevelAutosaveProvider($this->database());

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertSame('user-level-create-v1', $provider->getCreateContractVersion());
        $this->assertSame('com_users.level', $provider->getContext());
        $this->assertSame(2, $provider->getPayloadSchemaVersion());
    }

    public function testLevelCreateAuthorizationMirrorsNativeAllowSaveForNewRecords(): void
    {
        $provider = new LevelAutosaveProvider($this->database());

        // core.admin on com_users AND (core.create on com_users or an authorised category).
        $allowed = $this->createMock(User::class);
        $allowed->method('authorise')->willReturn(true);
        $allowed->method('getAuthorisedCategories')->willReturn([]);
        $provider->authorizeCreate($allowed, AutosaveOperation::InitializeCreate, null);

        $scoped = $this->createMock(User::class);
        $scoped->method('authorise')->willReturnMap([
            ['core.admin', 'com_users', true],
            ['core.create', 'com_users', false],
            ['core.admin', null, false],
        ]);
        $scoped->method('getAuthorisedCategories')->willReturn([3]);
        $provider->authorizeCreate($scoped, AutosaveOperation::InitializeCreate, null);

        // Missing com_users core.admin fails closed on every provisional operation.
        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);
        $denied->method('getAuthorisedCategories')->willReturn([]);

        foreach (AutosaveOperation::cases() as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeCreate($denied, $operation, null))->getErrorCode()
            );
        }

        // core.admin without any create right or category fails closed.
        $adminOnly = $this->createMock(User::class);
        $adminOnly->method('authorise')->willReturnMap([
            ['core.admin', 'com_users', true],
            ['core.create', 'com_users', false],
            ['core.admin', null, false],
        ]);
        $adminOnly->method('getAuthorisedCategories')->willReturn([]);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $provider->authorizeCreate($adminOnly, AutosaveOperation::Preserve, null))->getErrorCode()
        );
    }

    public function testLevelCreatePayloadAuthorizationVerifiesRelationsAndRights(): void
    {
        // A global super user may author a payload whose groups exist. The
        // super-user group branch mirrors LevelModel::validate() and consults
        // Access::checkGroup(), so it is exercised only when the operator is
        // not a global super user.
        $provider = new LevelAutosaveProvider($this->database(['groupCount' => 1]));
        $super    = $this->createMock(User::class);
        $super->method('authorise')->willReturn(true);
        $super->method('getAuthorisedCategories')->willReturn([]);
        $provider->authorizeCreate($super, AutosaveOperation::Preserve, ['title' => 'Special', 'rules' => [1, 8]]);

        // An operator without the com_users core.admin gate fails closed even
        // when the authored groups exist.
        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);
        $denied->method('getAuthorisedCategories')->willReturn([]);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $provider->authorizeCreate($denied, AutosaveOperation::Preserve, ['title' => 'Special', 'rules' => [1]]))->getErrorCode()
        );

        // A group that does not resolve to a real user group fails as a
        // malformed relation before any authorization is granted.
        $ghost = new LevelAutosaveProvider($this->database(['groupCount' => 0]));
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $ghost->authorizeCreate($super, AutosaveOperation::Preserve, ['title' => 'Ghost', 'rules' => [99]]))->getErrorCode()
        );
    }

    public function testLevelPayloadKeepsOrderedIntegerGroupsOutOfTheNativeColumns(): void
    {
        $provider = new LevelAutosaveProvider($this->database());

        $valid = ['title' => 'Special', 'rules' => [1, 8, 2]];
        $this->assertSame($valid, $provider->normalizePayload($valid, 2));
        $this->assertSame(['title' => '', 'rules' => []], $provider->normalizePayload(['title' => '', 'rules' => []], 2));

        foreach (
            [
            ['title' => 'Special'],
            ['title' => 'Special', 'rules' => [1], 'ordering' => 0],
            ['title' => 'Special', 'rules' => '1'],
            ['title' => 'Special', 'rules' => ['1']],
            ['title' => 'Special', 'rules' => [0]],
            ['title' => 'Special', 'rules' => [-1]],
            ['title' => 'Special', 'rules' => [2147483648]],
            ['title' => 'Special', 'rules' => [1.5]],
            ['title' => 'x' . str_repeat('x', 100), 'rules' => []],
            ['title' => 'Special', 'rules' => range(1, 101)],
            ] as $invalid
        ) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 2))->getErrorCode()
            );
        }

        // The v1 title-only draft shape is no longer accepted.
        $this->assertSame(
            'unsupported_schema_version',
            $this->failure(fn () => $provider->normalizePayload(['title' => 'Legacy'], 1))->getErrorCode()
        );
    }

    public function testLevelExistingRecordBehaviorIsUnchanged(): void
    {
        $row      = (object) ['id' => 7, 'title' => 'Special', 'ordering' => 0, 'rules' => '[1,8]'];
        $provider = new LevelAutosaveProvider($this->database(['row' => $row]));

        $this->assertTrue($provider->targetExists('7'));
        $this->assertSame('7', $provider->canonicalizeTargetId('7'));

        $editor = $this->createMock(User::class);
        $editor->method('authorise')->willReturn(true);
        $provider->authorize($editor, '7', AutosaveOperation::Read);
        $this->assertStringStartsWith('autosave:com_users.level:base-revision:v1:', $provider->getBaseRevision('7'));
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(
            \array_key_exists('row', $options) ? $options['row'] : (object) ['id' => 7, 'title' => 'Special', 'ordering' => 0, 'rules' => '[1,8]']
        );
        $db->method('loadResult')->willReturn($options['groupCount'] ?? 1);

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
