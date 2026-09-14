<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Service;

use Joomla\CMS\Event\CustomFields\GetFilterOptionsEvent;
use Joomla\Event\Dispatcher;
use Joomla\Tests\Unit\Administrator\Components\Fields\Fixtures\ThirdPartyOptionFieldSubscriber;
use Joomla\Tests\Unit\UnitTestCase;

class FieldsFilterExtensionTest extends UnitTestCase
{
    public function testThirdPartySubscriberDeclaresOptionsWithoutEngineRegistration(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber(new ThirdPartyOptionFieldSubscriber());
        $event = new GetFilterOptionsEvent('onCustomFieldsGetFilterOptions', [
            'subject' => (object) ['type' => 'fixture-option'],
        ]);

        $result = $dispatcher->dispatch($event->getName(), $event)->getArgument('result');

        $this->assertCount(1, $result);
        $this->assertSame(['0', '01', 'north'], array_column($result[0]['options'], 'value'));
    }

    public function testNonParticipatingFieldTypeIsUnaffected(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber(new ThirdPartyOptionFieldSubscriber());
        $event = new GetFilterOptionsEvent('onCustomFieldsGetFilterOptions', [
            'subject' => (object) ['type' => 'unrelated'],
        ]);

        $result = $dispatcher->dispatch($event->getName(), $event)->getArgument('result', []);

        $this->assertSame([], $result);
    }

    public function testOptionEventUsesArrayResultsAndRejectsDirectReplacement(): void
    {
        $event = new GetFilterOptionsEvent('onCustomFieldsGetFilterOptions', [
            'subject' => (object) ['type' => 'fixture-option'],
        ]);
        $event->addResult(['options' => []]);
        $this->assertSame([['options' => []]], $event->getArgument('result'));

        try {
            $event->addResult('invalid');
            $this->fail('Non-array results must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([['options' => []]], $event->getArgument('result'));
        }

        $this->expectException(\BadMethodCallException::class);
        $event->setArgument('result', [['options' => []]]);
    }
}
