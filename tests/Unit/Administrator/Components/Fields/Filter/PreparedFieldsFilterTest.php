<?php

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Filter;

use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Tests\Unit\UnitTestCase;

class PreparedFieldsFilterTest extends UnitTestCase
{
    public function testFingerprintIgnoresSelectionOrderAndLabels(): void
    {
        $first = new PreparedFieldsFilter(
            'com_content.article',
            [7  => ['label' => 'Region', 'options' => []]],
            [12 => ['high'], 7 => ['japan', 'india']],
        );
        $second = new PreparedFieldsFilter(
            'com_content.article',
            [7 => ['label' => 'Translated region', 'options' => []]],
            [7 => ['india', 'japan'], 12 => ['high']],
        );

        $this->assertSame($first->getFingerprint(), $second->getFingerprint());
    }

    public function testZeroAndLeadingZeroRemainDistinct(): void
    {
        $prepared = new PreparedFieldsFilter(
            'com_content.article',
            [7 => ['label' => 'Code', 'options' => []]],
            [7 => ['0', '01', '1']],
        );

        $this->assertSame([7 => ['0', '01', '1']], $prepared->getSelections());
        $this->assertSame(['customfield_7' => ['0', '01', '1']], $prepared->getActiveFilters());
    }

    public function testRemovalClearingAndDistinctTokensProduceCanonicalStates(): void
    {
        $controls  = [7 => ['label' => 'Code', 'options' => []], 12 => ['label' => 'Region', 'options' => []]];
        $both      = new PreparedFieldsFilter('com_content.article', $controls, [7 => ['01', '1'], 12 => ['japan']]);
        $leading   = new PreparedFieldsFilter('com_content.article', $controls, [7 => ['01'], 12 => ['japan']]);
        $one       = new PreparedFieldsFilter('com_content.article', $controls, [7 => ['1'], 12 => ['japan']]);
        $cleared   = new PreparedFieldsFilter('com_content.article', $controls, [12 => ['japan']]);
        $reordered = new PreparedFieldsFilter('com_content.article', $controls, [12 => ['japan'], 7 => ['1', '01']]);

        $this->assertSame([7 => ['01'], 12 => ['japan']], $leading->getSelections());
        $this->assertSame([7 => ['1'], 12 => ['japan']], $one->getSelections());
        $this->assertSame([12 => ['japan']], $cleared->getSelections());
        $this->assertSame($both->getFingerprint(), $reordered->getFingerprint());
        $this->assertNotSame($leading->getFingerprint(), $one->getFingerprint());
        $this->assertNotSame($one->getFingerprint(), $cleared->getFingerprint());
    }
}
