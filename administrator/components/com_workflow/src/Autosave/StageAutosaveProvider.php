<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_workflow
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftModel;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Draft fields, scope and access checks for the Stage form.
 *
 * @since  __DEPLOY_VERSION__
 */
final class StageAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'description'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_workflow.stage';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $scope = $this->scope($item);
        $this->authorize($user, $item, $scope);

        return DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            empty($item->id) ? null : (string) $item->id,
            $scope,
            self::KEYS,
            []
        );
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== ['extension', 'workflow_id']) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }
        $model = $this->factory->createModel('Stage', 'Administrator', ['ignore_request' => true]);
        $model->setState('stage.id', $target === null ? 0 : (int) $target);
        $model->setState('filter.extension', $binding['scope']['extension']);
        $model->setState('filter.workflow_id', (int) $binding['scope']['workflow_id']);
        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }
        if ($target === null) {
            $item->workflow_id = (int) $binding['scope']['workflow_id'];
        }
        $this->authorize($user, $item, $binding['scope']);
        $form       = DraftModel::form($model, 'com_workflow', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope'], 'The native identity or owning relation changed.');
        return $descriptor;
    }

    private function scope(object $item): array
    {
        $workflowId = (int) ($item->workflow_id ?? 0);
        $workflow   = $this->factory->createModel('Workflow', 'Administrator', ['ignore_request' => true])->getItem($workflowId);
        if ($workflowId <= 0 || !$workflow || (int) $workflow->id !== $workflowId) {
            throw new AutosaveException('target_not_found', 'The owning workflow was not found.');
        }
        $extension = (string) $workflow->extension;
        DraftModel::extension($extension, $item->draftExtension ?? null);
        return ['extension' => $extension, 'workflow_id' => (string) $workflowId];
    }

    private function authorize(User $user, object $item, array $scope): void
    {
        $component = explode('.', $scope['extension'])[0];
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage.workflow', $component)) {
            throw new AutosaveException('forbidden', 'The native component access is denied.');
        }

        if (
            !(empty($item->id) ? $user->authorise('core.create', $component . '.workflow.' . $scope['workflow_id']) : ($user->authorise('core.edit', $component . '.stage.' . (int) $item->id)
            || (isset($item->created_by) && $user->authorise('core.edit.own', $component . '.stage.' . (int) $item->id) && (int) $item->created_by === (int) $user->id)))
        ) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user, 'The record is checked out by another user.');
    }
}
