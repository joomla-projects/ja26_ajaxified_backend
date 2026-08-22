<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_content
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Content\Administrator\Controller;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Versioning\VersionableControllerTrait;
use Joomla\Input\Input;
use Joomla\Utilities\ArrayHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The article controller
 *
 * @since  1.6
 */
class ArticleController extends FormController
{
    use VersionableControllerTrait;

    private const AUTOSAVE_CONTEXT         = 'com_content.article';
    private const AUTOSAVE_OPERATION_FIELD = 'autosave_operation_id';
    private const AUTOSAVE_INTENT_FIELD    = 'autosave_operation_intent';
    private const AUTOSAVE_TASK_INTENTS    = [
        'apply'     => 'apply',
        'save'      => 'save-exit',
        'save2new'  => 'save-new',
        'save2copy' => 'save-copy',
    ];

    /**
     * Verified action awaiting the authoritative post-save model.
     *
     * @var array{service: AutosaveCanonicalActionServiceInterface, operationId: string, targetId: int, intent: string}|null
     *
     * @since  __DEPLOY_VERSION__
     */
    private ?array $autosaveCanonicalAction = null;

    /**
     * Authoritative Article identity captured from Joomla's successful save model.
     *
     * @var int|null
     *
     * @since  __DEPLOY_VERSION__
     */
    private ?int $autosaveCanonicalResultId = null;

    /**
     * Constructor.
     *
     * @param   array                 $config   An optional associative array of configuration settings.
     *                                          Recognized key values include 'name', 'default_task', 'model_path', and
     *                                          'view_path' (this list is not meant to be comprehensive).
     * @param   ?MVCFactoryInterface  $factory  The factory.
     * @param   ?CMSApplication       $app      The Application for the dispatcher
     * @param   ?Input                $input    Input
     *
     * @since   3.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);

        // An article edit form can come from the articles or featured view.
        // Adjust the redirect view on the value of 'return' in the request.
        if ($this->input->get('return') == 'featured') {
            $this->view_list = 'featured';
            $this->view_item = 'article&return=featured';
        }
    }

    /**
     * Method to cancel an edit.
     *
     * @param   string  $key  The name of the primary key of the URL variable.
     *
     * @return  boolean  True if access level checks pass, false otherwise.
     *
     * @since   5.0.0
     */
    public function cancel($key = null)
    {
        $result = parent::cancel($key);

        // When editing in modal then redirect to modalreturn layout
        if ($result && $this->input->get('layout') === 'modal') {
            $id     = $this->input->get('id');
            $return = 'index.php?option=' . $this->option . '&view=' . $this->view_item . $this->getRedirectToItemAppend($id)
                . '&layout=modalreturn&from-task=cancel';

            $this->setRedirect(Route::_($return, false));
        }

        return $result;
    }

    /**
     * Reconcile a prepared Autosave generation around Joomla's canonical save.
     *
     * Requests without Autosave metadata retain the ordinary controller path.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function save($key = null, $urlVar = null)
    {
        $operationId = $this->input->post->getString(self::AUTOSAVE_OPERATION_FIELD, '');
        $intent      = $this->input->post->getString(self::AUTOSAVE_INTENT_FIELD, '');
        if ($operationId === '' && $intent === '') {
            return parent::save($key, $urlVar);
        }

        $task           = $this->input->getCmd('task', '');
        $taskAction     = str_contains($task, '.') ? substr($task, strrpos($task, '.') + 1) : $task;
        $expectedIntent = self::AUTOSAVE_TASK_INTENTS[$taskAction] ?? null;
        $data           = $this->input->post->get('jform', [], 'array');
        $targetId       = isset($data['id']) ? (int) $data['id'] : 0;
        $service        = $this->app->bootComponent('com_autosave');
        $now            = new Date('now', 'UTC');
        $this->app->getLanguage()->load('com_autosave', JPATH_ADMINISTRATOR);

        if (
            $operationId === ''
            || $intent === ''
            || $expectedIntent === null
            || $intent !== $expectedIntent
            || $targetId <= 0
            || !$service instanceof AutosaveCanonicalActionServiceInterface
        ) {
            $this->setMessage(Text::_('COM_AUTOSAVE_CANONICAL_ACTION_INVALID'), 'error');
            if ($targetId > 0) {
                $this->setRedirect($this->getRedirectUrlToItem($targetId));
            }

            return false;
        }

        try {
            $service->verifyCanonicalAction(
                $this->app->getIdentity(),
                $operationId,
                self::AUTOSAVE_CONTEXT,
                (string) $targetId,
                $intent,
                $now
            );
        } catch (\Throwable $exception) {
            try {
                $service->finalizeCanonicalActionFailure(
                    $this->app->getIdentity(),
                    $operationId,
                    self::AUTOSAVE_CONTEXT,
                    (string) $targetId,
                    $intent,
                    'canonical_verification_failed',
                    new Date('now', 'UTC')
                );
            } catch (\Throwable) {
                // Invalid or foreign metadata remains private and unmodified.
            }

            $this->setMessage(Text::_('COM_AUTOSAVE_CANONICAL_ACTION_UNVERIFIED'), 'error');
            $this->setRedirect($this->getRedirectUrlToItem($targetId));

            return false;
        }

        $this->autosaveCanonicalAction = [
            'service'     => $service,
            'operationId' => $operationId,
            'targetId'    => $targetId,
            'intent'      => $intent,
        ];
        $this->autosaveCanonicalResultId = null;
        try {
            $result = parent::save($key, $urlVar);
        } catch (\Throwable $exception) {
            $this->autosaveCanonicalAction   = null;
            $this->autosaveCanonicalResultId = null;

            // An exception after model persistence has an unknown canonical
            // outcome. Keep the operation pending and the generation closed.
            throw $exception;
        }

        if (!$result) {
            $this->autosaveCanonicalAction   = null;
            $this->autosaveCanonicalResultId = null;
            try {
                $service->finalizeCanonicalActionFailure(
                    $this->app->getIdentity(),
                    $operationId,
                    self::AUTOSAVE_CONTEXT,
                    (string) $targetId,
                    $intent,
                    'canonical_save_failed',
                    new Date('now', 'UTC')
                );
            } catch (\Throwable $exception) {
                $this->getLogger()->warning(
                    'Failed to record a definitive Autosave canonical action failure.',
                    ['category' => 'autosave']
                );
            }

            return false;
        }

        $this->finalizeAutosaveCanonicalSuccess();

        return true;
    }

    /**
     * Function that allows child controller access to model data
     * after the data has been saved.
     *
     * @param   BaseDatabaseModel  $model      The data model object.
     * @param   array              $validData  The validated data.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        if ($this->getTask() === 'save2menu') {
            $editState = [];

            $id = $model->getState('article.id');

            $link = 'index.php?option=com_content&view=article';
            $type = 'component';

            $editState['link']          = $link;
            $editState['title']         = $model->getItem($id)->title;
            $editState['type']          = $type;
            $editState['request']['id'] = $id;

            $this->app->setUserState('com_menus.edit.item', [
                'data' => $editState,
                'type' => $type,
                'link' => $link,
            ]);

            $this->setRedirect(Route::_('index.php?option=com_menus&view=item&client_id=0&menutype=mainmenu&layout=edit', false));
        } elseif ($this->input->get('layout') === 'modal' && $this->task === 'save') {
            // When editing in modal then redirect to modalreturn layout
            $id     = $model->getState('article.id', '');
            $return = 'index.php?option=' . $this->option . '&view=' . $this->view_item . $this->getRedirectToItemAppend($id)
                . '&layout=modalreturn&from-task=save';

            $this->setRedirect(Route::_($return, false));
        }

        $this->captureAutosaveCanonicalResult($model);
    }

    /**
     * Capture the authoritative identity from the exact model Joomla saved.
     *
     * Retirement is deliberately deferred until the parent save method has
     * completed every remaining Joomla controller success stage.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function captureAutosaveCanonicalResult(BaseDatabaseModel $model): void
    {
        if ($this->autosaveCanonicalAction === null) {
            return;
        }

        $this->autosaveCanonicalResultId = (int) $model->getState('article.id');
    }

    /**
     * Retire the submitted generation after Joomla's parent save returned true.
     *
     * Finalization cannot change or repeat an already successful canonical save.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function finalizeAutosaveCanonicalSuccess(): void
    {
        if ($this->autosaveCanonicalAction === null) {
            return;
        }

        $action                          = $this->autosaveCanonicalAction;
        $resultingId                     = $this->autosaveCanonicalResultId;
        $this->autosaveCanonicalAction   = null;
        $this->autosaveCanonicalResultId = null;
        try {
            if ($resultingId === null || $resultingId <= 0) {
                throw new \RuntimeException('The canonical Article identity is unavailable.');
            }

            $action['service']->finalizeCanonicalActionSuccess(
                $this->app->getIdentity(),
                $action['operationId'],
                self::AUTOSAVE_CONTEXT,
                (string) $action['targetId'],
                $action['intent'],
                (string) $resultingId,
                new Date('now', 'UTC')
            );
        } catch (\Throwable $exception) {
            // Canonical persistence has already succeeded. Never repeat or
            // roll it back because retirement needs later reconciliation.
            $this->getLogger()->warning(
                'Canonical Article save succeeded but Autosave retirement remains pending.',
                ['category' => 'autosave']
            );
        }
    }

    /**
     * Method override to check if you can add a new record.
     *
     * @param   array  $data  An array of input data.
     *
     * @return  boolean
     *
     * @since   1.6
     */
    protected function allowAdd($data = [])
    {
        $categoryId = ArrayHelper::getValue($data, 'catid', $this->input->getInt('filter_category_id'), 'int');

        if ($categoryId) {
            // If the category has been passed in the data or URL check it.
            return $this->app->getIdentity()->authorise('core.create', 'com_content.category.' . $categoryId);
        }

        // In the absence of better information, revert to the component permissions.
        return parent::allowAdd();
    }

    /**
     * Method override to check if you can edit an existing record.
     *
     * @param   array   $data  An array of input data.
     * @param   string  $key   The name of the key for the primary key.
     *
     * @return  boolean
     *
     * @since   1.6
     */
    protected function allowEdit($data = [], $key = 'id')
    {
        $recordId = isset($data[$key]) ? (int) $data[$key] : 0;
        $user     = $this->app->getIdentity();

        // Zero record (id:0), return component edit permission by calling parent controller method
        if (!$recordId) {
            return parent::allowEdit($data, $key);
        }

        // Check edit on the record asset (explicit or inherited)
        if ($user->authorise('core.edit', 'com_content.article.' . $recordId)) {
            return true;
        }

        // Check edit own on the record asset (explicit or inherited)
        if ($user->authorise('core.edit.own', 'com_content.article.' . $recordId)) {
            // Existing record already has an owner, get it
            $record = $this->getModel()->getItem($recordId);

            if (empty($record)) {
                return false;
            }

            // Grant if current user is owner of the record
            return $user->id == $record->created_by;
        }

        return false;
    }

    /**
     * Method to run batch operations.
     *
     * @param   object  $model  The model.
     *
     * @return  boolean   True if successful, false otherwise and internal error is set.
     *
     * @since   1.6
     */
    public function batch($model = null)
    {
        $this->checkToken();

        // Set the model
        /** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
        $model = $this->getModel('Article', 'Administrator', []);

        // Preset the redirect
        $this->setRedirect(Route::_('index.php?option=com_content&view=articles' . $this->getRedirectToListAppend(), false));

        return parent::batch($model);
    }
}
