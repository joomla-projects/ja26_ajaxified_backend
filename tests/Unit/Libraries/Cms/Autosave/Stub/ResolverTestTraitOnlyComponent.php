<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveServiceTrait;
use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;

final class ResolverTestTraitOnlyComponent implements ComponentInterface
{
    use AutosaveServiceTrait;

    public function getDispatcher(CMSApplicationInterface $application): DispatcherInterface
    {
        throw new \LogicException('Not used by this test.');
    }
}
