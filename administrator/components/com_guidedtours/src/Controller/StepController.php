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
 * Controller for a single step
 *
 * @since 4.3.0
 */
class StepController extends FormController
{
    use AutosaveFormControllerTrait;

    private const AUTOSAVE_CONTEXT      = 'com_guidedtours.step';
    private const AUTOSAVE_TASK_INTENTS = [
        'apply'     => 'apply',
        'save'      => 'save-exit',
        'save2new'  => 'save-new',
        'save2copy' => 'save-copy',
    ];

    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        $this->captureAutosaveCanonicalResult($model, 'step.id');
    }

    /**
     * Gets the URL arguments to append to a list redirect.
     *
     * @return  string  The arguments to append to the redirect URL.
     *
     * @since  5.2.2
     */
    protected function getRedirectToListAppend()
    {
        $append = parent::getRedirectToListAppend();
        $tourId = $this->app->getUserState('com_guidedtours.tour_id');
        if (!empty($tourId)) {
            $append .= '&tour_id=' . $tourId;
        }

        return $append;
    }
}
