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

    public function getSchemasToLoad(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db     = $this->getDBDriver();
        $prefix = str_starts_with($db->getServerType(), 'pgsql') ? 'CREATE TEMP TABLE ' : 'CREATE TEMPORARY TABLE ';

        $db->setQuery($prefix . $db->quoteName('#__cff_items') . ' ('
            . $db->quoteName('id') . ' VARCHAR(64) NOT NULL, '
            . $db->quoteName('kind') . ' VARCHAR(16) NOT NULL)')->execute();
        $db->setQuery($prefix . $db->quoteName('#__fields_values') . ' ('
            . $db->quoteName('field_id') . ' INTEGER NOT NULL, '
            . $db->quoteName('item_id') . ' VARCHAR(64) NOT NULL, '
            . $db->quoteName('value') . ' VARCHAR(255) NULL)')->execute();

        foreach ([['1', 'article'], ['01', 'text'], ['abc-1', 'text'], ['2', 'article'], ['3', 'article']] as $row) {
            $db->setQuery($db->createQuery()->insert($db->quoteName('#__cff_items'))
                ->columns([$db->quoteName('id'), $db->quoteName('kind')])
                ->values(implode(',', [$db->quote($row[0]), $db->quote($row[1])])))->execute();
        }

        foreach ([[7, '1', '0'], [7, '1', '0'], [7, '2', '01'], [7, '3', '1'], [12, '1', 'high'], [12, '2', 'low'], [7, '01', '01'], [7, 'abc-1', '1']] as $row) {
            $db->setQuery($db->createQuery()->insert($db->quoteName('#__fields_values'))
                ->columns([$db->quoteName('field_id'), $db->quoteName('item_id'), $db->quoteName('value')])
                ->values(implode(',', [(int) $row[0], $db->quote($row[1]), $db->quote($row[2])])))->execute();
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
            new PreparedFieldsFilter('com_content.article', [7 => [], 12 => []], [7 => ['0', '01'], 12 => ['high']]),
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
                new PreparedFieldsFilter('fixture.record', [7 => []], [7 => [$token]]),
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
}
