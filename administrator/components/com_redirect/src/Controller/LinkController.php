<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_redirect
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Redirect\Administrator\Controller;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
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
    use AutosaveFormControllerTrait;

    private const AUTOSAVE_CONTEXT      = 'com_redirect.link';
    private const AUTOSAVE_TASK_INTENTS = [
        'apply'    => 'apply',
        'save'     => 'save-exit',
        'save2new' => 'save-new',
    ];

    /**
     * Capture the identity from the exact model Joomla saved.
     *
     * @param   BaseDatabaseModel  $model      The saved model.
     * @param   array              $validData  The validated data.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        parent::postSaveHook($model, $validData);
        $this->captureAutosaveCanonicalResult($model, 'link.id');
    }
}
