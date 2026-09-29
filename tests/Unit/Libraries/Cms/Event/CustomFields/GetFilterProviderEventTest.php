<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Event
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Event\CustomFields;

use Joomla\CMS\Event\CustomFields\GetFilterProviderEvent;
use Joomla\CMS\Fields\CustomFieldFilterProviderInterface;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the custom-field filter provider event.
 *
 * @since  __DEPLOY_VERSION__
 */
class GetFilterProviderEventTest extends UnitTestCase
{
    public function testCarriesFieldAndCollectsProviderResults(): void
    {
        $field = (object) ['id' => 7];
        $event = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', ['subject' => $field]);

        $this->assertSame($field, $event->getField());
        $this->assertSame([], $event->getArgument('result', []));

        $first  = $this->createStub(CustomFieldFilterProviderInterface::class);
        $second = $this->createStub(CustomFieldFilterProviderInterface::class);
        $event->addResult($first);
        $event->addResult($second);

        $this->assertSame([$first, $second], $event->getArgument('result'));
    }

    public function testRejectsInvalidProviderResult(): void
    {
        $event = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', ['subject' => new \stdClass()]);

        $this->expectException(\InvalidArgumentException::class);
        $event->addResult(new \stdClass());
    }

    public function testRejectsDirectResultAssignment(): void
    {
        $event = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', ['subject' => new \stdClass()]);

        $this->expectException(\BadMethodCallException::class);
        $event->setArgument('result', [$this->createStub(CustomFieldFilterProviderInterface::class)]);
    }
}
