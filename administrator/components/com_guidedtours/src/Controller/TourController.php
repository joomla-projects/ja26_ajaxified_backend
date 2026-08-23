<?php

/**
 * @package       Joomla.Administrator
 * @subpackage    com_guidedtours
 *
 * @copyright     (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license       GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Guidedtours\Administrator\Controller;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
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
    use AutosaveFormControllerTrait;

    private const AUTOSAVE_CONTEXT      = 'com_guidedtours.tour';
    private const AUTOSAVE_TASK_INTENTS = [
        'apply'     => 'apply',
        'save'      => 'save-exit',
        'save2new'  => 'save-new',
        'save2copy' => 'save-copy',
    ];

    /**
     * Capture the authoritative Tour identity after Joomla saves it.
     *
     * @param   BaseDatabaseModel  $model      The saved model.
     * @param   array              $validData  The validated form data.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        $this->captureAutosaveCanonicalResult($model, 'tour.id');
    }
}
