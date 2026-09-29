<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Fields\Administrator\Filter;

use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the prepared filter snapshot.
 *
 * @since  __DEPLOY_VERSION__
 */
class PreparedFieldsFilterTest extends UnitTestCase
{
    public function testPreservesPreparedFilterState(): void
    {
        $fields   = ['customfield_7' => ['field' => new \stdClass(), 'provider' => new \stdClass()]];
        $active   = ['customfield_7' => ['0', '01']];
        $prepared = new PreparedFieldsFilter($fields, $active, false);

        $this->assertSame($fields, $prepared->getFields());
        $this->assertSame($active, $prepared->getActive());
        $this->assertFalse($prepared->isRejected());
    }

    public function testFingerprintDistinguishesActiveFieldValuesAndRejection(): void
    {
        $one      = new PreparedFieldsFilter([], ['customfield_1' => ['01']], false);
        $zeroPad  = new PreparedFieldsFilter([], ['customfield_1' => ['001']], false);
        $newField = new PreparedFieldsFilter([], ['customfield_2' => ['01']], false);
        $rejected = new PreparedFieldsFilter([], ['customfield_1' => ['01']], true);

        $this->assertNotSame($one->getFingerprint(), $zeroPad->getFingerprint());
        $this->assertNotSame($one->getFingerprint(), $newField->getFingerprint());
        $this->assertNotSame($one->getFingerprint(), $rejected->getFingerprint());
        $this->assertNotSame($zeroPad->getFingerprint(), $newField->getFingerprint());
        $this->assertNotSame($zeroPad->getFingerprint(), $rejected->getFingerprint());
        $this->assertNotSame($newField->getFingerprint(), $rejected->getFingerprint());
    }

    public function testFingerprintIgnoresEligibleFieldMetadata(): void
    {
        $first = new PreparedFieldsFilter(
            ['customfield_1' => ['field' => (object) ['label' => 'First']]],
            ['customfield_7' => ['01']],
            false
        );
        $second = new PreparedFieldsFilter(
            ['customfield_2' => ['field' => (object) ['label' => 'Second']]],
            ['customfield_7' => ['01']],
            false
        );

        $this->assertSame($first->getFingerprint(), $second->getFingerprint());
    }
}
