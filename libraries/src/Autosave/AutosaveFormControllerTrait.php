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
     * @var array{service: AutosaveCanonicalActionServiceInterface, operationId: string, targetId: string, intent: string}|null
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
    private int|string|null $autosaveCanonicalResultId = null;

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
            $urlVar,
            $key
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
     * @param   string|null          $key         The native table primary-key field.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function executeAutosaveCanonicalSave(
        callable $nativeSave,
        ?string $urlVar = null,
        ?string $key = null
    ): bool {
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

        // Match FormController's route and table identities without allowing
        // its integer default/filtering to collapse missing or malformed input
        // into a genuine create request.
        $routeIdentity  = $this->classifyAutosaveNumericIdentity($this->input->get($urlVar ?: 'id', null, 'raw'));
        $routeId        = $routeIdentity['id'];
        $targetId       = $routeIdentity['state'] === 'positive' ? (string) $routeId : '';
        $submitted      = $this->input->post->get('jform', [], 'array');
        $primaryKey     = $this->resolveAutosavePrimaryKey($key);
        $submittedValue = \is_array($submitted) && $primaryKey !== null && \array_key_exists($primaryKey, $submitted)
            ? $submitted[$primaryKey]
            : null;
        $submittedIdentity = $this->classifyAutosaveNumericIdentity($submittedValue);
        $identityMatches   = $primaryKey !== null
            && $this->autosaveCanonicalIdentitiesMatch($routeIdentity, $submittedIdentity);
        $service        = $this->app->bootComponent('com_autosave');
        $now            = new Date('now', 'UTC');
        $this->app->getLanguage()->load('com_autosave', JPATH_ADMINISTRATOR);

        if (
            $operationId === ''
            || $intent === ''
            || $expectedIntent === null
            || $intent !== $expectedIntent
            || !$identityMatches
            || !$service instanceof AutosaveCanonicalActionServiceInterface
            || ($routeIdentity['state'] === 'zero' && !$service instanceof AutosaveCreateCanonicalActionServiceInterface)
        ) {
            $this->setMessage(Text::_('COM_AUTOSAVE_CANONICAL_ACTION_INVALID'), 'error');

            if ($targetId !== '') {
                $this->setAutosaveCanonicalFailureRedirect($targetId);
            }

            return false;
        }

        try {
            if ($routeIdentity['state'] === 'zero') {
                $verified = $service->verifyCreateCanonicalAction(
                    $this->app->getIdentity(),
                    $operationId,
                    self::AUTOSAVE_CONTEXT,
                    $intent,
                    $now
                );
                $targetId = AutosaveTargetIdentity::requireProvisional($verified['target_id'] ?? '');
            } else {
                $service->verifyCanonicalAction(
                    $this->app->getIdentity(),
                    $operationId,
                    self::AUTOSAVE_CONTEXT,
                    $targetId,
                    $intent,
                    $now
                );
            }
        } catch (\Throwable $exception) {
            try {
                $service->finalizeCanonicalActionFailure(
                    $this->app->getIdentity(),
                    $operationId,
                    self::AUTOSAVE_CONTEXT,
                    $targetId,
                    $intent,
                    'canonical_verification_failed',
                    new Date('now', 'UTC')
                );
            } catch (\Throwable) {
                // Invalid or foreign metadata remains private and unmodified.
            }

            $this->setMessage(Text::_('COM_AUTOSAVE_CANONICAL_ACTION_UNVERIFIED'), 'error');
            $this->setAutosaveCanonicalFailureRedirect($targetId);

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
                    $targetId,
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

    /** Resolve FormController's native table primary-key field. */
    private function resolveAutosavePrimaryKey(?string $key): ?string
    {
        if ($key !== null && $key !== '') {
            return $key;
        }

        try {
            $resolved = $this->getModel()->getTable()->getKeyName();
        } catch (\Throwable) {
            return null;
        }

        return \is_string($resolved) && $resolved !== '' ? $resolved : null;
    }

    /** Classify a raw Joomla route or submitted table identity without coercion. */
    private function classifyAutosaveNumericIdentity(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['state' => 'missing', 'id' => null];
        }

        if (\is_int($value)) {
            return $value === 0
                ? ['state' => 'zero', 'id' => 0]
                : ($value > 0 ? ['state' => 'positive', 'id' => $value] : ['state' => 'malformed', 'id' => null]);
        }

        if (!\is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return ['state' => 'malformed', 'id' => null];
        }

        if ($value === '0') {
            return ['state' => 'zero', 'id' => 0];
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return \is_int($id)
            ? ['state' => 'positive', 'id' => $id]
            : ['state' => 'malformed', 'id' => null];
    }

    /** Require route and submitted identities to describe the same native operation. */
    private function autosaveCanonicalIdentitiesMatch(array $route, array $submitted): bool
    {
        if ($route['state'] === 'zero') {
            return $submitted['state'] === 'missing' || $submitted['state'] === 'zero';
        }

        if ($route['state'] !== 'positive') {
            return false;
        }

        return $submitted['state'] === 'missing'
            || ($submitted['state'] === 'positive' && $submitted['id'] === $route['id']);
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

        $id                              = (int) $model->getState($stateKey);
        $this->autosaveCanonicalResultId = $id > 0 ? $id : null;
    }

    /** Resolve the existing native route target. */
    protected function resolveAutosaveCanonicalTarget(?string $urlVar): string
    {
        $id = $this->input->getInt($urlVar ?: 'id');

        return $id > 0 ? (string) $id : '';
    }

    /** Capture a component-authoritative canonical target after persistence. */
    protected function captureAutosaveCanonicalTarget(string $targetId): void
    {
        if ($this->autosaveCanonicalAction !== null) {
            $this->autosaveCanonicalResultId = $targetId;
        }
    }

    /** Preserve the native edit redirect when canonical metadata is rejected. */
    protected function setAutosaveCanonicalFailureRedirect(string $targetId): void
    {
        $this->setRedirect($this->getRedirectUrlToItem((int) $targetId));
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
            if ($resultingId === null || $resultingId === '' || $resultingId === 0) {
                throw new \RuntimeException('The canonical item identity is unavailable.');
            }

            $action['service']->finalizeCanonicalActionSuccess(
                $this->app->getIdentity(),
                $action['operationId'],
                self::AUTOSAVE_CONTEXT,
                $action['targetId'],
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
