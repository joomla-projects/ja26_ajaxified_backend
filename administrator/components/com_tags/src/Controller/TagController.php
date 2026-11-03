<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_tags
 *
 * @copyright   (C) 2013 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Tags\Administrator\Controller;

use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Versioning\VersionableControllerTrait;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The Tag Controller
 *
 * @since  3.1
 */
class TagController extends FormController
{
    use VersionableControllerTrait;

    /**
     * Method to check if you can add a new record.
     *
     * @param   array  $data  An array of input data.
     *
     * @return  boolean
     *
     * @since   3.1
     */
    protected function allowAdd($data = [])
    {
        return $this->app->getIdentity()->authorise('core.create', 'com_tags');
    }

    /**
     * Method to run batch operations.
     *
     * @param   object  $model  The model.
     *
     * @return  boolean  True if successful, false otherwise and internal error is set.
     *
     * @since   3.1
     */
    public function batch($model = null)
    {
        $this->checkToken();

        // Set the model
        $model = $this->getModel('Tag');

        // Preset the redirect
        $this->setRedirect('index.php?option=com_tags&view=tags');

        return parent::batch($model);
    }
    /**
     * Reconcile private drafts after the authoritative native success hook.
     *
     * @inheritDoc
     * @since  __DEPLOY_VERSION__
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        parent::postSaveHook($model, $validData);

        DraftService::reconcileNativeModelSave($this->app, $this->input, $model, 'com_tags.tag', $this->getTask(), 'id', []);
    }
}
