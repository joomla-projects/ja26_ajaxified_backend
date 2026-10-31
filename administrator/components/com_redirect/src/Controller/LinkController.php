<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_redirect
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Redirect\Administrator\Controller;

use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Redirect link controller class.
 *
 * @since  1.6
 */
class LinkController extends FormController
{
    // Parent class access checks are sufficient for this controller.
    /**
     * Reconcile private drafts after the authoritative native success hook.
     *
     * @inheritDoc
     * @since  __DEPLOY_VERSION__
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        parent::postSaveHook($model, $validData);

        DraftService::reconcileNativeModelSave($this->app, $this->input, $model, 'com_redirect.link', $this->getTask(), 'id', []);
    }
}
