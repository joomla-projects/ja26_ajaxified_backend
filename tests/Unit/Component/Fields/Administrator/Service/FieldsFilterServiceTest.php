<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Fields\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\Event\CustomFields\GetFilterProviderEvent;
use Joomla\CMS\Fields\CustomFieldFilterProviderInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Fields\Administrator\Model\FieldsModel;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests generic custom-field filter preparation.
 *
 * @since  __DEPLOY_VERSION__
 */
class FieldsFilterServiceTest extends UnitTestCase
{
    private mixed $priorPlugins;
    private mixed $priorComponents;

    protected function setUp(): void
    {
        parent::setUp();

        $property           = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $this->priorPlugins = $property->getValue();
        $property->setValue(null, []);

        $property              = new \ReflectionProperty(ComponentHelper::class, 'components');
        $this->priorComponents = $property->getValue();
        $component             = new ComponentRecord(['enabled' => true]);
        $component->setParams(new Registry(['custom_fields_enable' => 1]));
        $property->setValue(null, ['com_content' => $component]);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(PluginHelper::class, 'plugins'))->setValue(null, $this->priorPlugins);
        (new \ReflectionProperty(ComponentHelper::class, 'components'))->setValue(null, $this->priorComponents);

        parent::tearDown();
    }

    public function testPreparesOnlyOptedInSupportedFieldsAndPreservesProviderCanonicalValues(): void
    {
        $provider = $this->createProvider();
        $service  = $this->createService([
            $this->createField(1, 'list', true),
            $this->createField(2, 'list', false),
            $this->createField(3, 'unsupported', true),
        ], $provider);

        $prepared = $service->prepare(
            'com_content.article',
            $this->createStub(User::class),
            [],
            '',
            ['customfield_1' => ['b', 'a'], 'customfield_2' => ['a']],
            []
        );

        $this->assertSame(['customfield_1'], array_keys($prepared->getFields()));
        $this->assertSame(['customfield_1' => ['a', 'b']], $prepared->getActive());
        $this->assertFalse($prepared->isRejected());
    }

    public function testUnpublishedFieldGroupIsExcluded(): void
    {
        $field              = $this->createField(4, 'list', true);
        $field->group_id    = 12;
        $field->group_state = 0;
        $prepared           = $this->createService([$field], $this->createProvider())->prepare(
            'com_content.article',
            $this->createStub(User::class),
            [],
            '',
            ['customfield_4' => ['a']],
            []
        );

        $this->assertSame([], $prepared->getFields());
    }

    public function testExplicitInvalidSelectionIsRejectedButRememberedInvalidSelectionIsCleared(): void
    {
        $service = $this->createService([$this->createField(1, 'list', true)], $this->createProvider());
        $user    = $this->createStub(User::class);

        $explicit = $service->prepare(
            'com_content.article',
            $user,
            [],
            '',
            ['customfield_1' => ['forged']],
            ['customfield_1' => ['forged']]
        );
        $remembered = $service->prepare(
            'com_content.article',
            $user,
            [],
            '',
            ['customfield_1' => ['forged']],
            []
        );

        $this->assertTrue($explicit->isRejected());
        $this->assertSame([], $explicit->getActive());
        $this->assertFalse($remembered->isRejected());
        $this->assertSame([], $remembered->getActive());
    }

    public function testDisabledComponentRejectsExplicitSelectionButClearsRememberedSelection(): void
    {
        ComponentHelper::getComponent('com_content')->setParams(new Registry(['custom_fields_enable' => 0]));
        $service = $this->createService([$this->createField(1, 'list', true)], $this->createProvider());
        $user    = $this->createStub(User::class);
        $state   = ['customfield_1' => ['a']];

        $explicit   = $service->prepare('com_content.article', $user, [], '', $state, $state);
        $remembered = $service->prepare('com_content.article', $user, [], '', $state, []);

        $this->assertSame([], $explicit->getFields());
        $this->assertSame([], $explicit->getActive());
        $this->assertTrue($explicit->isRejected());
        $this->assertSame([], $remembered->getFields());
        $this->assertSame([], $remembered->getActive());
        $this->assertFalse($remembered->isRejected());
    }

    public function testNativeSubmissionDoesNotMakeRememberedInvalidValueExplicit(): void
    {
        $service  = $this->createService([$this->createField(1, 'list', true)], $this->createProvider());
        $prepared = $service->prepare(
            'com_content.article',
            $this->createStub(User::class),
            [],
            '',
            ['published' => '1', 'customfield_1' => ['forged']],
            ['published' => '1']
        );

        $this->assertFalse($prepared->isRejected());
        $this->assertSame([], $prepared->getActive());
    }

    public function testOnlyNumericDynamicNamespaceIsOwned(): void
    {
        $service = $this->createService([
            $this->createField(1, 'list', true),
            $this->createField(17, 'list', true),
            $this->createField(123, 'list', true),
        ], $this->createProvider());
        $user    = $this->createStub(User::class);

        foreach (['customfield', 'customfield_search', 'customfield_invalid'] as $name) {
            $state    = [$name => ['a']];
            $prepared = $service->prepare('com_content.article', $user, [], '', $state, $state);
            $this->assertFalse($prepared->isRejected(), $name);
            $this->assertSame([], $prepared->getActive(), $name);
        }

        foreach (['customfield_', 'customfield_0', 'customfield_01', 'customfield_17x'] as $name) {
            $state    = [$name => ['a']];
            $prepared = $service->prepare('com_content.article', $user, [], '', $state, $state);
            $this->assertTrue($prepared->isRejected(), $name);
            $this->assertSame([], $prepared->getActive(), $name);
        }

        foreach (['customfield_1', 'customfield_17', 'customfield_123'] as $name) {
            $state    = [$name => ['a']];
            $prepared = $service->prepare('com_content.article', $user, [], '', $state, $state);
            $this->assertFalse($prepared->isRejected(), $name);
            $this->assertSame($state, $prepared->getActive(), $name);
        }
    }

    public function testInactiveValuesAreIgnoredButZeroIsActive(): void
    {
        $service = $this->createService([], $this->createProvider());
        $user    = $this->createStub(User::class);
        $empty   = $service->prepare(
            'com_content.article',
            $user,
            [],
            '',
            ['customfield_999' => ['', null]],
            ['customfield_999' => ['', null]]
        );
        $zero = $service->prepare(
            'com_content.article',
            $user,
            [],
            '',
            ['customfield_999' => '0'],
            ['customfield_999' => '0']
        );

        $this->assertFalse($empty->isRejected());
        $this->assertTrue($zero->isRejected());
    }

    public function testFailedFieldDiscoveryThrows(): void
    {
        $model = $this->createMock(FieldsModel::class);
        $model->method('getItems')->willReturn(false);
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $service = new FieldsFilterService(
            $factory,
            new Dispatcher(),
            $this->createStub(DatabaseInterface::class)
        );

        $this->expectException(\RuntimeException::class);
        $service->prepare('com_content.article', $this->createStub(User::class), [], '', [], []);
    }

    public function testAmbiguousProvidersMakeExplicitSelectionFailClosed(): void
    {
        $model = $this->createMock(FieldsModel::class);
        $model->method('getItems')->willReturn([$this->createField(1, 'list', true)]);
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $dispatcher = new Dispatcher();
        $first      = $this->createProvider();
        $second     = $this->createProvider();
        $dispatcher->addListener('onCustomFieldsGetFilterProvider', static function ($event) use ($first): void {
            $event->addResult($first);
        });
        $dispatcher->addListener('onCustomFieldsGetFilterProvider', static function ($event) use ($second): void {
            $event->addResult($second);
        });
        $service = new FieldsFilterService($factory, $dispatcher, $this->createStub(DatabaseInterface::class));

        $prepared = $service->prepare(
            'com_content.article',
            $this->createStub(User::class),
            [],
            '',
            ['customfield_1' => ['a']],
            ['customfield_1' => ['a']]
        );

        $this->assertSame([], $prepared->getFields());
        $this->assertTrue($prepared->isRejected());
    }

    public function testNoProviderMakesExplicitSelectionFailClosed(): void
    {
        $model = $this->createMock(FieldsModel::class);
        $model->method('getItems')->willReturn([$this->createField(1, 'list', true)]);
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $service = new FieldsFilterService($factory, new Dispatcher(), $this->createStub(DatabaseInterface::class));
        $state   = ['customfield_1' => ['a']];

        $prepared = $service->prepare('com_content.article', $this->createStub(User::class), [], '', $state, $state);

        $this->assertSame([], $prepared->getFields());
        $this->assertSame([], $prepared->getActive());
        $this->assertTrue($prepared->isRejected());
    }

    public function testUnknownExplicitFieldFailsClosed(): void
    {
        $service = $this->createService([], $this->createProvider());
        $user    = $this->createStub(User::class);

        $rejected = $service->prepare(
            'com_content.article',
            $user,
            [],
            '',
            ['customfield_999' => ['01'], 'customfield_998' => [], 'customfieldbad' => ['01']],
            ['customfield_999' => ['01'], 'customfield_998' => [], 'customfieldbad' => ['01']]
        );
        $this->assertTrue($rejected->isRejected());
        $this->assertSame([], $rejected->getActive());
    }

    public function testExactActiveFilterLimitBoundary(): void
    {
        $fields = [];
        $state  = [];

        for ($id = 1; $id <= 33; $id++) {
            $fields[]                    = $this->createField($id, 'list', true);
            $state['customfield_' . $id] = ['a'];
        }

        $service       = $this->createService($fields, $this->createProvider());
        $user          = $this->createStub(User::class);
        $acceptedState = \array_slice($state, 0, 32, true);
        $accepted      = $service->prepare(
            'com_content.article',
            $user,
            [],
            '',
            $acceptedState,
            $acceptedState
        );
        $rejected = $service->prepare(
            'com_content.article',
            $user,
            [],
            '',
            $state,
            $state
        );

        $this->assertFalse($accepted->isRejected());
        $this->assertCount(32, $accepted->getActive());
        $this->assertTrue($rejected->isRejected());
    }

    public function testRejectedPreparationAppliesFalseQueryCondition(): void
    {
        $database = $this->createStub(DatabaseInterface::class);
        $service  = new FieldsFilterService(
            $this->createStub(MVCFactoryInterface::class),
            new Dispatcher(),
            $database
        );
        $query = $this->getQueryStub($database)->select('*')->from('#__content');

        $service->applyToQuery($query, new PreparedFieldsFilter([], [], true), 'id');

        $this->assertStringContainsString('1 = 0', (string) $query->where);
    }

    public function testAppliesTwoActiveProvidersWithDistinctBindPrefixes(): void
    {
        $database       = $this->createStub(DatabaseInterface::class);
        $query          = $this->getQueryStub($database)->select('*')->from('#__content');
        $firstField     = $this->createField(1, 'list', true);
        $secondField    = $this->createField(2, 'list', true);
        $first          = $this->createMock(CustomFieldFilterProviderInterface::class);
        $second         = $this->createMock(CustomFieldFilterProviderInterface::class);
        $expression     = 'a.id';
        $castExpression = $query->castAs('CHAR', $expression);

        $first->expects($this->once())->method('applyFilter')->with(
            $query,
            $database,
            $firstField,
            ['a'],
            $castExpression,
            'cff0_'
        );
        $second->expects($this->once())->method('applyFilter')->with(
            $query,
            $database,
            $secondField,
            ['b'],
            $castExpression,
            'cff1_'
        );

        $prepared = new PreparedFieldsFilter(
            [
                'customfield_1' => ['field' => $firstField, 'provider' => $first],
                'customfield_2' => ['field' => $secondField, 'provider' => $second],
            ],
            ['customfield_1' => ['a'], 'customfield_2' => ['b']],
            false
        );
        $service = new FieldsFilterService($this->createStub(MVCFactoryInterface::class), new Dispatcher(), $database);

        $service->applyToQuery($query, $prepared, $expression);

        $this->assertNull($query->where);
    }

    public function testAddsFlatFilterFieldAndCanonicalFormValue(): void
    {
        $provider = $this->createProvider();
        $field    = $this->createField(1, 'list', true);
        $prepared = new PreparedFieldsFilter(
            ['customfield_1' => ['field' => $field, 'provider' => $provider]],
            ['customfield_1' => ['a']],
            false
        );
        $form = new Form('test');
        $form->load('<form><fields name="filter"><field name="published" type="list" /><fieldset name="default" /></fields></form>');
        $service = new FieldsFilterService(
            $this->createStub(MVCFactoryInterface::class),
            new Dispatcher(),
            $this->createStub(DatabaseInterface::class)
        );

        $service->addFilterFields($form, $prepared);

        $this->assertSame('customfield_1', (string) $form->getFieldXml('customfield_1', 'filter')['name']);
        $this->assertSame(['a'], $form->getValue('customfield_1', 'filter'));
        $this->assertSame('published', (string) $form->getFieldXml('published', 'filter')['name']);
    }

    public function testRejectsProviderFieldNameMismatchBeforeFormMutation(): void
    {
        $provider = $this->createMock(CustomFieldFilterProviderInterface::class);
        $provider->method('getFilterField')->willReturn(
            new \SimpleXMLElement('<field name="published" type="list" />')
        );
        $field    = $this->createField(1, 'list', true);
        $prepared = new PreparedFieldsFilter(
            ['customfield_1' => ['field' => $field, 'provider' => $provider]],
            [],
            false
        );
        $form = new Form('test');
        $form->load('<form><fields name="filter"><field name="published" type="list" /></fields></form>');
        $service = new FieldsFilterService(
            $this->createStub(MVCFactoryInterface::class),
            new Dispatcher(),
            $this->createStub(DatabaseInterface::class)
        );

        try {
            $service->addFilterFields($form, $prepared);
            $this->fail('Expected an invalid provider field name to be rejected.');
        } catch (\UnexpectedValueException) {
            $this->assertSame('published', (string) $form->getFieldXml('published', 'filter')['name']);
            $this->assertFalse($form->getFieldXml('customfield_1', 'filter'));
        }
    }

    private function createService(array $fields, CustomFieldFilterProviderInterface $provider): FieldsFilterService
    {
        $model = $this->createMock(FieldsModel::class);
        $model->method('getItems')->willReturn($fields);

        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);

        $dispatcher = new Dispatcher();
        $dispatcher->addListener(
            'onCustomFieldsGetFilterProvider',
            static function (GetFilterProviderEvent $event) use ($provider): void {
                if ($event->getField()->type === 'list') {
                    $event->addResult($provider);
                }
            }
        );

        return new FieldsFilterService($factory, $dispatcher, $this->createStub(DatabaseInterface::class));
    }

    private function createProvider(): CustomFieldFilterProviderInterface
    {
        return new class () implements CustomFieldFilterProviderInterface {
            public function getFilterField(object $field, string $name): \SimpleXMLElement
            {
                $element = new \SimpleXMLElement('<field/>');
                $element->addAttribute('name', $name);
                $element->addAttribute('type', 'list');

                return $element;
            }

            public function normaliseValue(object $field, mixed $value): array
            {
                $values = \is_array($value) ? $value : [$value];

                foreach ($values as $token) {
                    if (!\in_array($token, ['a', 'b'], true)) {
                        throw new \InvalidArgumentException();
                    }
                }

                $values = array_values(array_unique($values));
                sort($values, SORT_STRING);

                return $values;
            }

            public function applyFilter(
                QueryInterface $query,
                DatabaseInterface $database,
                object $field,
                array $value,
                string $itemIdExpression,
                string $bindPrefix
            ): void {
            }
        };
    }

    private function createField(int $id, string $type, bool $enabled): object
    {
        return (object) [
            'id'          => $id,
            'type'        => $type,
            'params'      => new Registry(['show_in_admin_list_filter' => $enabled ? 1 : 0]),
            'fieldparams' => new Registry(),
        ];
    }
}
