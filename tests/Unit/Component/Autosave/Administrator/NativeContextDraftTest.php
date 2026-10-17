<?php

/**
 * @package Joomla.UnitTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Autosave\Administrator;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Language;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

class NativeContextDraftTest extends UnitTestCase
{
    private mixed $previousApplication;
    private mixed $previousLanguage;
    private mixed $previousDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Factory::$application;
        $this->previousLanguage    = Factory::$language;
        $this->previousDatabase    = Factory::$database;
        $language                  = new Language('en-GB', false);
        $application               = $this->createMock(CMSApplication::class);
        $application->method('getLanguage')->willReturn($language);
        $application->method('getInput')->willReturn(new Input());
        $application->method('getClientId')->willReturn(1);
        $application->method('getName')->willReturn('administrator');
        Factory::$application = $application;
        Factory::$language    = $language;
        $db                   = $this->createMock(\Joomla\Database\DatabaseDriver::class);
        $db->method('getIterator')->willReturn($this->createMock(\Joomla\Database\DatabaseIterator::class));
        $query = $this->getQueryStub($db);
        $db->method('createQuery')->willReturn($query);
        $db->method('setQuery')->willReturnSelf();
        $db->method('quoteName')->willReturnCallback(static fn ($name) => \is_array($name) ? $name : (string) $name);
        $db->method('loadObject')->willReturn((object) ['extension_id' => 99, 'element' => 'mod_draft_fixture', 'enabled' => 1, 'client_id' => 0]);
        Factory::$database = $db;
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        Factory::$language    = $this->previousLanguage;
        Factory::$database    = $this->previousDatabase;
        parent::tearDown();
    }

    private function user(array $denied = []): User
    {
        $user        = $this->createMock(User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturnCallback(static fn ($action, $asset = null) => !\in_array($action . ':' . ($asset ?? 'root'), $denied, true));
        $user->method('getAuthorisedCategories')->willReturn([]);
        return $user;
    }

    private function item(string $component, string $entity): object
    {
        return (object) ['id' => 42, 'lang_id' => 42, 'filter_id' => 42, 'title' => 'Canonical', 'name' => 'Canonical',
            'subject'         => 'Canonical', 'old_url' => 'https://example.org/old', 'new_url' => 'https://example.org/new',
            'description'     => 'Canonical body', 'misc' => 'Canonical body', 'body' => 'Canonical body',
            'catid'           => 3, 'cid' => 0, 'user_id' => 7, 'tour_id' => 12, 'workflow_id' => 12,
            'context'         => 'com_content.article', 'type' => $component === 'com_fields' ? 'text' : 'component',
            'extension'       => 'com_content.article', 'link' => 'index.php?option=com_content&view=article', 'menutype' => 'draft-test-menu',
            'client_id'       => 0, 'module' => 'mod_draft_fixture', 'template' => 'draft_fixture', 'lang_code' => 'en-GB',
            'created_by'      => 7, 'created_user_id' => 7, 'version' => 2, 'checked_out' => 0, 'parent_id' => 1,
            'required'        => 0, 'only_use_in_subform' => 0, 'rules' => [3, 1], 'data' => '3,1',
            'modified'        => '2026-10-13 00:00:00', 'modified_time' => '2026-10-13 00:00:00',
            'params'          => ['required' => '1', 'requiredvalue' => 'Canonical condition', 'draft_public' => 'Public canonical'],
            'fieldparams'     => ['draft_public' => 'Public canonical'],
            'xml'             => new \SimpleXMLElement('<extension><parent>parent-template</parent></extension>')];
    }

    private function fixture(string $component, string $entity): array
    {
        $providerClass = 'Joomla\\Component\\' . ucfirst(substr($component, 4)) . '\\Administrator\\Autosave\\' . $entity . 'AutosaveProvider';
        $modelClass    = 'Joomla\\Component\\' . ucfirst(substr($component, 4)) . '\\Administrator\\Model\\' . $entity . 'Model';
        $item          = $this->item($component, $entity);
        $model         = $this->createMock($modelClass);
        $model->expects($this->never())->method('save');
        $model->expects($this->never())->method('validate');
        $model->method('getItem')->willReturn($item);
        $form = new Form($component . '.' . strtolower($entity), ['control' => 'jform']);
        $form->setCurrentUser($this->user());
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/' . $component . '/forms/' . strtolower($entity) . '.xml');
        $form->bind(['title' => 'Native error value', 'name' => 'Native error value', 'subject' => 'Native error value', 'catid' => 3, 'type' => $item->type]);
        $model->method('getForm')->willReturn($form);
        $factory = $this->createMock(MVCFactoryInterface::class);
        if ($component === 'com_menus' && $entity === 'Item') {
            $table            = $this->createMock(\Joomla\CMS\Table\MenuType::class);
            $table->id        = 12;
            $table->client_id = 0;
            $table->method('load')->with(['menutype' => 'draft-test-menu'])->willReturn(true);
            $model->method('getTable')->willReturn($table);
        }
        if (($component === 'com_workflow' && $entity !== 'Workflow') || $entity === 'Step') {
            $parentEntity = $entity === 'Step' ? 'Tour' : 'Workflow';
            $parentClass  = 'Joomla\\Component\\' . ucfirst(substr($component, 4)) . '\\Administrator\\Model\\' . $parentEntity . 'Model';
            $parent       = $this->createMock($parentClass);
            $parent->expects($this->never())->method('save');
            $parent->method('getItem')->with(12)->willReturn((object) ['id' => 12, 'extension' => 'com_content.article']);
            $factory->method('createModel')->willReturnCallback(static fn ($name) => $name === $parentEntity ? $parent : $model);
        } else {
            $factory->method('createModel')->willReturn($model);
        }
        return [new $providerClass($factory), $form, $item, $model];
    }

    /** @dataProvider contexts */
    public function testCanonicalProjectionAndMeaningfulRevision(string $component, string $entity, string $first, string $editAsset): void
    {
        [$provider, $form, $item] = $this->fixture($component, $entity);
        $descriptor               = $provider->describe($form, $item, $this->user());
        $this->assertSame($component . '.' . strtolower($entity), $descriptor['context']);
        $this->assertSame('42', $descriptor['target']);
        $this->assertSame((string) $item->$first, $descriptor['values'][$first]);
        $this->assertArrayHasKey($first, $descriptor['fields']);
        foreach (['lft', 'rgt', 'hits', 'checked_out_time', 'clicks', 'impmade', 'map_count'] as $name) {
            $item->$name = 'bookkeeping only';
        }
        $this->assertSame($descriptor['revision'], $provider->describe($form, $item, $this->user())['revision']);
        $item->$first = 'Changed protected canonical value';
        $this->assertNotSame($descriptor['revision'], $provider->describe($form, $item, $this->user())['revision']);
    }

    /** @dataProvider contexts */
    public function testBoundNativeReadNeverPerformsCanonicalWrites(string $component, string $entity, string $first, string $editAsset): void
    {
        [$provider, $form, $item, $model] = $this->fixture($component, $entity);
        $descriptor                       = $provider->describe($form, $item, $this->user());
        $model->expects($this->atLeastOnce())->method('setState')->withConsecutive([strtolower($entity) . '.id', 42]);
        $current = $provider->read(['context' => $descriptor['context'], 'target_id' => '42', 'scope' => $descriptor['scope']], $this->user());
        $this->assertSame($descriptor['scope'], $current['scope']);
        $this->assertSame($descriptor['values'][$first], $current['values'][$first]);
        $this->assertSame($descriptor['revision'], $current['revision']);
    }

    /** @dataProvider contexts */
    public function testNativeDenialAndCheckoutRemainAuthoritative(string $component, string $entity, string $first, string $editAsset): void
    {
        [$provider, $form, $item] = $this->fixture($component, $entity);
        try {
            $provider->describe($form, $item, $this->user(['core.edit:' . $editAsset, 'core.edit.own:' . $editAsset, 'core.edit.own:' . $component, 'core.edit.own:' . explode('.', $editAsset)[0]]));
            $this->fail('A denied native edit must not admit drafts');
        } catch (AutosaveException $exception) {
            $this->assertSame('forbidden', $exception->getErrorCode());
        }
        $item->checked_out = 99;
        try {
            $provider->describe($form, $item, $this->user());
            $this->fail('A checked-out record must not admit drafts');
        } catch (AutosaveException $exception) {
            $this->assertSame('checkout_conflict', $exception->getErrorCode());
        }
    }

    /** @dataProvider contexts */
    public function testCreationAndForeignScope(string $component, string $entity, string $first, string $editAsset): void
    {
        [$provider, $form, $item] = $this->fixture($component, $entity);
        $descriptor               = $provider->describe($form, $item, $this->user());
        try {
            $provider->read(['context' => $descriptor['context'], 'target_id' => '42', 'scope' => ['foreign' => 'scope']], $this->user());
            $this->fail('A foreign scope must not be read');
        } catch (AutosaveException $exception) {
            $this->assertSame('scope_conflict', $exception->getErrorCode());
        }
        $item->id = $item->lang_id = $item->filter_id = 0;
        if ($component === 'com_templates') {
            $this->expectException(AutosaveException::class);
            $provider->describe($form, $item, $this->user());
        } else {
            $created = $provider->describe($form, $item, $this->user());
            $this->assertNull($created['target']);
            $this->assertNull($created['revision']);
        }
    }

    public function testDeclaredGroupProjectionDoesNotCaptureSecretsOrUnknownWidgets(): void
    {
        $form = new Form('native.dynamic', ['control' => 'jform']);
        $form->setCurrentUser($this->user());
        $form->load('<form><fields name="params"><fieldset><field name="public" type="text" autosave="true"/>'
            . '<field name="secret" type="text" sensitive="true" autosave="true"/>'
            . '<field name="password" type="password" autosave="true"/>'
            . '<field name="unknown" type="text"/></fieldset></fields></form>');
        $keys   = DraftProjection::groupKeys($form, 'params');
        $fields = DraftProjection::describe($form, $keys);
        $this->assertSame(['params.public'], array_keys($fields));
        $this->assertNotContains('params.unknown', $keys);
        $descriptor = DraftProjection::descriptor($form, (object) ['params' => ['public' => 'Public', 'secret' => 'Sensitive']], 'com_example.item', '1', [], $keys);
        $this->assertSame(['params.public' => 'Public'], $descriptor['values']);
        $this->assertStringNotContainsString('Sensitive', json_encode($descriptor));
    }

    public function testNativeMenuAliasIsProtectedWithoutEnablingUnknownOrReadonlyWidgets(): void
    {
        [$provider, $form, $item] = $this->fixture('com_menus', 'Item');
        $item->alias              = 'canonical-alias';
        $form->setValue('alias', null, 'draft-alias');
        $descriptor = $provider->describe($form, $item, $this->user());
        $this->assertSame('alias', $form->getFieldAttribute('alias', 'type'));
        $this->assertSame('text', $descriptor['fields']['alias']['control']);
        $this->assertSame('jform[alias]', $descriptor['fields']['alias']['name']);
        $this->assertSame('canonical-alias', $descriptor['values']['alias']);
        $this->assertSame('draft-alias', $form->getField('alias')->value);
        $this->assertSame(['alias' => 'draft-alias'], DraftProjection::normalize(['alias' => 'draft-alias'], $descriptor['fields']));
        foreach ([['readonly', 'true'], ['sensitive', 'true'], ['type', 'unknown-widget']] as [$attribute, $value]) {
            $form->setFieldAttribute('alias', $attribute, $value);
            $excluded = $provider->describe($form, $item, $this->user());
            $this->assertArrayNotHasKey('alias', $excluded['fields']);
            $this->assertContains('alias', $excluded['excluded']);
            $form->setFieldAttribute('alias', $attribute, $attribute === 'type' ? 'alias' : 'false');
        }
    }


    public function testNativeGeneratedSelectionsHaveSemanticRevisions(): void
    {
        foreach ([['com_users', 'Level'], ['com_finder', 'Filter']] as [$component, $entity]) {
            [$provider, $form, $item] = $this->fixture($component, $entity);
            $before                   = $provider->describe($form, $item, $this->user());
            if ($entity === 'Level') {
                $item->rules = [1, 3, 3];
            } else {
                $item->data = '1,3,3';
            }
            $this->assertSame($before['revision'], $provider->describe($form, $item, $this->user())['revision']);
        }
    }

    /** @dataProvider contexts */
    public function testMalformedIdentityCannotReachTheNativeModel(string $component, string $entity, string $first, string $editAsset): void
    {
        [$provider] = $this->fixture($component, $entity);
        foreach (['0', '-1', '01', ' 1', '1.0', 'foreign', '4294967296'] as $target) {
            try {
                $provider->read(['context' => $provider->getContext(), 'target_id' => $target, 'scope' => []], $this->user());
                $this->fail('An invalid canonical identity was accepted');
            } catch (AutosaveException $exception) {
                $this->assertSame('invalid_target', $exception->getErrorCode());
            }
        }
    }

    /** @dataProvider nativeParameterForms */
    public function testRealNativeParameterDeclarationsProtectPublicPrimitiveSiblings(string $path, string $group, string $protected, string $excluded): void
    {
        $form = new Form('native.parameters', ['control' => 'jform']);
        $form->setCurrentUser($this->user());
        $this->assertTrue($form->loadFile(JPATH_ROOT . '/' . $path, true, str_ends_with($path, 'templateDetails.xml') ? '//config' : false));
        $keys = DraftProjection::groupKeys($form, $group);
        $this->assertContains($group . '.' . $protected, $keys);
        $this->assertNotContains($group . '.' . $excluded, array_keys(DraftProjection::describe($form, $keys)));
    }

    public static function nativeParameterForms(): array
    {
        return [
            ['plugins/fields/text/params/text.xml', 'fieldparams', 'maxlength', 'secret'],
            ['plugins/fields/sql/params/sql.xml', 'fieldparams', 'multiple', 'query'],
            ['plugins/fields/list/params/list.xml', 'fieldparams', 'multiple', 'options'],
            ['administrator/components/com_modules/forms/advanced.xml', 'params', 'module_tag', 'private_key'],
            ['templates/cassiopeia/templateDetails.xml', 'params', 'brand', 'private_key'],
        ];
    }

    public function testNativeWorkflowComponentRoutesKeepTheCanonicalSectionAndRejectForeignSections(): void
    {
        foreach (['Workflow', 'Stage', 'Transition'] as $entity) {
            [$provider, $form, $item] = $this->fixture('com_workflow', $entity);
            $item->draftExtension     = 'com_content';
            $descriptor               = $provider->describe($form, $item, $this->user());
            $this->assertSame('com_content.article', $descriptor['scope']['extension']);
            $provider->read(['context' => $descriptor['context'], 'target_id' => '42', 'scope' => $descriptor['scope']], $this->user());
            $item->draftExtension = 'com_content.category';
            try {
                $provider->describe($form, $item, $this->user());
                $this->fail('An explicit foreign native section was accepted');
            } catch (AutosaveException $exception) {
                $this->assertSame('scope_conflict', $exception->getErrorCode());
            }
        }
    }

    public function testIncompleteNewMenuItemUsesNativeMenuZeroAuthorityWithoutInventingAMenu(): void
    {
        [$provider, $form, $item] = $this->fixture('com_menus', 'Item');
        $item->id                 = 0;
        $item->menutype           = '';
        $item->link               = '';
        $descriptor               = $provider->describe($form, $item, $this->user());
        $this->assertNull($descriptor['target']);
        $this->assertSame('', $descriptor['scope']['menutype']);
        $this->assertSame('0', $descriptor['scope']['menu_id']);
        $this->assertArrayHasKey('title', $descriptor['fields']);
        $read = $provider->read(['context' => $descriptor['context'], 'target_id' => null, 'scope' => $descriptor['scope']], $this->user());
        $this->assertSame($descriptor['scope'], $read['scope']);
        try {
            $provider->describe($form, $item, $this->user(['core.create:com_menus.menu.0']));
            $this->fail('Native menu.0 create denial was ignored');
        } catch (AutosaveException $exception) {
            $this->assertSame('forbidden', $exception->getErrorCode());
        }
        $item->id = 42;
        $this->expectException(AutosaveException::class);
        $provider->describe($form, $item, $this->user());
    }

    public static function contexts(): array
    {
        return [
            ['com_banners', 'Banner', 'name', 'com_banners.category.3'],
            ['com_banners', 'Client', 'name', 'com_banners'],
            ['com_contact', 'Contact', 'name', 'com_contact.category.3'],
            ['com_fields', 'Field', 'title', 'com_content.field.42'],
            ['com_fields', 'Group', 'title', 'com_content.fieldgroup.42'],
            ['com_finder', 'Filter', 'title', 'com_finder'],
            ['com_guidedtours', 'Tour', 'title', 'com_guidedtours'],
            ['com_guidedtours', 'Step', 'title', 'com_guidedtours'],
            ['com_languages', 'Language', 'title', 'com_languages'],
            ['com_menus', 'Item', 'title', 'com_menus.menu.12'],
            ['com_menus', 'Menu', 'title', 'com_menus'],
            ['com_modules', 'Module', 'title', 'com_modules.module.42'],
            ['com_newsfeeds', 'Newsfeed', 'name', 'com_newsfeeds.category.3'],
            ['com_redirect', 'Link', 'old_url', 'com_redirect'],
            ['com_tags', 'Tag', 'title', 'com_tags'],
            ['com_templates', 'Style', 'title', 'com_templates'],
            ['com_users', 'Group', 'title', 'com_users'],
            ['com_users', 'Level', 'title', 'com_users'],
            ['com_users', 'Note', 'subject', 'com_users'],
            ['com_workflow', 'Workflow', 'title', 'com_content.workflow.42'],
            ['com_workflow', 'Stage', 'title', 'com_content.stage.42'],
            ['com_workflow', 'Transition', 'title', 'com_content.transition.42'],
        ];
    }
}
