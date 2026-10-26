<?php

/**
 * @package    Joomla.Administrator
 * @subpackage com_fields
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftModel;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Draft fields, scope and access checks for the Field form.
 *
 * @since __DEPLOY_VERSION__
 */
final class FieldAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'name', 'label', 'description', 'default_value', 'note', 'required', 'only_use_in_subform'];

    /**
     * @since __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /**
     * @inheritDoc
     */
    public function getContext(): string
    {
        return 'com_fields.field';
    }

    /**
     * @inheritDoc
     */
    public function describe(Form $form, object $item, User $user): array
    {
        $scope = $this->scope($form, $item);
        $this->authorize($user, $item, $scope);
        $keys       = array_merge(self::KEYS, DraftProjection::groupKeys($form, 'fieldparams'));
        $descriptor = DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            empty($item->id) ? null : (string) $item->id,
            $scope,
            $keys,
            [$item->modified ?? null, $item->modified_time ?? null, $item->modified_user_id ?? null]
        );

        $descriptor['unsupported'] = array_values(array_diff(DraftProjection::groupKeys($form, 'fieldparams', false), array_keys($descriptor['fields'])));
        return $descriptor;
    }

    /**
     * @inheritDoc
     */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== ['context', 'type']) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }
        $model = $this->factory->createModel('Field', 'Administrator', ['ignore_request' => true]);
        $model->setState('field.id', $target === null ? 0 : (int) $target);
        $parts = FieldsHelper::extract($binding['scope']['context']);
        if (!$parts) {
            throw new AutosaveException('scope_conflict', 'The native context changed.');
        }
        $model->setState('field.context', $binding['scope']['context']);
        $model->setState('field.component', $parts[0]);
        $model->setState('field.section', $parts[1] ?? null);
        $model->setState('field.type', $binding['scope']['type']);
        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }
        if ($target === null) {
            $item->context = $binding['scope']['context'];
            $item->type    = $binding['scope']['type'];
        }
        $this->authorize($user, $item, $binding['scope']);
        $form       = DraftModel::form($model, 'com_fields', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope'], 'The native identity or owning relation changed.');
        return $descriptor;
    }

    private function scope(Form $form, object $item): array
    {
        $context = (string) ($item->context ?? '');
        $parts   = FieldsHelper::extract($context);
        if (!$parts || InputFilter::getInstance()->clean($context, 'cmd') !== $context) {
            throw new AutosaveException('scope_conflict', 'The native field context is invalid.');
        }
        if (isset($item->draftContext) && $item->draftContext !== $context) {
            throw new AutosaveException('scope_conflict', 'The prepared field context changed.');
        }
        $type = (string) ($item->type ?? $form->getValue('type', null, ''));
        if ($type === '' || InputFilter::getInstance()->clean($type, 'cmd') !== $type) {
            throw new AutosaveException('scope_conflict', 'The native field type is unavailable.');
        }
        return ['context' => $context, 'type' => $type];
    }

    private function authorize(User $user, object $item, array $scope): void
    {
        $component = FieldsHelper::extract($scope['context'])[0];
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', $component)) {
            throw new AutosaveException('forbidden', 'The native component access is denied.');
        }

        if (
            !(empty($item->id) ? $user->authorise('core.create', $component) : ($user->authorise('core.edit', $component . '.field.' . (int) $item->id)
            || (($user->authorise('core.edit.own', $component . '.field.' . (int) $item->id)) && (int) ($item->created_user_id ?? 0) === (int) $user->id)))
        ) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user, 'The record is checked out by another user.');
    }
}
