<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Fields\Administrator\Helper;

use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Mysqli\MysqliQuery;
use Joomla\Database\ParameterType;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests custom-field list filter values and query predicates.
 *
 * @since  __DEPLOY_VERSION__
 */
class FieldsHelperTest extends UnitTestCase
{
    public function testListFilterValuesIgnoreEmptyAndNonScalarInputButKeepZero(): void
    {
        $this->assertSame([
            7 => ['0', 'blue'],
            8 => ['small'],
        ], FieldsHelper::getListFilterValues([
            'filter.search'        => 'Article',
            'filter.customfield_7' => ['', '0', ['nested'], null, 'blue'],
            'filter.customfield_8' => 'small',
            'filter.customfield_9' => '',
        ]));
    }

    public function testApplyListFilterBindsEachFieldAndItsSelectedValues(): void
    {
        $query = $this->getWorkingQuery();

        FieldsHelper::applyListFilter($query, 7, ['0', "O'Reilly"], 'CAST(`a`.`id` AS CHAR)');
        FieldsHelper::applyListFilter($query, 8, ['B'], 'CAST(`a`.`id` AS CHAR)');

        $sql = (string) $query;
        $this->assertSame(2, substr_count($sql, 'EXISTS (SELECT 1 FROM'));
        $this->assertSame(2, substr_count($sql, '`fv`.`field_id` = '));
        $this->assertStringContainsString('`fv`.`item_id` = CAST(`a`.`id` AS CHAR)', $sql);
        $this->assertSame(2, substr_count($sql, '`fv`.`value` IN ('));
        $this->assertStringNotContainsString("O'Reilly", $sql);
        $this->assertSame([7, '0', "O'Reilly", 8, 'B'], array_values(array_map(static fn ($bound) => $bound->value, $query->getBounded())));
        $this->assertSame([
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
        ], array_values(array_map(static fn ($bound) => $bound->dataType, $query->getBounded())));
    }

    public function testEmptySelectionDoesNotChangeTheQuery(): void
    {
        $query = $this->getWorkingQuery();
        $sql   = (string) $query;

        FieldsHelper::applyListFilter($query, 7, [], 'CAST(`a`.`id` AS CHAR)');

        $this->assertSame($sql, (string) $query);
        $this->assertSame([], $query->getBounded());
    }

    private function getWorkingQuery(): MysqliQuery
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('quoteName')->willReturnCallback(static function ($name, $alias = null): string {
            $quoted = '`' . str_replace('.', '`.`', $name) . '`';

            return $alias === null ? $quoted : $quoted . ' AS `' . $alias . '`';
        });

        return (new MysqliQuery($db))->select('a.id')->from('items AS a');
    }
}
