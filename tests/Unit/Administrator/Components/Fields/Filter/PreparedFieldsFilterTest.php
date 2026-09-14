<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Filter;

use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Tests\Unit\UnitTestCase;

class PreparedFieldsFilterTest extends UnitTestCase
{
    public function testFingerprintTracksExactCanonicalQueryStateButIgnoresOrderAndLabels(): void
    {
        $first = new PreparedFieldsFilter(
            'com_content.article',
            [7  => ['label' => 'Region', 'options' => []]],
            [12 => ['high'], 7 => ['01', '1']],
        );
        $second = new PreparedFieldsFilter(
            'com_content.article',
            [7 => ['label' => 'Translated region', 'options' => []]],
            [7 => ['1', '01'], 12 => ['high']],
        );
        $differentToken = new PreparedFieldsFilter(
            'com_content.article',
            $first->getControls(),
            [7 => ['1'], 12 => ['high']],
        );
        $cleared = new PreparedFieldsFilter('com_content.article', $first->getControls(), []);

        $this->assertSame($first->getFingerprint(), $second->getFingerprint());
        $this->assertNotSame($first->getFingerprint(), $differentToken->getFingerprint());
        $this->assertNotSame($differentToken->getFingerprint(), $cleared->getFingerprint());
    }
}
