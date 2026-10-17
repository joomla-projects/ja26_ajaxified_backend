<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Autosave\Administrator;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Categories\Administrator\Autosave\CategoryAutosaveProvider;
use Joomla\Component\Categories\Administrator\Model\CategoryModel;
use Joomla\Component\Languages\Administrator\Autosave\OverrideAutosaveProvider;
use Joomla\Component\Languages\Administrator\Model\OverrideModel;
use Joomla\Tests\Unit\UnitTestCase;

/** Prepared native forms and canonical data, with no business persistence during drafts. */
class NativeDraftProvidersTest extends UnitTestCase
{
    private function user(array $denied = [], array $categories = []): User
    {
        $user        = $this->createMock(User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturnCallback(
            static fn ($action, $asset) => !\in_array($action . ':' . $asset, $denied, true)
        );
        $user->method('getAuthorisedCategories')->willReturn($categories);
        return $user;
    }

    private function form(string $component, string $name): Form
    {
        $form = new Form($component . '.' . $name, ['control' => 'jform']);
        $form->setCurrentUser($this->user());
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/' . $component . '/forms/' . $name . '.xml');
        $form->bind(['title' => 'session error', 'key' => 'SESSION_ERROR', 'extension' => 'com_content']);
        return $form;
    }

    private function category(): object
    {
        return (object) ['id' => 42, 'extension' => 'com_content', 'title' => 'Canonical',
            'note'            => '', 'description' => 'Body', 'version_note' => '', 'metadesc' => '', 'metakey' => '',
            'parent_id'       => 1, 'created_user_id' => 7, 'checked_out' => 0, 'modified_time' => '2026-10-12 00:00:00'];
    }

    private function override(): object
    {
        return (object) ['key' => 'COM_EXAMPLE', 'override' => 'Canonical', 'both' => false,
            'client'           => 'site', 'language' => 'en-GB',
            'draftRevision'    => ['site' => 'Canonical', 'administrator' => 'Different']];
    }

    public function testCategoryUsesCanonicalSelectedFieldsAndIgnoresTreeBookkeeping(): void
    {
        $provider = new CategoryAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $item     = $this->category();
        $form     = $this->form('com_categories', 'category');
        $first    = $provider->describe($form, $item, $this->user());
        $this->assertSame(['title', 'note', 'description', 'version_note', 'metadesc', 'metakey', 'parent_id'], array_keys($first['fields']));
        $this->assertSame('Canonical', $first['values']['title']);
        $this->assertSame('session error', $form->getValue('title'));
        $this->assertSame(['extension' => 'com_content'], $first['scope']);
        $this->assertSame(['parent_id' => ''], DraftProjection::normalize(['parent_id' => ''], $first['fields']));
        foreach (['lft', 'rgt', 'level', 'path', 'hits', 'checked_out_time'] as $key) {
            $item->$key = 'bookkeeping changed';
            $this->assertSame($first['revision'], $provider->describe($form, $item, $this->user())['revision']);
        }
        foreach (['title', 'description', 'parent_id', 'modified_time'] as $key) {
            $changed       = clone $item;
            $changed->$key = 'changed';
            $this->assertNotSame($first['revision'], $provider->describe($form, $changed, $this->user())['revision']);
        }
    }

    public function testCategoryReadSeedsFullOwningScopeAndNeverSaves(): void
    {
        $item            = $this->category();
        $item->extension = 'com_content.section.subsection';
        $states          = [];
        $model           = $this->createMock(CategoryModel::class);
        $model->expects($this->never())->method('save');
        $model->expects($this->never())->method('rebuild');
        $model->method('setState')->willReturnCallback(static function ($key, $value) use (&$states) {
            $states[$key] = $value;
        });
        $model->method('getItem')->with(42)->willReturn($item);
        $model->method('getForm')->with($this->isType('array'), false)->willReturn($this->form('com_categories', 'category'));
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->with('Category', 'Administrator', ['ignore_request' => true])->willReturn($model);
        $current = (new CategoryAutosaveProvider($factory))->read(
            ['context' => 'com_categories.category', 'target_id' => '42', 'scope' => ['extension' => $item->extension]],
            $this->user()
        );
        $this->assertSame(['extension' => $item->extension], $current['scope']);
        $this->assertSame('com_content', $states['category.component']);
        $this->assertSame('section', $states['category.section']);
        $this->assertSame(42, $states['category.id']);
    }

    /** @dataProvider deniedCategoryCases */
    public function testCategoryRechecksNativeAuthorityAndCheckout(array $denied, array $changes, string $code): void
    {
        $item = (object) array_replace((array) $this->category(), $changes);
        $this->expectException(AutosaveException::class);
        $this->expectExceptionMessageMatches('/./');
        try {
            (new CategoryAutosaveProvider($this->createMock(MVCFactoryInterface::class)))->describe(
                $this->form('com_categories', 'category'),
                $item,
                $this->user($denied)
            );
        } catch (AutosaveException $error) {
            $this->assertSame($code, $error->getErrorCode());
            throw $error;
        }
    }

    public static function deniedCategoryCases(): array
    {
        return [
            [['core.manage:com_content'], [], 'forbidden'],
            [['core.edit:com_content.category.42', 'core.edit.own:com_content.category.42'], [], 'forbidden'],
            [['core.edit:com_content.category.42'], ['created_user_id' => 8], 'forbidden'],
            [[], ['checked_out'                                        => 8], 'checkout_conflict'],
            [[], ['extension'                                          => '../com_content'], 'scope_conflict'],
            [[], ['draftExtension'                                     => 'com_banners'], 'scope_conflict'],
        ];
    }

    public function testCategoryCreationMatchesNativeComponentOrAuthorisedCategoryFallback(): void
    {
        $item     = $this->category();
        $item->id = 0;
        $provider = new CategoryAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $current  = $provider->describe($this->form('com_categories', 'category'), $item, $this->user(['core.create:com_content'], [2]));
        $this->assertNull($current['target']);
        $this->assertNull($current['revision']);
        $this->expectException(AutosaveException::class);
        $provider->describe($this->form('com_categories', 'category'), $item, $this->user(['core.create:com_content']));
    }

    public function testOverrideKeepsSourceIdentityAndKeyLevelNativeRevision(): void
    {
        $provider = new OverrideAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $item     = $this->override();
        $form     = $this->form('com_languages', 'override');
        $first    = $provider->describe($form, $item, $this->user());
        $this->assertSame(['key', 'override', 'both'], array_keys($first['fields']));
        $this->assertSame('COM_EXAMPLE', $first['target']);
        $this->assertSame(['client' => 'site', 'language' => 'en-GB'], $first['scope']);
        $this->assertSame('checkbox', $first['fields']['both']['control']);
        $form->bind(['key' => 'COM_RENAMED', 'override' => 'draft', 'both' => true]);
        $this->assertSame($first['revision'], $provider->describe($form, $item, $this->user())['revision']);
        $item->unrelatedFileEntry = 'changed';
        $this->assertSame($first['revision'], $provider->describe($form, $item, $this->user())['revision']);
        $item->draftRevision['administrator'] = 'Another different value';
        $this->assertNotSame($first['revision'], $provider->describe($form, $item, $this->user())['revision']);
        $item->draftRevision['administrator'] = null;
        $this->assertNotSame($first['revision'], $provider->describe($form, $item, $this->user())['revision']);
    }

    public function testOverrideReadUsesBoundModelStateWithoutBorrowingCandidateIdentity(): void
    {
        $states = [];
        $model  = $this->createMock(OverrideModel::class);
        $model->expects($this->never())->method('save');
        $model->method('setState')->willReturnCallback(static function ($key, $value) use (&$states) {
            $states[$key] = $value;
        });
        $model->expects($this->once())->method('getItem')->with('COM_EXAMPLE')->willReturn($this->override());
        $model->method('getForm')->with($this->isType('array'), false)->willReturn($this->form('com_languages', 'override'));
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->with('Override', 'Administrator', ['ignore_request' => true])->willReturn($model);
        $descriptor = (new OverrideAutosaveProvider($factory))->read(
            ['context' => 'com_languages.override', 'target_id' => 'COM_EXAMPLE', 'scope' => ['client' => 'site', 'language' => 'en-GB']],
            $this->user(),
            ['key' => 'COM_RENAMED', 'both' => '1']
        );
        $this->assertSame('COM_EXAMPLE', $descriptor['target']);
        $this->assertSame(['filter.client' => 'site', 'filter.language' => 'en-GB'], $states);
        $this->assertSame('0', $descriptor['values']['both']);
    }

    public function testOverrideCreationAndCheckboxHaveNativeSafeRepresentation(): void
    {
        $item       = $this->override();
        $item->key  = '';
        $provider   = new OverrideAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $descriptor = $provider->describe($this->form('com_languages', 'override'), $item, $this->user());
        $this->assertNull($descriptor['target']);
        $this->assertNull($descriptor['revision']);
        $this->assertSame(['key' => '', 'both' => '1'], DraftProjection::normalize(['key' => '', 'both' => true], $descriptor['fields']));
        $this->assertSame(['both' => '0'], DraftProjection::normalize(['both' => false], $descriptor['fields']));
        $this->expectException(AutosaveException::class);
        DraftProjection::normalize(['both' => 'false'], $descriptor['fields']);
    }

    /** @dataProvider deniedOverrideCases */
    public function testOverrideDeniesWrongScopeIdentityAndNativePermission(array $changes, array $denied, string $code): void
    {
        $item = (object) array_replace((array) $this->override(), $changes);
        $this->expectException(AutosaveException::class);
        try {
            (new OverrideAutosaveProvider($this->createMock(MVCFactoryInterface::class)))->describe(
                $this->form('com_languages', 'override'),
                $item,
                $this->user($denied)
            );
        } catch (AutosaveException $error) {
            $this->assertSame($code, $error->getErrorCode());
            throw $error;
        }
    }


    public function testNativeOverrideReadHonoursExplicitEmptyAndZeroKeysWithoutRequestFallback(): void
    {
        $previous = \Joomla\CMS\Factory::$application;
        $input    = $this->createMock(\Joomla\Input\Input::class);
        $input->expects($this->never())->method('get');
        $application = $this->createMock(\Joomla\CMS\Application\CMSApplication::class);
        $application->method('getInput')->willReturn($input);
        \Joomla\CMS\Factory::$application = $application;
        try {
            $model = new OverrideModel(['ignore_request' => true], $this->createMock(MVCFactoryInterface::class));
            $model->setState('filter.client', 'site');
            $model->setState('filter.language', 'phase3-unused-fixture');
            foreach (['', '0'] as $key) {
                $item = $model->getItem($key);
                $this->assertSame($key, $item->key);
                $this->assertFalse($item->both);
                $this->assertSame(['site' => null, 'administrator' => null], $item->draftRevision);
            }
        } finally {
            \Joomla\CMS\Factory::$application = $previous;
        }
    }

    public function testMissingPrimaryOverrideKeepsItsSourceIdentityAndRecoverableRevision(): void
    {
        $provider            = new OverrideAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $item                = $this->override();
        $item->override      = '';
        $item->both          = false;
        $item->draftRevision = ['site' => null, 'administrator' => 'Old value'];
        $descriptor          = $provider->describe($this->form('com_languages', 'override'), $item, $this->user());
        $this->assertSame('COM_EXAMPLE', $descriptor['target']);
        $this->assertSame('', $descriptor['values']['override']);
        $this->assertNotNull($descriptor['revision']);
        $item->draftRevision['site'] = 'Reappeared';
        $this->assertNotSame($descriptor['revision'], $provider->describe(
            $this->form('com_languages', 'override'),
            $item,
            $this->user()
        )['revision']);
    }

    public static function deniedOverrideCases(): array
    {
        return [
            [[], ['core.manage:com_languages'], 'forbidden'],
            [[], ['core.edit:com_languages'], 'forbidden'],
            [['key' => ''], ['core.create:com_languages'], 'forbidden'],
            [['key' => 'lowercase_key'], [], 'invalid_target'],
            [['client' => 'api'], [], 'scope_conflict'],
            [['language' => '../en-GB'], [], 'scope_conflict'],
            [['language' => 'invalid-fixture-language'], [], 'scope_conflict'],
        ];
    }
}
