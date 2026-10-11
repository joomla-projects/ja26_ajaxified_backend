<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_banners
 *
 * @copyright   (C) 2006 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Banners\Administrator\Controller;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Versioning\VersionableControllerTrait;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Client controller class.
 *
 * @since  1.6
 */
class ClientController extends FormController
{
    use AutosaveFormControllerTrait;
    use VersionableControllerTrait;

    private const AUTOSAVE_CONTEXT      = 'com_banners.client';
    private const AUTOSAVE_TASK_INTENTS = [
        'apply'     => 'apply',
        'save'      => 'save-exit',
        'save2new'  => 'save-new',
        'save2copy' => 'save-copy',
    ];

    /**
     * The prefix to use with controller messages.
     *
     * @var    string
     * @since  1.6
     */
    protected $text_prefix = 'COM_BANNERS_CLIENT';

    /**
     * Capture the authoritative Client identity after Joomla saves it.
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
        $this->captureAutosaveCanonicalResult($model, 'client.id');
    }
}
