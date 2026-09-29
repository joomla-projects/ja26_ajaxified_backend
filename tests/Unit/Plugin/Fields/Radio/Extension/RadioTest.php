<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Fields.radio
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Fields\Radio\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\CustomFields\GetFilterProviderEvent;
use Joomla\CMS\Fields\CustomFieldFilterProviderInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Language;
use Joomla\CMS\User\User;
use Joomla\Database\Mysqli\MysqliDriver;
use Joomla\Database\ParameterType;
use Joomla\Database\Pgsql\PgsqlDriver;
use Joomla\Plugin\Fields\Radio\Extension\Radio;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Radio custom-field filter provider.
 *
 * @since  __DEPLOY_VERSION__
 */
class RadioTest extends UnitTestCase
{
    public function testExplicitlyImplementsFilterProviderContract(): void
    {
        $this->assertInstanceOf(CustomFieldFilterProviderInterface::class, $this->getPlugin());
    }

    public function testCommonHandlerAdvertisesOnlyRadioFields(): void
    {
        $plugin = $this->getPlugin();
        (new \ReflectionProperty($plugin, '_type'))->setValue($plugin, 'fields');
        (new \ReflectionProperty($plugin, '_name'))->setValue($plugin, 'radio');
        $app = $this->createStub(CMSApplicationInterface::class);
        $app->method('getLanguage')->willReturn($this->createStub(Language::class));
        $plugin->setApplication($app);

        $matching = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'radio'],
        ]);
        $plugin->getFilterProvider($matching);

        $other = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'checkboxes'],
        ]);
        $plugin->getFilterProvider($other);

        $this->assertSame([$plugin], $matching->getArgument('result'));
        $this->assertSame([], $other->getArgument('result', []));
    }

    public function testBuildsNativeStrictMultipleListWithoutFilterDefault(): void
    {
        $xml = $this->getPlugin()->getFilterField($this->getField(), 'customfield_7');

        $this->assertSame('customfield_7', (string) $xml['name']);
        $this->assertSame('list', (string) $xml['type']);
        $this->assertSame('true', (string) $xml['multiple']);
        $this->assertSame('true', (string) $xml['strictselection']);
        $this->assertSame('joomla.form.field.list-fancy-select', (string) $xml['layout']);
        $this->assertSame('', (string) $xml['default']);

        $form = new Form('test');
        $form->setCurrentUser($this->createStub(User::class));
        $form->load('<form><fields name="filter" /></form>');
        $form->setField($xml, 'filter');
        $field = $form->getField('customfield_7', 'filter');

        $this->assertSame('filter[customfield_7][]', $field->name);
        $this->assertSame('', $field->value);
    }

    public function testUsesConfiguredOptionsAndPreservesExactIdentities(): void
    {
        $plugin = $this->getPlugin();
        $field  = $this->getField();
        $xml    = $plugin->getFilterField($field, 'customfield_7');
        $values = [];

        foreach ($xml->option as $option) {
            $values[] = (string) $option['value'];
        }

        $this->assertSame(['1', '01', '001', '0', 'A', 'a', 'North ', 'Åland'], $values);
        $this->assertSame(
            ['0', '001', '01', '1', 'A', 'North ', 'a', 'Åland'],
            $plugin->normaliseValue($field, ['1', '01', '001', '0', 'A', 'a', 'North ', 'Åland'])
        );
        $this->assertSame([], $plugin->normaliseValue($field, ''));
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
            'unknown option' => [['unknown']],
            'nested input'   => [[['1']]],
        ];
    }

    public function testBuildsMySqlExactExistsPredicateAndBindings(): void
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

        $sql     = (string) $query;
        $bounded = $query->getBounded();

        $this->assertStringContainsString('EXISTS (', $sql);
        $this->assertStringContainsString('BINARY `fv`.`value` = BINARY :cff0_value0 OR BINARY `fv`.`value` = BINARY :cff0_value1', $sql);
        $this->assertSame(7, $bounded[':cff0_field']->value);
        $this->assertSame(ParameterType::INTEGER, $bounded[':cff0_field']->dataType);
        $this->assertSame('1', $bounded[':cff0_value0']->value);
        $this->assertSame('01', $bounded[':cff0_value1']->value);
        $this->assertSame(ParameterType::STRING, $bounded[':cff0_value0']->dataType);
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
            ['Åland'],
            $query->castAs('CHAR', $database->quoteName('a.id')),
            'cff0_'
        );

        $this->assertStringContainsString(
            "convert_to(\"fv\".\"value\", 'UTF8') = convert_to(:cff0_value0, 'UTF8')",
            (string) $query
        );
        $this->assertSame('Åland', $query->getBounded()[':cff0_value0']->value);
    }

    private function getPlugin(): Radio
    {
        $reflection     = new \ReflectionClass(Radio::class);
        $plugin         = $reflection->newInstanceWithoutConstructor();
        $plugin->params = new Registry();

        return $plugin;
    }

    private function getField(): object
    {
        return (object) [
            'id'            => 7,
            'label'         => 'Radio choice',
            'default_value' => '001',
            'fieldparams'   => new Registry([
                'options' => [
                    ['value' => '1', 'name' => 'One'],
                    ['value' => '01', 'name' => 'Zero one'],
                    ['value' => '001', 'name' => 'Zero zero one'],
                    ['value' => '0', 'name' => 'Zero'],
                    ['value' => '', 'name' => 'Empty'],
                    ['value' => 'A', 'name' => 'Upper'],
                    ['value' => 'a', 'name' => 'Lower'],
                    ['value' => 'North ', 'name' => 'North space'],
                    ['value' => 'Åland', 'name' => 'Unicode'],
                ],
            ]),
        ];
    }
}
