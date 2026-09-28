<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_contact
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Contact\Administrator\Model;

use Joomla\CMS\Categories\CategoryInterface;
use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Contact\Administrator\Model\ContactsModel;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Contacts model custom-field filtering integration.
 *
 * @since  __DEPLOY_VERSION__
 */
class ContactsModelFieldsFilterTest extends UnitTestCase
{
    public function testPopulateStateSuppliesContactContextAndResolvedLanguage(): void
    {
        $model = new class () extends ContactsModel {
            public array $preparedArguments = [];

            public function __construct()
            {
            }

            public function populateForTest(): void
            {
                $this->populateState();
            }

            protected function prepareFieldsFilter(
                object $app,
                string $context,
                array $categoryIds,
                string $language,
                array $submitted,
                array $previousFilters
            ): void {
                $this->preparedArguments = [$context, $categoryIds, $language, $submitted, $previousFilters];
            }
        };
        $this->initialiseModel($model, 'com_contact.contacts');

        $input = new class () {
            public array $values = [
                'layout'         => 'modal',
                'forcedLanguage' => 'fr-FR',
                'filter'         => ['language' => '*'],
            ];

            public function exists(string $name): bool
            {
                return \array_key_exists($name, $this->values);
            }

            public function get(string $name, mixed $default = null, string $filter = 'cmd'): mixed
            {
                return $this->values[$name] ?? $default;
            }

            public function set(string $name, mixed $value): void
            {
                $this->values[$name] = $value;
            }
        };
        $app   = $this->createApplication($input, [
            'com_contact.contacts.modal.fr-FR.filter' => ['customfield_search' => 'term'],
        ]);

        $previousApplication  = Factory::$application;
        Factory::$application = $app;

        try {
            $model->populateForTest();
        } finally {
            Factory::$application = $previousApplication;
        }

        $this->assertSame('com_contact.contact', $model->preparedArguments[0]);
        $this->assertSame([], $model->preparedArguments[1]);
        $this->assertSame('fr-FR', $model->preparedArguments[2]);
        $this->assertSame(['language' => '*'], $model->preparedArguments[3]);
        $this->assertSame(['customfield_search' => 'term'], $model->preparedArguments[4]);
        $this->assertSame('com_contact.contacts.modal.fr-FR', $this->getContext($model));
    }

    public function testCategoryScopeMatchesNativeDescendantLevelSemantics(): void
    {
        $parent       = new CategoryNode((object) ['id' => 5, 'level' => 2]);
        $child        = new CategoryNode((object) ['id' => 6, 'level' => 3]);
        $deep         = new CategoryNode((object) ['id' => 7, 'level' => 4]);
        $second       = new CategoryNode((object) ['id' => 8, 'level' => 2]);
        $siblingChild = new CategoryNode((object) ['id' => 9, 'level' => 3]);
        $child->setParent($parent);
        $deep->setParent($child);
        $siblingChild->setParent($second);
        $parent->setAllLoaded();
        $child->setAllLoaded();
        $deep->setAllLoaded();
        $second->setAllLoaded();
        $siblingChild->setAllLoaded();

        $categories = $this->createMock(CategoryInterface::class);
        $categories->method('get')->willReturnCallback(
            static fn (int $id): ?CategoryNode => match ($id) {
                5       => $parent,
                8       => $second,
                default => null,
            }
        );
        $component = $this->createMock(CategoryServiceInterface::class);
        $component->expects($this->exactly(5))->method('getCategory')->willReturn($categories);
        $app = new class ($component) {
            public function __construct(private readonly CategoryServiceInterface $component)
            {
            }

            public function bootComponent(string $name): CategoryServiceInterface
            {
                return $this->component;
            }
        };

        $model = new class () extends ContactsModel {
            public function __construct()
            {
            }

            public function categoryScope(array $selected): array
            {
                return (new \ReflectionMethod(ContactsModel::class, 'getEffectiveCategoryIds'))->invoke($this, $selected);
            }
        };
        $model->setCurrentUser($this->createStub(User::class));

        $previousApplication  = Factory::$application;
        Factory::$application = $app;

        try {
            $model->setState('filter.level', 1);
            $this->assertSame([5], $model->categoryScope([5]));

            $model->setState('filter.level', 2);
            $this->assertSame([5, 6], $model->categoryScope([5]));

            $model->setState('filter.level', 0);
            $this->assertSame([5, 6, 7], $model->categoryScope([5]));
            $model->setState('filter.level', 2);
            $this->assertSame([5, 8, 6, 9], $model->categoryScope([5, 8]));
            $this->assertSame([], $model->categoryScope([]));
            $this->assertSame([0], $model->categoryScope([0]));
            $this->assertSame([0], $model->categoryScope(['invalid']));
            $this->assertSame([999], $model->categoryScope([999]));
        } finally {
            Factory::$application = $previousApplication;
        }
    }

    public function testCanonicalFiltersExtendNativeActiveFiltersAndStoreIdentity(): void
    {
        $one      = $this->createPreparedModel(new PreparedFieldsFilter([], ['customfield_7' => ['north']], false));
        $other    = $this->createPreparedModel(new PreparedFieldsFilter([], ['customfield_7' => ['south']], false));
        $rejected = $this->createPreparedModel(new PreparedFieldsFilter([], ['customfield_7' => ['north']], true));
        $one->setState('filter.published', 1);

        $active = $one->getActiveFilters();

        $this->assertSame(1, $active['published']);
        $this->assertSame(['north'], $active['customfield_7']);
        $this->assertNotSame($one->storeIdForTest(), $other->storeIdForTest());
        $this->assertNotSame($one->storeIdForTest(), $rejected->storeIdForTest());
    }

    public function testFilterFormPreservesNativeFormAndInvokesCustomFieldInjection(): void
    {
        $form = new Form('contacts.test');
        $form->setCurrentUser($this->createStub(User::class));
        $form->load('<form><fields name="filter"><field name="language" type="text" /></fields></form>');
        $model = new class ($form) extends ContactsModel {
            public bool $injected = false;

            public function __construct(private readonly Form $testForm)
            {
            }

            protected function populateState($ordering = 'a.name', $direction = 'asc')
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

        $result = $model->getFilterForm();

        $this->assertTrue($model->injected);
        $this->assertSame($form, $result);
        $this->assertNotFalse($result->getField('language', 'filter'));
        $this->assertNotFalse($result->getField('customfield_7', 'filter'));
    }

    public function testQueryKeepsNativePredicatesAndSuppliesTrustedItemId(): void
    {
        $model = new class () extends ContactsModel {
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
        $this->initialiseModel($model, 'com_contact.contacts');
        $model->setDatabase($this->createDatabase());
        $user = $this->createStub(User::class);
        $user->method('authorise')->willReturn(true);
        $model->setCurrentUser($user);
        $model->setState('filter.published', 1);
        $model->setState('filter.language', 'fr-FR');
        $model->setState('filter.level', 2);

        $pluginsProperty = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $previousPlugins = $pluginsProperty->getValue();
        $previousEnabled = Multilanguage::$enabled;
        $pluginsProperty->setValue(null, []);
        Multilanguage::$enabled = true;

        try {
            $query = (string) $model->queryForTest();
        } finally {
            Multilanguage::$enabled = $previousEnabled;
            $pluginsProperty->setValue(null, $previousPlugins);
        }

        $this->assertSame('`a`.`id`', $model->itemIdExpression);
        $this->assertStringContainsString('`a`.`published` = :published', $query);
        $this->assertStringContainsString('`a`.`language` = :language', $query);
        $this->assertStringContainsString('`c`.`level` <= :level', $query);
        $this->assertStringContainsString('custom-field-predicate', $query);
    }

    private function createPreparedModel(PreparedFieldsFilter $prepared): object
    {
        $model = new class () extends ContactsModel {
            public function __construct()
            {
            }

            public function storeIdForTest(): string
            {
                return $this->getStoreId('test');
            }
        };
        $this->initialiseModel($model, 'com_contact.contacts');
        (new \ReflectionProperty(ContactsModel::class, 'preparedFieldsFilter'))->setValue($model, $prepared);

        return $model;
    }

    private function initialiseModel(ContactsModel $model, string $context): void
    {
        (new \ReflectionProperty(ListModel::class, 'context'))->setValue($model, $context);
        (new \ReflectionProperty(ListModel::class, 'filter_fields'))->setValue($model, ['published']);
        (new \ReflectionProperty($model, '__state_set'))->setValue($model, true);
    }

    private function getContext(ContactsModel $model): string
    {
        return (new \ReflectionProperty(ListModel::class, 'context'))->getValue($model);
    }

    private function createApplication(object $input, array $state): object
    {
        return new class ($input, $state) {
            public function __construct(private readonly object $input, public array $state)
            {
            }

            public function getInput(): object
            {
                return $this->input;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
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
                if (!$this->input->exists($request)) {
                    return $this->getUserState($key, $default);
                }

                $value = $this->input->get($request, $default, $type);
                $this->setUserState($key, $value);

                return $value;
            }
        };
    }

    private function createDatabase(): DatabaseInterface
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(
            static fn () => new class ($db) extends \Joomla\Database\DatabaseQuery {
                public function groupConcat($expression, $separator = ',')
                {
                    return (string) $expression;
                }

                public function processLimit($query, $limit, $offset = 0)
                {
                    return $query;
                }
            }
        );
        $db->method('escape')->willReturnArgument(0);
        $db->method('quote')->willReturnCallback(
            static fn (mixed $value): string => "'" . str_replace("'", "''", (string) $value) . "'"
        );
        $db->method('quoteName')->willReturnCallback(
            static function (mixed $name, mixed $as = null): array|string {
                if (\is_array($name)) {
                    return array_map(
                        static fn (string $item): string => '`' . str_replace('.', '`.`', $item) . '`',
                        $name
                    );
                }

                $quoted = '`' . str_replace('.', '`.`', (string) $name) . '`';

                return $as === null ? $quoted : $quoted . ' AS `' . $as . '`';
            }
        );

        return $db;
    }
}
