<?php

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

        foreach ([['1', 'article'], ['01', 'text'], ['abc-1', 'text'], ['2', 'article'], ['3', 'article']] as $row) {
            $db->setQuery(
                $db->createQuery()
                    ->insert($db->quoteName('#__cff_items'))
                    ->columns([$db->quoteName('id'), $db->quoteName('kind')])
                    ->values(implode(',', [$db->quote($row[0]), $db->quote($row[1])]))
            )->execute();
        }

        foreach ([
            [self::PRIMARY_FIELD_ID, '1', '0'],
            [self::PRIMARY_FIELD_ID, '1', '0'],
            [self::PRIMARY_FIELD_ID, '2', '01'],
            [self::PRIMARY_FIELD_ID, '3', '1'],
            [self::SECONDARY_FIELD_ID, '1', 'high'],
            [self::SECONDARY_FIELD_ID, '2', 'low'],
            [self::PRIMARY_FIELD_ID, '01', '01'],
            [self::PRIMARY_FIELD_ID, 'abc-1', '1'],
        ] as $row) {
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
                ]
            );

        $db->setQuery($query)->execute();
    }
}
