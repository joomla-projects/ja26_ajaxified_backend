<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_languages
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Languages\Administrator\Controller;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Languages list actions controller.
 *
 * @since  1.6
 */
class LanguageController extends FormController
{
    use AutosaveFormControllerTrait;


    private const AUTOSAVE_CONTEXT      = 'com_languages.language';
    private const AUTOSAVE_TASK_INTENTS = ['apply' => 'apply', 'save' => 'save-exit', 'save2new' => 'save-new'];

    public function save($key = null, $urlVar = null)
    {
        return $this->executeAutosaveCanonicalSave(
            fn () => parent::save($key, $urlVar),
            $urlVar ?: 'lang_id'
        );
    }

    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        $this->captureAutosaveCanonicalResult($model, 'language.id');
    }
    /**
     * Gets the URL arguments to append to an item redirect.
     *
     * @param   int     $recordId  The primary key id for the item.
     * @param   string  $key       The name of the primary key variable.
     *
     * @return  string  The arguments to append to the redirect URL.
     *
     * @since   1.6
     */
    protected function getRedirectToItemAppend($recordId = null, $key = 'lang_id')
    {
        return parent::getRedirectToItemAppend($recordId, $key);
    }
}
