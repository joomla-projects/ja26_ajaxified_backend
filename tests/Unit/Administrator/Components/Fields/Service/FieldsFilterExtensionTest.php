<?php

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
}
