<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reconcile prepared Autosave operations around a FormController save.
 *
 * Consuming controllers must define AUTOSAVE_CONTEXT and
 * AUTOSAVE_TASK_INTENTS, and capture the saved model identity from their
 * postSaveHook with captureAutosaveCanonicalResult().
 *
 * @since  __DEPLOY_VERSION__
 */
trait AutosaveFormControllerTrait
{
    /**
     * Verified action awaiting the authoritative post-save model.
     *
     * @var array{service: AutosaveCanonicalActionServiceInterface, operationId: string, targetId: int, intent: string}|null
     *
     * @since  __DEPLOY_VERSION__
     */
    private ?array $autosaveCanonicalAction = null;

    /**
     * Authoritative identity captured from the exact model Joomla saved.
     *
     * @var int|null
     *
     * @since  __DEPLOY_VERSION__
     */
    private ?int $autosaveCanonicalResultId = null;

    /**
     * Reconcile a prepared Autosave generation around Joomla's canonical save.
     *
     * Requests without Autosave metadata retain the ordinary controller path.
     *
     * @param   string  $key     The name of the primary key of the URL variable.
     * @param   string  $urlVar  The name of the URL variable if different from the primary key.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function save($key = null, $urlVar = null)
    {
        return $this->executeAutosaveCanonicalSave(
            fn () => parent::save($key, $urlVar),
            $urlVar
        );
    }

    /**
     * Reconcile Autosave around one component-owned native save workflow.
     *
     * The callable must execute the complete authoritative native operation,
     * including captureAutosaveCanonicalResult() after successful persistence.
     * It is invoked at most once and its boolean result is returned unchanged.
     *
     * @param   callable(): boolean  $nativeSave  The authoritative native save workflow.
     * @param   string|null          $urlVar      The native route identity variable.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function executeAutosaveCanonicalSave(callable $nativeSave, ?string $urlVar = null): bool
    {
        $operationId = $this->input->post->getString('autosave_operation_id', '');
        $intent      = $this->input->post->getString('autosave_operation_intent', '');

        if ($operationId === '' && $intent === '') {
            return $nativeSave();
        }

        $task           = $this->input->getCmd('task', '');
        $taskAction     = str_contains($task, '.') ? substr($task, strrpos($task, '.') + 1) : $task;
        $expectedIntent = self::AUTOSAVE_TASK_INTENTS[$taskAction] ?? null;

        if ($expectedIntent === null) {
            return $nativeSave();
        }

        // Match FormController's authoritative route identity. A Joomla form
        // is not required to render jform[id], and a posted form value must
        // never select the record bound to a prepared Autosave action.
        $targetId       = $this->input->getInt($urlVar ?: 'id');
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
            $result = $nativeSave();
        } catch (\Throwable $exception) {
            $this->autosaveCanonicalAction   = null;
            $this->autosaveCanonicalResultId = null;

            // The canonical result is unknown. Keep the operation pending and
            // never replay persistence from Autosave reconciliation.
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

        return $result;
    }

    /**
     * Capture the authoritative identity from the exact model Joomla saved.
     *
     * @param   BaseDatabaseModel  $model     The saved model.
     * @param   string             $stateKey  The component model identity state key.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function captureAutosaveCanonicalResult(BaseDatabaseModel $model, string $stateKey): void
    {
        if ($this->autosaveCanonicalAction === null) {
            return;
        }

        $this->autosaveCanonicalResultId = (int) $model->getState($stateKey);
    }

    /**
     * Retire the generation only after Joomla's canonical save returned true.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
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
                throw new \RuntimeException('The canonical item identity is unavailable.');
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
            // Persistence has succeeded. Retirement failure must never replay
            // or roll back Joomla's authoritative canonical save.
            $this->getLogger()->warning(
                'Canonical save succeeded but Autosave retirement remains pending.',
                ['category' => 'autosave']
            );
        }
    }
}
