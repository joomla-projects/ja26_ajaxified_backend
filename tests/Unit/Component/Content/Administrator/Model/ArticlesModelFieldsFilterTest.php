<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Content\Administrator\Model;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Categories\CategoryInterface;
use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Model\ArticlesModel;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Fields\Administrator\Model\FieldsModel;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
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

        (new \ReflectionProperty($model, 'fieldsFilterService'))->setValue(
            $model,
            $this->createEmptyFieldsFilterService()
        );
        $fixture = $this->createFilterApplication(
            ['published' => '1', 'customfield_search' => 'term'],
            ['published' => '1', 'customfield_7' => ['01'], 'customfield_search' => 'term'],
            40
        );
        $app = $fixture->application;

        $pluginsProperty    = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $priorPlugins       = $pluginsProperty->getValue();
        $componentsProperty = new \ReflectionProperty(ComponentHelper::class, 'components');
        $priorComponents    = $componentsProperty->getValue();
        $component          = new ComponentRecord(['enabled' => true]);
        $component->setParams(new Registry(['custom_fields_enable' => 1]));
        $componentsProperty->setValue(null, ['com_content' => $component]);
        $pluginsProperty->setValue(null, []);

        try {
            (new \ReflectionMethod($model, 'prepareFieldsFilter'))->invoke(
                $model,
                $app,
                'com_content.article',
                [],
                '',
                [],
                ['published' => '1', 'customfield_7' => ['01'], 'customfield_search' => 'term']
            );

            $this->assertSame(
                ['published' => '1', 'customfield_search' => 'term'],
                $fixture->state['com_content.articles.filter']
            );
            $this->assertSame(
                ['published' => '1', 'customfield_search' => 'term'],
                $fixture->state['com_content.articles']->filter
            );
            $this->assertSame([], $model->getState('filter.customfield_7', []));
            $this->assertSame(0, $fixture->input->getInt('limitstart'));
            $this->assertSame(0, $fixture->state['com_content.articles.limitstart']);
            $this->assertSame(0, $model->getState('list.start'));

            $fixture->input = new Input();

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

        $priorApplication     = Factory::$application;
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

        $priorApplication     = Factory::$application;
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

    public function testExplicitRejectionWarnsAndFailsQueryClosed(): void
    {
        $model = $this->createModel(new PreparedFieldsFilter([], [], false));
        $model->setCurrentUser($this->createStub(User::class));
        $service = $this->createEmptyFieldsFilterService();
        (new \ReflectionProperty($model, 'fieldsFilterService'))->setValue($model, $service);
        $fixture = $this->createFilterApplication(['published' => '1', 'customfield_999' => ['invalid']]);
        $app     = $fixture->application;

        $pluginsProperty    = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $priorPlugins       = $pluginsProperty->getValue();
        $componentsProperty = new \ReflectionProperty(ComponentHelper::class, 'components');
        $priorComponents    = $componentsProperty->getValue();
        $priorApplication   = Factory::$application;
        $languageProperty   = new \ReflectionProperty(Factory::class, 'language');
        $priorLanguage      = $languageProperty->getValue();
        $component          = new ComponentRecord(['enabled' => true]);
        $component->setParams(new Registry(['custom_fields_enable' => 1]));
        $componentsProperty->setValue(null, ['com_content' => $component]);
        $pluginsProperty->setValue(null, []);
        Factory::$application = $app;
        $languageProperty->setValue(null, $fixture->language);

        try {
            (new \ReflectionMethod($model, 'prepareFieldsFilter'))->invoke(
                $model,
                $app,
                'com_content.article',
                [],
                '',
                ['customfield_999' => ['invalid']],
                ['published' => '1', 'customfield_999' => ['invalid']]
            );

            $query = $this->createMock(QueryInterface::class);
            $query->expects($this->once())->method('where')->with('1 = 0')->willReturnSelf();
            (new \ReflectionMethod($model, 'applyPreparedFieldsFilters'))->invoke($model, $query, '`a`.`id`');

            $prepared = (new \ReflectionProperty(ArticlesModel::class, 'preparedFieldsFilter'))->getValue($model);
            $this->assertTrue($prepared->isRejected());
            $this->assertSame(
                [['COM_FIELDS_FILTER_INVALID_SELECTION', 'warning']],
                $fixture->messages
            );
        } finally {
            Factory::$application = $priorApplication;
            $languageProperty->setValue(null, $priorLanguage);
            $pluginsProperty->setValue(null, $priorPlugins);
            $componentsProperty->setValue(null, $priorComponents);
        }
    }

    public function testRememberedStaleDynamicStateClearsWithoutWarning(): void
    {
        $model = $this->createModel(new PreparedFieldsFilter([], [], false));
        $model->setCurrentUser($this->createStub(User::class));
        (new \ReflectionProperty($model, 'fieldsFilterService'))->setValue(
            $model,
            $this->createEmptyFieldsFilterService()
        );
        $remembered = [
            'published'          => '1',
            'customfield_999'    => ['stale'],
            'customfield_0'      => ['zero'],
            'customfield_17x'    => ['malformed'],
            'customfield_search' => 'term',
        ];
        $fixture = $this->createFilterApplication($remembered);
        $app     = $fixture->application;

        $pluginsProperty    = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $priorPlugins       = $pluginsProperty->getValue();
        $componentsProperty = new \ReflectionProperty(ComponentHelper::class, 'components');
        $priorComponents    = $componentsProperty->getValue();
        $component          = new ComponentRecord(['enabled' => true]);
        $component->setParams(new Registry(['custom_fields_enable' => 1]));
        $componentsProperty->setValue(null, ['com_content' => $component]);
        $pluginsProperty->setValue(null, []);

        try {
            (new \ReflectionMethod($model, 'prepareFieldsFilter'))->invoke(
                $model,
                $app,
                'com_content.article',
                [],
                '',
                [],
                $remembered
            );

            $prepared = (new \ReflectionProperty(ArticlesModel::class, 'preparedFieldsFilter'))->getValue($model);
            $this->assertFalse($prepared->isRejected());
            $expected = ['published' => '1', 'customfield_search' => 'term'];
            $this->assertSame($expected, $fixture->state['com_content.articles.filter']);
            $this->assertSame($expected, $fixture->state['com_content.articles']->filter);
            $this->assertSame([], $fixture->messages);
        } finally {
            $pluginsProperty->setValue(null, $priorPlugins);
            $componentsProperty->setValue(null, $priorComponents);
        }
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

    private function createEmptyFieldsFilterService(): FieldsFilterService
    {
        $fieldsModel = $this->createMock(FieldsModel::class);
        $fieldsModel->method('getItems')->willReturn([]);
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($fieldsModel);

        return new FieldsFilterService($factory, new Dispatcher(), $this->createStub(DatabaseInterface::class));
    }

    private function createFilterApplication(
        array $filters,
        ?array $formFilters = null,
        int $limitStart = 20
    ): object {
        $fixture           = new \stdClass();
        $fixture->input    = new Input();
        $fixture->messages = [];
        $fixture->state    = [
            'com_content.articles.filter'     => $filters,
            'com_content.articles'            => (object) ['filter' => $formFilters ?? $filters],
            'com_content.articles.limitstart' => $limitStart,
        ];
        $fixture->language = $this->createStub(Language::class);
        $fixture->language->method('load')->willReturn(true);
        $fixture->language->method('_')->willReturnArgument(0);

        $app = $this->createMock(CMSWebApplicationInterface::class);
        $app->method('getInput')->willReturnCallback(static fn (): Input => $fixture->input);
        $app->method('getLanguage')->willReturn($fixture->language);
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
        $app->method('enqueueMessage')->willReturnCallback(
            static function (string $message, string $type = 'message') use ($fixture): void {
                $fixture->messages[] = [$message, $type];
            }
        );
        $fixture->application = $app;

        return $fixture;
    }
}
