<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Fields.list
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Fields\ListField\Extension;

use Joomla\CMS\Event\CustomFields\GetFilterProviderEvent;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin;
use Joomla\Plugin\Fields\ListField\Extension\ListPlugin;
use Joomla\Database\Mysqli\MysqliDriver;
use Joomla\Database\Pgsql\PgsqlDriver;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the List custom-field filter provider.
 *
 * @since  __DEPLOY_VERSION__
 */
class ListPluginTest extends UnitTestCase
{
    public function testSubscribesToFilterProviderEvent(): void
    {
        $this->assertSame(
            'getFilterProvider',
            ListPlugin::getSubscribedEvents()['onCustomFieldsGetFilterProvider']
        );
    }

    public function testCommonHandlerAdvertisesOnlyTheOwningProvider(): void
    {
        $plugin = $this->getPlugin();
        (new \ReflectionProperty($plugin, '_type'))->setValue($plugin, 'fields');
        (new \ReflectionProperty($plugin, '_name'))->setValue($plugin, 'list');
        $app = $this->createStub(CMSApplicationInterface::class);
        $app->method('getLanguage')->willReturn($this->createStub(Language::class));
        $plugin->setApplication($app);

        $matching = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'list'],
        ]);
        $plugin->getFilterProvider($matching);

        $other = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'text'],
        ]);
        $plugin->getFilterProvider($other);

        $this->assertSame([$plugin], $matching->getArgument('result'));
        $this->assertSame([], $other->getArgument('result', []));
    }

    public function testUnrelatedFieldsPluginDoesNotAdvertiseProvider(): void
    {
        $plugin = new class () extends FieldsPlugin {
            public function __construct()
            {
            }

            public function onCustomFieldsGetTypes()
            {
                return [['type' => 'list']];
            }
        };
        $event = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'list'],
        ]);

        $plugin->getFilterProvider($event);

        $this->assertSame([], $event->getArgument('result', []));
    }

    public function testBuildsNativeStrictMultipleListFieldFromAuthoritativeOptions(): void
    {
        $xml = $this->getPlugin()->getFilterField($this->getField(), 'customfield_7');

        $this->assertSame('customfield_7', (string) $xml['name']);
        $this->assertSame('list', (string) $xml['type']);
        $this->assertSame('Exact & safe', (string) $xml['hint']);
        $this->assertSame('true', (string) $xml['multiple']);
        $this->assertSame('true', (string) $xml['strictselection']);
        $this->assertSame('joomla.form.field.list-fancy-select', (string) $xml['layout']);
        $this->assertSame('js-select-submit-on-change', (string) $xml['class']);
        $labels = [];
        $values = [];

        foreach ($xml->option as $option) {
            $labels[] = (string) $option;
            $values[] = (string) $option['value'];
        }

        $this->assertSame(['One', '01', '001', '0', 'North', 'north', 'North space', 'Åland'], $labels);
        $this->assertSame(['1', '01', '001', '0', 'North', 'north', 'North ', 'Åland'], $values);
        $this->assertNotContains('', $values);
    }

    public function testNativeFormUsesFlatMultipleFilterName(): void
    {
        $form = new Form('test');
        $form->setCurrentUser($this->createStub(User::class));
        $form->load('<form><fields name="filter" /></form>');
        $form->setField($this->getPlugin()->getFilterField($this->getField(), 'customfield_7'), 'filter');
        $form->setValue('customfield_7', 'filter', ['01']);

        $field = $form->getField('customfield_7', 'filter');

        $this->assertSame('filter[customfield_7][]', $field->name);
        $this->assertSame('Exact & safe', $field->hint);
        $this->assertSame(['01'], $field->value);
    }

    public function testNormalisesExactTokensIncludingZero(): void
    {
        $actual = $this->getPlugin()->normaliseValue(
            $this->getField(),
            ['1', '01', '001', '0', '01', '', 'North', 'north', 'North ', 'Åland']
        );

        $this->assertSame(['0', '001', '01', '1', 'North', 'North ', 'north', 'Åland'], $actual);
    }

    public function testEnforcesFilterValueCountBoundary(): void
    {
        $plugin = $this->getPlugin();
        $field = $this->getField();

        $this->assertSame(['1'], $plugin->normaliseValue($field, array_fill(0, 100, '1')));

        $this->expectException(\InvalidArgumentException::class);
        $plugin->normaliseValue($field, array_fill(0, 101, '1'));
    }

    public function testRejectsOversizedTokenEvenWhenItIsAConfiguredOption(): void
    {
        $accepted = str_repeat('x', 1024);
        $rejected = str_repeat('x', 1025);
        $field = $this->getField();
        $field->fieldparams = new Registry(['options' => [
            ['value' => $accepted, 'name' => 'Accepted'],
            ['value' => $rejected, 'name' => 'Rejected'],
        ]]);
        $plugin = $this->getPlugin();
        $options = $plugin->getFilterField($field, 'customfield_7')->option;

        $this->assertCount(1, $options);
        $this->assertSame($accepted, (string) $options[0]['value']);
        $this->assertSame([$accepted], $plugin->normaliseValue($field, [$accepted]));

        $this->expectException(\InvalidArgumentException::class);
        $plugin->normaliseValue($field, [$rejected]);
    }

    public function testBuildsMySqlCorrelatedExactExistsPredicateAndBindings(): void
    {
        $database = new MysqliDriver([
            'host'     => '127.0.0.1',
            'user'     => 'unused',
            'password' => 'unused',
            'database' => 'unused',
        ]);
        $query = $database->createQuery()
            ->select($database->quoteName('a.id'))
            ->from($database->quoteName('#__content', 'a'));

        $this->getPlugin()->applyFilter(
            $query,
            $database,
            $this->getField(),
            ['1', '01'],
            'CONVERT(' . $database->quoteName('a.id') . ', CHAR)',
            'cff0_'
        );

        $sql = (string) $query;

        $this->assertStringContainsString('EXISTS (', $sql);
        $this->assertStringContainsString('BINARY `fv`.`item_id` = BINARY CONVERT(`a`.`id`, CHAR)', $sql);
        $this->assertStringContainsString('BINARY `fv`.`value` = BINARY :cff0_value0', $sql);
        $this->assertStringContainsString('BINARY `fv`.`value` = BINARY :cff0_value1', $sql);
        $this->assertStringContainsString('BINARY `fv`.`value` = BINARY :cff0_value0 OR BINARY `fv`.`value` = BINARY :cff0_value1', $sql);
        $this->assertSame([':cff0_field', ':cff0_value0', ':cff0_value1'], array_keys($query->getBounded()));
        $this->assertSame(7, $query->getBounded()[':cff0_field']->value);
        $this->assertSame('1', $query->getBounded()[':cff0_value0']->value);
        $this->assertSame('01', $query->getBounded()[':cff0_value1']->value);
    }

    public function testBuildsPostgresqlByteExactComparison(): void
    {
        $database = new PgsqlDriver([
            'host'     => '127.0.0.1',
            'user'     => 'unused',
            'password' => 'unused',
            'database' => 'unused',
        ]);
        $query = $database->createQuery()
            ->select($database->quoteName('a.id'))
            ->from($database->quoteName('#__content', 'a'));

        $this->getPlugin()->applyFilter(
            $query,
            $database,
            $this->getField(),
            ['North'],
            $query->castAs('CHAR', $database->quoteName('a.id')),
            'cff0_'
        );

        $sql = (string) $query;

        $this->assertStringContainsString(
            "convert_to(\"fv\".\"item_id\", 'UTF8') = convert_to(\"a\".\"id\"::text, 'UTF8')",
            $sql
        );
        $this->assertStringContainsString(
            "convert_to(\"fv\".\"value\", 'UTF8') = convert_to(:cff0_value0, 'UTF8')",
            $sql
        );
    }

    /**
     * @dataProvider invalidValueProvider
     */
    public function testRejectsInvalidValues(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getPlugin()->normaliseValue($this->getField(), $value);
    }

    public function invalidValueProvider(): array
    {
        return [
            'forged option' => [['forged']],
            'nested input'  => [[['1']]],
            'object input'  => [[new \stdClass()]],
            'boolean input' => [[true]],
            'float input'   => [[1.0]],
        ];
    }

    private function getPlugin(): ListPlugin
    {
        $reflection = new \ReflectionClass(ListPlugin::class);
        $plugin     = $reflection->newInstanceWithoutConstructor();
        $plugin->params = new Registry();

        return $plugin;
    }

    private function getField(): object
    {
        return (object) [
            'id'          => 7,
            'label'       => 'Exact & safe',
            'fieldparams' => new Registry([
                'options' => [
                    ['value' => '1', 'name' => 'One'],
                    ['value' => '01', 'name' => '01'],
                    ['value' => '001', 'name' => '001'],
                    ['value' => '0', 'name' => '0'],
                    ['value' => '', 'name' => 'Empty'],
                    ['value' => 'North', 'name' => 'North'],
                    ['value' => 'north', 'name' => 'north'],
                    ['value' => 'North ', 'name' => 'North space'],
                    ['value' => 'Åland', 'name' => 'Åland'],
                ],
            ]),
        ];
    }
}
