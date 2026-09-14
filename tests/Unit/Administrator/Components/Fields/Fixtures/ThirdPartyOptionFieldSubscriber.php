<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Fixtures;

use Joomla\CMS\Event\CustomFields\GetFilterOptionsEvent;
use Joomla\Event\SubscriberInterface;

/**
 * Third-party-style field plugin fixture. It has no shared-engine registration.
 */
final class ThirdPartyOptionFieldSubscriber implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onCustomFieldsGetFilterOptions' => 'declareFilterOptions'];
    }

    public function declareFilterOptions(GetFilterOptionsEvent $event): void
    {
        if ($event->getField()->type !== 'fixture-option') {
            return;
        }

        $event->addResult([
            'options' => $event->getField()->filterOptions ?? [
                ['value' => '0', 'text' => 'Zero'],
                ['value' => '01', 'text' => 'Leading zero'],
                ['value' => 'north', 'text' => 'North'],
            ],
        ]);
    }
}
