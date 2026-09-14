<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Administrator\Components\Fields;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Event\DispatcherInterface;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

class FieldsFilterDatabaseTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    private const PRIMARY_FIELD_ID   = 900001;
    private const SECONDARY_FIELD_ID = 900002;
    private const EXACT_FIELD_ID     = 900003;

    public function getSchemasToLoad(): array
    {
        return ['fieldsfilter.sql'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->getDBDriver();

        $db->truncateTable('#__cff_items');

        $this->clearFieldValues();

        foreach (
            [
                ['1', 'article', 1],
                ['01', 'text', null],
                ['abc-1', 'text', null],
                ['2', 'article', 2],
                ['3', 'article', 3],
                ['case-upper', 'exact', 10],
                ['case-lower', 'exact', 11],
                ['accented', 'exact', 12],
                ['plain-accent', 'exact', 13],
                ['leading-space', 'exact', 14],
                ['plain-space', 'exact', 15],
                ['trailing-space', 'exact', 16],
                ['zero', 'exact', 17],
                ['leading-zero', 'exact', 18],
                ['one', 'exact', 19],
                ['unicode-a', 'exact', 20],
                ['unicode-b', 'exact', 21],
            ] as $row
        ) {
            $db->setQuery(
                $db->createQuery()
                    ->insert($db->quoteName('#__cff_items'))
                    ->columns([$db->quoteName('id'), $db->quoteName('kind'), $db->quoteName('integer_id')])
                    ->values(implode(',', [
                        $db->quote($row[0]),
                        $db->quote($row[1]),
                        $row[2] === null ? 'NULL' : (int) $row[2],
                    ]))
            )->execute();
        }

        foreach (
            [
                [self::PRIMARY_FIELD_ID, '1', '0'],
                [self::PRIMARY_FIELD_ID, '1', '0'],
                [self::PRIMARY_FIELD_ID, '2', '01'],
                [self::PRIMARY_FIELD_ID, '3', '1'],
                [self::SECONDARY_FIELD_ID, '1', 'high'],
                [self::SECONDARY_FIELD_ID, '2', 'low'],
                [self::PRIMARY_FIELD_ID, '01', '01'],
                [self::PRIMARY_FIELD_ID, 'abc-1', '1'],
                [self::EXACT_FIELD_ID, '10', 'A'],
                [self::EXACT_FIELD_ID, '10', 'A'],
                [self::EXACT_FIELD_ID, '11', 'a'],
                [self::EXACT_FIELD_ID, '12', 'café'],
                [self::EXACT_FIELD_ID, '13', 'cafe'],
                [self::EXACT_FIELD_ID, '14', ' token'],
                [self::EXACT_FIELD_ID, '15', 'token'],
                [self::EXACT_FIELD_ID, '16', 'token '],
                [self::EXACT_FIELD_ID, '17', '0'],
                [self::EXACT_FIELD_ID, '18', '01'],
                [self::EXACT_FIELD_ID, '19', '1'],
                [self::EXACT_FIELD_ID, '20', '日本'],
                [self::EXACT_FIELD_ID, '21', '日本語'],
            ] as $row
        ) {
            $db->setQuery(
                $db->createQuery()
                    ->insert($db->quoteName('#__fields_values'))
                    ->columns([
                        $db->quoteName('field_id'),
                        $db->quoteName('item_id'),
                        $db->quoteName('value'),
                    ])
                    ->values(
                        implode(',', [
                            (int) $row[0],
                            $db->quote($row[1]),
                            $db->quote($row[2]),
                        ])
                    )
            )->execute();
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->clearFieldValues();
        } finally {
            try {
                $this->getDBDriver()->truncateTable('#__cff_items');
            } finally {
                parent::tearDown();
            }
        }
    }

    public function testCorrelatedPredicatesPreserveOuterBindingsAndDoNotMultiplyRows(): void
    {
        $db      = $this->getDBDriver();
        $query   = $db->createQuery()->select($db->quoteName('i.id'))->from($db->quoteName('#__cff_items', 'i'));
        $kind    = 'article';
        $query->where($db->quoteName('i.kind') . ' = :kind')->bind(':kind', $kind);
        $service = $this->service();
        $service->applyToQuery(
            $query,
            new PreparedFieldsFilter(
                'com_content.article',
                [
                    self::PRIMARY_FIELD_ID   => [],
                    self::SECONDARY_FIELD_ID => [],
                ],
                [
                    self::PRIMARY_FIELD_ID   => ['0', '01'],
                    self::SECONDARY_FIELD_ID => ['high'],
                ]
            ),
            $db->quoteName('i.id'),
        );

        $this->assertSame(['1'], $db->setQuery($query)->loadColumn());
        $this->assertSame('article', $query->getBounded(':kind')->value);
    }

    public function testStringIdentitiesAndNumericLookingTokensRemainIndependent(): void
    {
        $db      = $this->getDBDriver();
        $service = $this->service();

        foreach ([['01', ['01']], ['1', ['abc-1']], ['0', []]] as [$token, $expected]) {
            $query = $db->createQuery()->select($db->quoteName('i.id'))->from($db->quoteName('#__cff_items', 'i'))
                ->where($db->quoteName('i.kind') . ' = ' . $db->quote('text'));
            $service->applyToQuery(
                $query,
                new PreparedFieldsFilter(
                    'fixture.record',
                    [self::PRIMARY_FIELD_ID => []],
                    [self::PRIMARY_FIELD_ID => [$token]]
                ),
                $db->quoteName('i.id'),
                'string',
            );
            $this->assertSame($expected, $db->setQuery($query)->loadColumn());
        }
    }

    /**
     * @dataProvider exactTokenProvider
     */
    public function testIntegerIdentitiesUseByteExactOptionTokenComparison(string $token, array $expected): void
    {
        $db    = $this->getDBDriver();
        $query = $db->createQuery()
            ->select($db->quoteName('i.integer_id'))
            ->from($db->quoteName('#__cff_items', 'i'))
            ->where($db->quoteName('i.kind') . ' = ' . $db->quote('exact'));

        $this->service()->applyToQuery(
            $query,
            new PreparedFieldsFilter(
                'com_content.article',
                [self::EXACT_FIELD_ID => []],
                [self::EXACT_FIELD_ID => [$token]],
            ),
            $db->quoteName('i.integer_id'),
        );

        $this->assertSame($expected, array_map('intval', $db->setQuery($query)->loadColumn()));
    }

    public static function exactTokenProvider(): iterable
    {
        yield 'case upper' => ['A', [10]];
        yield 'accented' => ['café', [12]];
        yield 'leading space' => [' token', [14]];
        yield 'trailing space' => ['token ', [16]];
        yield 'zero' => ['0', [17]];
        yield 'leading zero' => ['01', [18]];
        yield 'one' => ['1', [19]];
        yield 'Unicode token' => ['日本', [20]];
    }

    private function service(): FieldsFilterService
    {
        return new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $this->getDBDriver(),
            $this->createMock(DispatcherInterface::class),
        );
    }

    private function clearFieldValues(): void
    {
        $db = $this->getDBDriver();

        $query = $db->createQuery()
            ->delete($db->quoteName('#__fields_values'))
            ->whereIn(
                $db->quoteName('field_id'),
                [
                    self::PRIMARY_FIELD_ID,
                    self::SECONDARY_FIELD_ID,
                    self::EXACT_FIELD_ID,
                ]
            );

        $db->setQuery($query)->execute();
    }
}
