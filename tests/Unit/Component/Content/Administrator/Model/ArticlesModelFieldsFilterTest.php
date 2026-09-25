<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Content\Administrator\Model;

use Joomla\Component\Content\Administrator\Model\ArticlesModel;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Fields\Administrator\Model\FieldsModel;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Categories\CategoryInterface;
use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Articles model custom-field filtering integration.
 *
 * @since  __DEPLOY_VERSION__
 */
class ArticlesModelFieldsFilterTest extends UnitTestCase
{
    public function testCanonicalCustomFieldsMergeWithNativeActiveFilters(): void
    {
        $model = $this->createModel(new PreparedFieldsFilter([], ['customfield_7' => ['0', '01']], false));
        $model->setState('filter.published', 1);

        $active = $model->getActiveFilters();

        $this->assertSame(1, $active['published']);
        $this->assertSame(['0', '01'], $active['customfield_7']);
    }

    public function testStoreIdentityChangesWithCanonicalSelectionAndRejection(): void
    {
        $one      = $this->createModel(new PreparedFieldsFilter([], ['customfield_7' => ['01']], false));
        $zeroPad  = $this->createModel(new PreparedFieldsFilter([], ['customfield_7' => ['001']], false));
        $rejected = $this->createModel(new PreparedFieldsFilter([], ['customfield_7' => ['01']], true));

        $this->assertNotSame($this->getStoreId($one), $this->getStoreId($zeroPad));
        $this->assertNotSame($this->getStoreId($one), $this->getStoreId($rejected));
        $this->assertNotSame($this->getStoreId($zeroPad), $this->getStoreId($rejected));
    }

    public function testExplicitClearRemovesBothRememberedRepresentations(): void
    {
        $model = $this->createModel(new PreparedFieldsFilter([], [], false));
        $model->setCurrentUser($this->createStub(User::class));
        $model->setState('filter.category_id', []);

        $fieldsModel = $this->createMock(FieldsModel::class);
        $fieldsModel->method('getItems')->willReturn([]);
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($fieldsModel);
        $service = new FieldsFilterService($factory, new Dispatcher(), $this->createStub(DatabaseInterface::class));
        (new \ReflectionProperty($model, 'fieldsFilterService'))->setValue($model, $service);

        $app = new class () {
            public array $state = [
                'com_content.articles.filter' => ['published' => '1', 'customfield_search' => 'term'],
                'com_content.articles' => null,
                'com_content.articles.limitstart' => 40,
            ];
            public object $input;

            public function __construct()
            {
                $this->state['com_content.articles'] = (object) [
                    'filter' => ['published' => '1', 'customfield_7' => ['01'], 'customfield_search' => 'term'],
                ];
                $this->input = new class () {
                    public array $values = [];

                    public function set(string $name, mixed $value): void
                    {
                        $this->values[$name] = $value;
                    }
                };
            }

            public function getUserState(string $key, mixed $default = null): mixed
            {
                return $this->state[$key] ?? $default;
            }

            public function setUserState(string $key, mixed $value): void
            {
                $this->state[$key] = $value;
            }

            public function getUserStateFromRequest(
                string $key,
                string $request,
                mixed $default = null,
                string $type = 'none'
            ): mixed {
                if (!array_key_exists($request, $this->input->values)) {
                    return $this->getUserState($key, $default);
                }

                $value = $this->input->values[$request];
                $this->setUserState($key, $value);

                return $value;
            }

            public function getInput(): object
            {
                return $this->input;
            }
        };

        $pluginsProperty = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $priorPlugins    = $pluginsProperty->getValue();
        $componentsProperty = new \ReflectionProperty(ComponentHelper::class, 'components');
        $priorComponents = $componentsProperty->getValue();
        $component = new ComponentRecord(['enabled' => true]);
        $component->setParams(new Registry(['custom_fields_enable' => 1]));
        $componentsProperty->setValue(null, ['com_content' => $component]);
        $pluginsProperty->setValue(null, []);

        try {
            (new \ReflectionMethod($model, 'prepareCustomFieldFilters'))->invoke(
                $model,
                $app,
                [],
                ['published' => '1', 'customfield_7' => ['01'], 'customfield_search' => 'term']
            );

            $this->assertSame(
                ['published' => '1', 'customfield_search' => 'term'],
                $app->state['com_content.articles.filter']
            );
            $this->assertSame(
                ['published' => '1', 'customfield_search' => 'term'],
                $app->state['com_content.articles']->filter
            );
            $this->assertSame([], $model->getState('filter.customfield_7', []));
            $this->assertSame(0, $app->input->values['limitstart']);
            $this->assertSame(0, $app->state['com_content.articles.limitstart']);
            $this->assertSame(0, $model->getState('list.start'));

            unset($app->input->values['limitstart']);

            $this->assertSame(
                0,
                $app->getUserStateFromRequest('com_content.articles.limitstart', 'limitstart', 0, 'int')
            );
        } finally {
            $pluginsProperty->setValue(null, $priorPlugins);
            $componentsProperty->setValue(null, $priorComponents);
        }
    }

    public function testSelectedCategoryScopeIncludesEligibleDescendantsAtNativeLevelLimit(): void
    {
        $parent = new CategoryNode((object) ['id' => 5, 'level' => 2]);
        $child  = new CategoryNode((object) ['id' => 6, 'level' => 3]);
        $deep   = new CategoryNode((object) ['id' => 7, 'level' => 4]);
        $child->setParent($parent);
        $deep->setParent($child);
        $parent->setAllLoaded();
        $child->setAllLoaded();
        $deep->setAllLoaded();

        $categories = $this->createMock(CategoryInterface::class);
        $categories->method('get')->with(5)->willReturn($parent);
        $component = $this->createMock(CategoryServiceInterface::class);
        $component->expects($this->once())
            ->method('getCategory')
            ->with(['access' => true, 'published' => 0], '')
            ->willReturn($categories);
        $app = new class ($component) {
            public function __construct(private readonly CategoryServiceInterface $component)
            {
            }

            public function bootComponent(string $name): CategoryServiceInterface
            {
                return $this->component;
            }
        };

        $priorApplication = Factory::$application;
        Factory::$application = $app;

        try {
            $model = $this->createModel(new PreparedFieldsFilter([], [], false));
            $model->setCurrentUser($this->createStub(User::class));
            $model->setState('filter.level', 2);
            $scope = (new \ReflectionMethod($model, 'getEffectiveCategoryIds'))->invoke($model, [5]);
        } finally {
            Factory::$application = $priorApplication;
        }

        $this->assertSame([5, 6], $scope);
    }

    public function testInvalidSelectedCategoryDoesNotBroadenFieldScope(): void
    {
        $categories = $this->createMock(CategoryInterface::class);
        $categories->method('get')->with(999)->willReturn(null);
        $component = $this->createMock(CategoryServiceInterface::class);
        $component->method('getCategory')->willReturn($categories);
        $app = new class ($component) {
            public function __construct(private readonly CategoryServiceInterface $component)
            {
            }

            public function bootComponent(string $name): CategoryServiceInterface
            {
                return $this->component;
            }
        };

        $priorApplication = Factory::$application;
        Factory::$application = $app;

        try {
            $model = $this->createModel(new PreparedFieldsFilter([], [], false));
            $model->setCurrentUser($this->createStub(User::class));
            $model->setState('filter.level', 0);
            $scope = (new \ReflectionMethod($model, 'getEffectiveCategoryIds'))->invoke($model, [999]);
        } finally {
            Factory::$application = $priorApplication;
        }

        $this->assertSame([999], $scope);
    }

    private function createModel(PreparedFieldsFilter $prepared): ArticlesModel
    {
        $reflection = new \ReflectionClass(ArticlesModel::class);
        $model      = $reflection->newInstanceWithoutConstructor();

        $filterFields = new \ReflectionProperty($model, 'filter_fields');
        $filterFields->setValue($model, ['published']);

        $context = new \ReflectionProperty($model, 'context');
        $context->setValue($model, 'com_content.articles');

        $stateSet = new \ReflectionProperty($model, '__state_set');
        $stateSet->setValue($model, true);

        $property = new \ReflectionProperty(ArticlesModel::class, 'preparedFieldsFilter');
        $property->setValue($model, $prepared);

        return $model;
    }

    private function getStoreId(ArticlesModel $model): string
    {
        $method = new \ReflectionMethod($model, 'getStoreId');

        return $method->invoke($model, 'test');
    }
}
