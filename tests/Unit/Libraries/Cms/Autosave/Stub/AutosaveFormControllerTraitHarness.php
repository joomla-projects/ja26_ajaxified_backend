<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Concrete consumer of the production reconciliation trait.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveFormControllerTraitHarness extends AutosaveFormControllerTraitParent
{
    use AutosaveFormControllerTrait;

    private const AUTOSAVE_CONTEXT      = 'com_example.item';
    private const AUTOSAVE_TASK_INTENTS = [
        'apply'    => 'apply',
        'save'     => 'save-exit',
        'save2new' => 'save-new',
    ];

    public function captureSavedModel(BaseDatabaseModel $model): void
    {
        $this->captureAutosaveCanonicalResult($model, 'item.id');
    }
}
