<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Autosave\AutosaveServiceTrait;
use Joomla\CMS\Extension\MVCComponent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class WorkflowComponent extends MVCComponent implements AutosaveServiceInterface
{
    use AutosaveServiceTrait;

    public function getAutosaveContexts(): array
    {
        return ['com_workflow.workflow' => true, 'com_workflow.stage' => true];
    }
}
