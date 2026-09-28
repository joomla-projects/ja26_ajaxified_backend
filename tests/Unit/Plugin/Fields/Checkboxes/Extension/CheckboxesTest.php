<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Fields.checkboxes
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Fields\Checkboxes\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\CustomFields\GetFilterProviderEvent;
use Joomla\CMS\Fields\CustomFieldFilterProviderInterface;
use Joomla\CMS\Language\Language;
use Joomla\Database\Mysqli\MysqliDriver;
use Joomla\Database\ParameterType;
use Joomla\Database\Pgsql\PgsqlDriver;
use Joomla\Plugin\Fields\Checkboxes\Extension\Checkboxes;
use Joomla\Plugin\Fields\SQL\Extension\SQL;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Checkboxes custom-field filter provider.
 *
 * @since  __DEPLOY_VERSION__
 */
class CheckboxesTest extends UnitTestCase
{
    public function testExplicitlyImplementsFilterProviderContract(): void
    {
        $this->assertInstanceOf(CustomFieldFilterProviderInterface::class, $this->getPlugin());
    }

    public function testCommonHandlerAdvertisesOnlyCheckboxesFields(): void
    {
        $plugin = $this->getPlugin();
        (new \ReflectionProperty($plugin, '_type'))->setValue($plugin, 'fields');
        (new \ReflectionProperty($plugin, '_name'))->setValue($plugin, 'checkboxes');
        $app = $this->createStub(CMSApplicationInterface::class);
        $app->method('getLanguage')->willReturn($this->createStub(Language::class));
        $plugin->setApplication($app);

        $matching = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'checkboxes'],
        ]);
        $plugin->getFilterProvider($matching);

        $other = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'radio'],
        ]);
        $plugin->getFilterProvider($other);

        $this->assertSame([$plugin], $matching->getArgument('result'));
        $this->assertSame([], $other->getArgument('result', []));
    }

    public function testBuildsStrictMultipleListFromConfiguredOptions(): void
    {
        $xml    = $this->getPlugin()->getFilterField($this->getField(), 'customfield_9');
        $values = [];

        foreach ($xml->option as $option) {
            $values[] = (string) $option['value'];
        }

        $this->assertSame('list', (string) $xml['type']);
        $this->assertSame('true', (string) $xml['multiple']);
        $this->assertSame('true', (string) $xml['strictselection']);
        $this->assertSame('joomla.form.field.list-fancy-select', (string) $xml['layout']);
        $this->assertSame(['A', 'AA', 'C', '1', '01', '0', 'North ', 'Åland'], $values);
    }

    public function testCanonicalisesMultipleExactSelectionsAndDuplicates(): void
    {
        $plugin = $this->getPlugin();
        $field  = $this->getField();

        $this->assertSame(
            ['0', '01', '1', 'A', 'C', 'North ', 'Åland'],
            $plugin->normaliseValue($field, ['C', 'A', 'A', '0', '1', '01', '', 'North ', 'Åland'])
        );

        $this->expectException(\InvalidArgumentException::class);
        $plugin->normaliseValue($field, ['unknown']);
    }

    public function testBuildsOneMySqlExistsWithAnySemanticsAndNoEncodedMatching(): void
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
        $value = $this->getPlugin()->normaliseValue($this->getField(), ['C', 'A', 'A']);

        $this->getPlugin()->applyFilter(
            $query,
            $database,
            $this->getField(),
            $value,
            'CONVERT(' . $database->quoteName('a.id') . ', CHAR)',
            'cff0_'
        );

        $sql     = (string) $query;
        $bounded = $query->getBounded();

        $this->assertSame(1, substr_count($sql, 'EXISTS ('));
        $this->assertStringContainsString('BINARY `fv`.`value` = BINARY :cff0_value0 OR BINARY `fv`.`value` = BINARY :cff0_value1', $sql);
        $this->assertStringNotContainsString('LIKE', $sql);
        $this->assertStringNotContainsString('JSON', strtoupper($sql));
        $this->assertSame([':cff0_field', ':cff0_value0', ':cff0_value1'], array_keys($bounded));
        $this->assertSame(9, $bounded[':cff0_field']->value);
        $this->assertSame(ParameterType::INTEGER, $bounded[':cff0_field']->dataType);
        $this->assertSame('A', $bounded[':cff0_value0']->value);
        $this->assertSame('C', $bounded[':cff0_value1']->value);
        $this->assertSame(ParameterType::STRING, $bounded[':cff0_value0']->dataType);
    }

    public function testUsesExactRowMembershipForOneValue(): void
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
            ['A'],
            'CONVERT(' . $database->quoteName('a.id') . ', CHAR)',
            'cff0_'
        );

        $this->assertStringContainsString('BINARY `fv`.`value` = BINARY :cff0_value0', (string) $query);
        $this->assertSame('A', $query->getBounded()[':cff0_value0']->value);
    }

    public function testBuildsPostgresqlByteExactAnyComparison(): void
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
            ['A', 'C'],
            $query->castAs('CHAR', $database->quoteName('a.id')),
            'cff0_'
        );

        $sql = (string) $query;

        $this->assertStringContainsString(
            "convert_to(\"fv\".\"value\", 'UTF8') = convert_to(:cff0_value0, 'UTF8') OR "
            . "convert_to(\"fv\".\"value\", 'UTF8') = convert_to(:cff0_value1, 'UTF8')",
            $sql
        );
    }

    public function testSqlPluginDoesNotAdvertiseFilterProviderCapability(): void
    {
        $reflection = new \ReflectionClass(SQL::class);
        $plugin     = $reflection->newInstanceWithoutConstructor();
        $event      = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', [
            'subject' => (object) ['type' => 'sql'],
        ]);

        $this->assertNotInstanceOf(CustomFieldFilterProviderInterface::class, $plugin);

        $plugin->getFilterProvider($event);

        $this->assertSame([], $event->getArgument('result', []));
    }

    private function getPlugin(): Checkboxes
    {
        $reflection     = new \ReflectionClass(Checkboxes::class);
        $plugin         = $reflection->newInstanceWithoutConstructor();
        $plugin->params = new Registry();

        return $plugin;
    }

    private function getField(): object
    {
        return (object) [
            'id'          => 9,
            'label'       => 'Checkbox choices',
            'fieldparams' => new Registry([
                'options' => [
                    ['value' => 'A', 'name' => 'A'],
                    ['value' => 'AA', 'name' => 'AA'],
                    ['value' => 'C', 'name' => 'C'],
                    ['value' => '1', 'name' => 'One'],
                    ['value' => '01', 'name' => 'Zero one'],
                    ['value' => '0', 'name' => 'Zero'],
                    ['value' => '', 'name' => 'Empty'],
                    ['value' => 'North ', 'name' => 'North space'],
                    ['value' => 'Åland', 'name' => 'Unicode'],
                ],
            ]),
        ];
    }
}
