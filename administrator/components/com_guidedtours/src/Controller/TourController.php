<?php

/**
 * @package       Joomla.Administrator
 * @subpackage    com_guidedtours
 *
 * @copyright     (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license       GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Guidedtours\Administrator\Controller;

use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Controller for a single Tour
 *
 * @since 4.3.0
 */
class TourController extends FormController
{
    /**
     * Reconcile private drafts after the authoritative native success hook.
     *
     * @inheritDoc
     * @since  __DEPLOY_VERSION__
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        parent::postSaveHook($model, $validData);

        DraftService::reconcileNativeModelSave($this->app, $this->input, $model, 'com_guidedtours.tour', $this->getTask(), 'id', []);
    }
}
