<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_categories
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Categories\Administrator\Model;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Categories\CategoryInterface;
use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Categories\SectionNotFoundException;
use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\User\User;
use Joomla\Component\Categories\Administrator\Model\CategoriesModel;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Categories model custom-field filtering integration.
 *
 * @since  __DEPLOY_VERSION__
 */
class CategoriesModelFieldsFilterTest extends UnitTestCase
{
    public function testPopulateStateSuppliesCategoryContextStateProvenanceAndLanguage(): void
    {
        $concrete = $this->populateModel(
            [
                'layout'         => 'modal',
                'forcedLanguage' => 'fr-FR',
                'extension'      => 'com_example.section',
                'filter'         => ['language' => '*', 'customfield_7' => ['north']],
            ],
            ['customfield_9' => ['old']]
        );

        $this->assertSame('com_example.section.categories', $concrete->model->preparedArguments[0]);
        $this->assertSame([], $concrete->model->preparedArguments[1]);
        $this->assertSame('fr-FR', $concrete->model->preparedArguments[2]);
        $this->assertSame(
            ['language' => '*', 'customfield_7' => ['north']],
            $concrete->model->preparedArguments[3]
        );
        $this->assertSame(['customfield_9' => ['old']], $concrete->model->preparedArguments[4]);

        $all = $this->populateModel(
            [
                'extension' => 'com_contact',
                'filter'    => ['language' => '*'],
            ]
        );

        $this->assertSame('com_contact.categories', $all->model->preparedArguments[0]);
        $this->assertSame('', $all->model->preparedArguments[2]);
    }

    public function testPopulateStateExpandsMultipleRootsThroughConfiguredSectionService(): void
    {
        $root  = new CategoryNode((object) ['id' => 10, 'level' => 2]);
        $child = new CategoryNode((object) ['id' => 20, 'level' => 3, 'parent_id' => 10, 'alias' => 'child']);
        $deep  = new CategoryNode((object) ['id' => 30, 'level' => 4, 'parent_id' => 20, 'alias' => 'deep']);
        $child->setParent($root);
        $deep->setParent($child);
        $root->setAllLoaded();
        $child->setAllLoaded();
        $deep->setAllLoaded();

        $categories = $this->createMock(CategoryInterface::class);
        $categories->method('get')->willReturnCallback(
            static fn (int $id): ?CategoryNode => match ($id) {
                10      => $root,
                20      => $child,
                default => null,
            }
        );
        $requests  = new \ArrayObject();
        $component = $this->createCategoryComponent($categories, null, $requests);
        $fixture   = $this->populateModel(
            [
                'extension' => 'com_example.section',
                'filter'    => ['category_id' => ['10', '20', '10']],
            ],
            [],
            $component
        );

        $this->assertEqualsCanonicalizing([10, 20, 30], $fixture->model->preparedArguments[1]);
        $this->assertSame([[['access' => false, 'published' => 0], 'section']], $requests->getArrayCopy());
        $this->assertSame(['com_example'], $fixture->bootedComponents);
    }

    public function testPopulateStateAppliesRelativeLevelAndKeepsLevelOnlyUnrestricted(): void
    {
        $first       = new CategoryNode((object) ['id' => 10, 'level' => 2]);
        $firstChild  = new CategoryNode((object) ['id' => 11, 'level' => 3, 'parent_id' => 10, 'alias' => 'first-child']);
        $firstDeep   = new CategoryNode((object) ['id' => 12, 'level' => 4, 'parent_id' => 11, 'alias' => 'first-deep']);
        $second      = new CategoryNode((object) ['id' => 20, 'level' => 4]);
        $secondChild = new CategoryNode((object) ['id' => 21, 'level' => 5, 'parent_id' => 20, 'alias' => 'second-child']);
        $firstChild->setParent($first);
        $firstDeep->setParent($firstChild);
        $secondChild->setParent($second);

        foreach ([$first, $firstChild, $firstDeep, $second, $secondChild] as $category) {
            $category->setAllLoaded();
        }

        $categories = $this->createMock(CategoryInterface::class);
        $categories->method('get')->willReturnCallback(
            static fn (int $id): ?CategoryNode => match ($id) {
                10      => $first,
                20      => $second,
                default => null,
            }
        );
        $component = $this->createCategoryComponent($categories);
        $limited   = $this->populateModel(
            [
                'extension' => 'com_example',
                'filter'    => ['category_id' => [10, 20], 'level' => 2],
            ],
            [],
            $component
        );

        $this->assertEqualsCanonicalizing([10, 20, 11, 21], $limited->model->preparedArguments[1]);

        $levelOnly = $this->populateModel(
            [
                'extension' => 'com_example',
                'filter'    => ['level' => 2],
            ]
        );

        $this->assertSame([], $levelOnly->model->preparedArguments[1]);
        $this->assertSame([], $levelOnly->bootedComponents);
    }

    public function testPopulateStateNormalizesEmptyScalarMalformedAndUnresolvedCategoryState(): void
    {
        $empty = $this->populateModel(
            ['extension' => 'com_example', 'filter' => ['category_id' => '']]
        );
        $this->assertSame([], $empty->model->preparedArguments[1]);
        $this->assertSame([], $empty->bootedComponents);

        $scalar = $this->populateModel(
            ['extension' => 'com_example', 'filter' => ['category_id' => '12']]
        );
        $this->assertSame([12], $scalar->model->preparedArguments[1]);

        $malformed = $this->populateModel(
            ['extension' => 'com_example', 'filter' => ['category_id' => [0, 'invalid', ['12'], true]]]
        );
        $this->assertSame([0], $malformed->model->preparedArguments[1]);

        $categories = $this->createMock(CategoryInterface::class);
        $categories->expects($this->once())->method('get')->with(999999)->willReturn(null);
        $unresolved = $this->populateModel(
            ['extension' => 'com_example', 'filter' => ['category_id' => '999999']],
            [],
            $this->createCategoryComponent($categories)
        );
        $this->assertSame([999999], $unresolved->model->preparedArguments[1]);
    }

    public function testPopulateStateRetainsRootsForMissingServiceAndSection(): void
    {
        $missingService = $this->populateModel(
            [
                'extension' => 'com_example',
                'filter'    => ['category_id' => ['12', ['56'], 'invalid', '34']],
            ]
        );
        $this->assertEqualsCanonicalizing([12, 34], $missingService->model->preparedArguments[1]);

        $requests  = new \ArrayObject();
        $component = $this->createCategoryComponent(
            null,
            new SectionNotFoundException('Unknown category section'),
            $requests
        );
        $missingSection = $this->populateModel(
            [
                'extension' => 'com_example.missing',
                'filter'    => ['category_id' => ['12', '34']],
            ],
            [],
            $component
        );

        $this->assertEqualsCanonicalizing([12, 34], $missingSection->model->preparedArguments[1]);
        $this->assertSame([[['access' => false, 'published' => 0], 'missing']], $requests->getArrayCopy());
    }

    public function testFormActiveFiltersAndStoreIdentityIncludePreparedCustomFields(): void
    {
        $form = new Form('categories.test');
        $form->setCurrentUser($this->createStub(User::class));
        $form->load('<form><fields name="filter"><field name="published" type="text" /></fields></form>');
        $model = new class ($form) extends CategoriesModel {
            public function __construct(private readonly Form $testForm)
            {
            }

            public function storeIdForTest(): string
            {
                return $this->getStoreId('test');
            }

            protected function loadForm($name, $source = null, $options = [], $clear = false, $xpath = null)
            {
                return $this->testForm;
            }

            protected function addFieldsFiltersToForm(Form $form, bool $setValues = true): void
            {
                $form->setField(new \SimpleXMLElement('<field name="customfield_7" type="text" />'), 'filter');
            }
        };
        $this->initialiseModel($model);
        $model->setState('filter.published', 1);
        (new \ReflectionProperty(CategoriesModel::class, 'preparedFieldsFilter'))->setValue(
            $model,
            new PreparedFieldsFilter([], ['customfield_7' => ['north']], false)
        );

        $northStoreId = $model->storeIdForTest();
        $model->getFilterForm();
        $active = $model->getActiveFilters();

        $this->assertNotFalse($form->getField('published', 'filter'));
        $this->assertNotFalse($form->getField('customfield_7', 'filter'));
        $this->assertSame(1, $active['published']);
        $this->assertSame(['north'], $active['customfield_7']);

        (new \ReflectionProperty(CategoriesModel::class, 'preparedFieldsFilter'))->setValue(
            $model,
            new PreparedFieldsFilter([], ['customfield_7' => ['south']], false)
        );
        $this->assertNotSame($northStoreId, $model->storeIdForTest());
    }

    public function testQueryAppliesPreparedFiltersOnceAgainstDatabaseQuotedCategoryId(): void
    {
        $model = new class () extends CategoriesModel {
            public int $applicationCount = 0;
            public string $itemIdExpression = '';

            public function __construct()
            {
            }

            public function getAssoc()
            {
                return false;
            }

            public function queryForTest(): QueryInterface
            {
                return $this->getListQuery();
            }

            protected function applyPreparedFieldsFilters(QueryInterface $query, string $itemIdExpression): void
            {
                $this->applicationCount++;
                $this->itemIdExpression = $itemIdExpression;
            }
        };
        $this->initialiseModel($model);
        $db    = $this->createStub(DatabaseInterface::class);
        $query = $this->getQueryStub($db);
        $db->method('createQuery')->willReturn($query);
        $db->method('escape')->willReturnArgument(0);
        $db->method('quoteName')->willReturnCallback(
            static function (mixed $name, mixed $as = null): array|string {
                if (\is_array($name)) {
                    return array_map(static fn (string $item): string => $item, $name);
                }

                if ($name === 'a.id' && $as === null) {
                    return 'QUOTED_CATEGORY_ID';
                }

                return $as === null ? (string) $name : $name . ' AS ' . $as;
            }
        );
        $model->setDatabase($db);
        $user = $this->createStub(User::class);
        $user->method('authorise')->willReturn(true);
        $model->setCurrentUser($user);
        $model->setState('filter.extension', 'com_example');

        $model->queryForTest();

        $this->assertSame(1, $model->applicationCount);
        $this->assertSame('QUOTED_CATEGORY_ID', $model->itemIdExpression);
    }

    private function createPopulateModel(): object
    {
        $model = new class () extends CategoriesModel {
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

    private function populateModel(
        array $inputValues,
        array $previousFilters = [],
        ?ComponentInterface $component = null
    ): object {
        $context = 'com_categories.categories.example';

        if (!empty($inputValues['layout'])) {
            $context .= '.' . $inputValues['layout'];
        }

        if (!empty($inputValues['forcedLanguage'])) {
            $context .= '.' . $inputValues['forcedLanguage'];
        }

        $model     = $this->createPopulateModel();
        $component = $component ?? $this->createStub(ComponentInterface::class);
        $fixture   = $this->createApplication(
            $inputValues,
            [$context . '.filter' => $previousFilters],
            $component
        );

        $this->withApplication($fixture->application, static fn () => $model->populateForTest());
        $fixture->model = $model;

        return $fixture;
    }

    private function createApplication(array $inputValues, array $state, ComponentInterface $component): object
    {
        $fixture                   = new \stdClass();
        $fixture->input            = new Input($inputValues);
        $fixture->state            = $state;
        $fixture->bootedComponents = [];
        $app                       = $this->createMock(CMSWebApplicationInterface::class);
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
                $value = $fixture->input->get($request, null, $type);

                if ($value === null) {
                    return $fixture->state[$key] ?? $default;
                }

                $fixture->state[$key] = $value;

                return $value;
            }
        );
        $app->method('bootComponent')->willReturnCallback(
            static function (string $name) use ($fixture, $component): ComponentInterface {
                $fixture->bootedComponents[] = $name;

                return $component;
            }
        );
        $fixture->application = $app;

        return $fixture;
    }

    private function initialiseModel(CategoriesModel $model): void
    {
        (new \ReflectionProperty(ListModel::class, 'context'))->setValue($model, 'com_categories.categories.example');
        (new \ReflectionProperty(ListModel::class, 'filter_fields'))->setValue($model, ['published']);
        (new \ReflectionProperty($model, '__state_set'))->setValue($model, true);
    }

    private function createCategoryComponent(
        ?CategoryInterface $categories,
        ?SectionNotFoundException $exception = null,
        ?\ArrayObject $requests = null
    ): ComponentInterface {
        return new class ($categories, $exception, $requests ?? new \ArrayObject()) implements ComponentInterface, CategoryServiceInterface {
            public function __construct(
                private readonly ?CategoryInterface $categories,
                private readonly ?SectionNotFoundException $exception,
                private readonly \ArrayObject $requests
            ) {
            }

            public function getDispatcher(CMSApplicationInterface $application): DispatcherInterface
            {
                throw new \LogicException('Not used by this test double.');
            }

            public function getCategory(array $options = [], $section = ''): CategoryInterface
            {
                $this->requests->append([$options, $section]);

                if ($this->exception) {
                    throw $this->exception;
                }

                return $this->categories ?? throw new \LogicException('No category tree configured.');
            }

            public function countItems(array $items, string $section)
            {
            }

            public function prepareForm(Form $form, $data)
            {
            }
        };
    }

    private function withApplication(CMSWebApplicationInterface $app, callable $callback): void
    {
        $previousApplication  = Factory::$application;
        Factory::$application = $app;

        try {
            $callback();
        } finally {
            Factory::$application = $previousApplication;
        }
    }
}
