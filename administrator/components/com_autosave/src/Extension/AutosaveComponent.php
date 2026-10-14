<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_autosave
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Autosave\Administrator\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\Component\Autosave\Administrator\Dispatcher\Dispatcher;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Hidden administrator Autosave component.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveComponent implements ComponentInterface
{
    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly DraftService $drafts)
    {
    }

    /**
     * Return the component's protected draft dispatcher.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getDispatcher(CMSApplicationInterface $application): DispatcherInterface
    {
        return new Dispatcher($application, $application->getInput(), $this->drafts);
    }
}
