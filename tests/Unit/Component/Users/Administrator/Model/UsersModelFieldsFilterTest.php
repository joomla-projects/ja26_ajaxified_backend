<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_users
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Users\Administrator\Model;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Users\Administrator\Model\UsersModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Users model custom-field filtering integration.
 *
 * @since  __DEPLOY_VERSION__
 */
class UsersModelFieldsFilterTest extends UnitTestCase
{
    private \ReflectionProperty $pluginsProperty;

    private mixed $previousPlugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginsProperty = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $this->previousPlugins = $this->pluginsProperty->getValue();
        $this->pluginsProperty->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $this->pluginsProperty->setValue(null, $this->previousPlugins);

        parent::tearDown();
    }

    public function testPopulateStateSuppliesUserScopesAndLayoutContexts(): void
    {
        $componentsProperty = new \ReflectionProperty(ComponentHelper::class, 'components');
        $previousComponents = $componentsProperty->getValue();
        $previousApplication = Factory::$application;
        $component = new ComponentRecord(['enabled' => true]);
        $component->setParams(new Registry());
        $componentsProperty->setValue(null, ['com_users' => $component]);

        try {
            $model = $this->createPopulateModel();
            $fixture = $this->createApplication(
                [
                    'layout'   => 'modal',
                    'groups'   => base64_encode('[2,5]'),
                    'excluded' => base64_encode('[9,11]'),
                    'filter'   => ['state' => '1', 'customfield_7' => ['north']],
                ],
                ['com_users.users.modal.filter' => ['customfield_search' => 'term']]
            );
            Factory::$application = $fixture->application;
            $model->populateForTest();

            $this->assertSame(
                [
                    'com_users.user',
                    [],
                    '',
                    ['state' => '1', 'customfield_7' => ['north']],
                    ['customfield_search' => 'term'],
                ],
                $model->preparedArguments
            );
            $this->assertSame([2, 5], $model->getState('filter.groups'));
            $this->assertSame([9, 11], $model->getState('filter.excluded'));
            $this->assertSame('com_users.users.modal', $this->getContext($model));

            $defaultModel = $this->createPopulateModel();
            $fixture = $this->createApplication();
            Factory::$application = $fixture->application;
            $defaultModel->populateForTest();

            $this->assertSame('com_users.users.default', $this->getContext($defaultModel));
        } finally {
            Factory::$application = $previousApplication;
            $componentsProperty->setValue(null, $previousComponents);
        }
    }

    public function testCanonicalFiltersExtendNativeFiltersAndStoreIdentity(): void
    {
        $one      = $this->createPreparedModel(new PreparedFieldsFilter([], ['customfield_7' => ['north']], false));
        $other    = $this->createPreparedModel(new PreparedFieldsFilter([], ['customfield_7' => ['south']], false));
        $rejected = $this->createPreparedModel(new PreparedFieldsFilter([], ['customfield_7' => ['north']], true));
        $one->setState('filter.state', 1);

        $this->assertSame(
            ['state' => 1, 'customfield_7' => ['north']],
            $one->getActiveFilters()
        );
        $this->assertNotSame($one->storeIdForTest(), $other->storeIdForTest());
        $this->assertNotSame($one->storeIdForTest(), $rejected->storeIdForTest());
    }

    public function testCustomStoreIdentityProtectsUsersModelItemCache(): void
    {
        $model    = $this->createPreparedModel(new PreparedFieldsFilter([], ['customfield_7' => ['north']], false));
        $firstKey = $model->storeIdForTest('');
        (new \ReflectionProperty(ListModel::class, 'cache'))->setValue(
            $model,
            [$firstKey => [(object) ['id' => 99]]]
        );
        (new \ReflectionProperty(UsersModel::class, 'preparedFieldsFilter'))->setValue(
            $model,
            new PreparedFieldsFilter([], ['customfield_7' => ['south']], false)
        );
        $model->setState('filter.groups', []);

        $this->assertNotSame($firstKey, $model->storeIdForTest(''));
        $this->assertSame([], $model->getItems());
    }

    public function testFilterFormKeepsMfaRemovalAndInjectsCustomFields(): void
    {
        $form = new Form('users.test');
        $form->setCurrentUser($this->createStub(User::class));
        $form->load('<form><fields name="filter"><field name="state" type="text" /><field name="mfa" type="text" /></fields></form>');
        $model = new class ($form) extends UsersModel {
            public bool $injected = false;

            public function __construct(private readonly Form $testForm)
            {
            }

            protected function loadForm($name, $source = null, $options = [], $clear = false, $xpath = null)
            {
                return $this->testForm;
            }

            protected function addFieldsFiltersToForm(Form $form, bool $setValues = true): void
            {
                $this->injected = true;
                $form->setField(new \SimpleXMLElement('<field name="customfield_7" type="text" />'), 'filter');
            }
        };
        $this->initialiseModel($model);
        $result = $model->getFilterForm();

        $this->assertTrue($model->injected);
        $this->assertNotFalse($result->getField('state', 'filter'));
        $this->assertFalse($result->getField('mfa', 'filter'));
        $this->assertNotFalse($result->getField('customfield_7', 'filter'));
    }

    public function testQuerySuppliesTrustedItemIdAndAppliesCustomPredicate(): void
    {
        $model = new class () extends UsersModel {
            public string $itemIdExpression = '';

            public function __construct()
            {
            }

            public function queryForTest(): QueryInterface
            {
                return $this->getListQuery();
            }

            protected function applyPreparedFieldsFilters(QueryInterface $query, string $itemIdExpression): void
            {
                $this->itemIdExpression = $itemIdExpression;
                $query->where('custom-field-predicate');
            }
        };
        $this->initialiseModel($model);
        $model->setState('filter.state', 1);
        $db    = $this->createStub(DatabaseInterface::class);
        $query = $this->getQueryStub($db);
        $db->method('createQuery')->willReturn($query);
        $db->method('escape')->willReturnArgument(0);
        $db->method('quoteName')->willReturnCallback(
            static fn (string $name): string => '`' . str_replace('.', '`.`', $name) . '`'
        );
        $model->setDatabase($db);

        $model->queryForTest();

        $where = (new \ReflectionProperty($query, 'where'))->getValue($query);
        $this->assertSame('`a`.`id`', $model->itemIdExpression);
        $this->assertStringContainsString('`a`.`block` = :state', (string) $where);
        $this->assertStringContainsString('custom-field-predicate', (string) $where);
    }

    private function createPopulateModel(): object
    {
        $model = new class () extends UsersModel {
            public array $preparedArguments = [];

            public function __construct()
            {
            }

            public function populateForTest(): void
            {
                $this->populateState();
            }

            protected function prepareFieldsFilter(
                CMSWebApplicationInterface $app,
                string $context,
                array $categoryIds,
                string $language,
                array $submitted,
                array $previousFilters
            ): void {
                $this->preparedArguments = [$context, $categoryIds, $language, $submitted, $previousFilters];
            }
        };
        $this->initialiseModel($model);

        return $model;
    }

    private function createPreparedModel(PreparedFieldsFilter $prepared): object
    {
        $model = new class () extends UsersModel {
            public function __construct()
            {
            }

            public function storeIdForTest(string $id = 'test'): string
            {
                return $this->getStoreId($id);
            }
        };
        $this->initialiseModel($model);
        (new \ReflectionProperty(UsersModel::class, 'preparedFieldsFilter'))->setValue($model, $prepared);

        return $model;
    }

    private function initialiseModel(UsersModel $model): void
    {
        (new \ReflectionProperty(ListModel::class, 'context'))->setValue($model, 'com_users.users');
        (new \ReflectionProperty(ListModel::class, 'filter_fields'))->setValue($model, ['state']);
        (new \ReflectionProperty($model, '__state_set'))->setValue($model, true);
    }

    private function getContext(UsersModel $model): string
    {
        return (new \ReflectionProperty(ListModel::class, 'context'))->getValue($model);
    }

    private function createApplication(array $input = [], array $state = []): object
    {
        $fixture        = new \stdClass();
        $fixture->input = new Input($input);
        $fixture->state = $state;
        $app            = $this->createMock(CMSWebApplicationInterface::class);
        $app->method('getInput')->willReturn($fixture->input);
        $app->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $default
        );
        $app->method('getUserState')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $fixture->state[$key] ?? $default
        );
        $app->method('setUserState')->willReturnCallback(
            static function (string $key, mixed $value) use ($fixture): mixed {
                $previous             = $fixture->state[$key] ?? null;
                $fixture->state[$key] = $value;

                return $previous;
            }
        );
        $app->method('getUserStateFromRequest')->willReturnCallback(
            static function (
                string $key,
                string $request,
                mixed $default = null,
                string $type = 'none'
            ) use ($fixture): mixed {
                if (!$fixture->input->exists($request)) {
                    return $fixture->state[$key] ?? $default;
                }

                $value                = $fixture->input->get($request, $default, $type);
                $fixture->state[$key] = $value;

                return $value;
            }
        );
        $fixture->application = $app;

        return $fixture;
    }
}
