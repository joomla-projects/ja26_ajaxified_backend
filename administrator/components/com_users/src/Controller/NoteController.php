<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_users
 *
 * @copyright   (C) 2011 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Users\Administrator\Controller;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Versioning\VersionableControllerTrait;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * User note controller class.
 *
 * @since  2.5
 */
class NoteController extends FormController
{
    use AutosaveFormControllerTrait;
    use VersionableControllerTrait;


    private const AUTOSAVE_CONTEXT      = 'com_users.note';
    private const AUTOSAVE_TASK_INTENTS = ['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new', 'save2copy' => 'save-copy'];
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        $this->captureAutosaveCanonicalResult($model, 'note.id');
    }
    /**
     * The prefix to use with controller messages.
     *
     * @var    string
     * @since  2.5
     */
    protected $text_prefix = 'COM_USERS_NOTE';

    /**
     * Gets the URL arguments to append to an item redirect.
     *
     * @param   integer  $recordId  The primary key id for the item.
     * @param   string   $key       The name of the primary key variable.
     *
     * @return  string  The arguments to append to the redirect URL.
     *
     * @since   2.5
     */
    protected function getRedirectToItemAppend($recordId = null, $key = 'id')
    {
        $append = parent::getRedirectToItemAppend($recordId, $key);

        $userId = $this->input->get('u_id', 0, 'int');

        if ($userId) {
            $append .= '&u_id=' . $userId;
        }

        return $append;
    }
}
