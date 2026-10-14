<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_users
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Users\Administrator\Autosave;

use Joomla\CMS\Access\Access;
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
 * Native Level scope, authority and selected values; no canonical persistence.
 *
 * @since  __DEPLOY_VERSION__
 */
final class LevelAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'rules'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_users.level';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $scope = $this->scope($form, $item);
        $this->authorize($user, $item, $scope);
        $keys = self::KEYS;

        $descriptor = DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            empty($item->id) ? null : (string) $item->id,
            $scope,
            $keys,
            [$item->modified ?? null, $item->modified_time ?? null, $item->modified_user_id ?? null]
        );
        $descriptor['fields']['rules'] = ['id' => 'level-rules', 'name' => 'jform[rules][]', 'control' => 'checkboxes', 'label' => 'COM_USERS_USER_GROUPS_HAVING_ACCESS'];
        $rules                         = array_values(array_unique(array_map('strval', $item->rules ?? [])));
        sort($rules, SORT_NUMERIC);
        $descriptor['values']['rules'] = $rules;
        if ($descriptor['target'] !== null) {
            $descriptor['revision'] = hash('sha256', json_encode([$descriptor['context'], $descriptor['target'], $descriptor['scope'], $descriptor['values']], JSON_THROW_ON_ERROR));
        }
        $descriptor['excluded'] = array_values(array_diff($descriptor['excluded'], ['rules']));

        return $descriptor;
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== []) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }
        $model = $this->factory->createModel('Level', 'Administrator', ['ignore_request' => true]);
        $model->setState('level.id', $target === null ? 0 : (int) $target);

        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $this->authorize($user, $item, $binding['scope']);
        $form       = DraftModel::form($model, 'com_users', $item);
        $descriptor = $this->describe($form, $item, $user);
        if ($descriptor['scope'] !== $binding['scope']) {
            throw new AutosaveException('scope_conflict', 'The native identity or owning relation changed.');
        }
        return $descriptor;
    }

    private function scope(Form $form, object $item): array
    {
        return [];
    }

    private function authorize(User $user, object $item, array $scope): void
    {
        $component = 'com_users';
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', $component)) {
            throw new AutosaveException('forbidden', 'The native component access is denied.');
        }
        if (!$user->authorise('core.admin', 'com_users')) {
            throw new AutosaveException('forbidden', 'Native administrator access is denied.');
        }
        foreach ($item->rules ?? [] as $id) {
            if (!$user->authorise('core.admin') && Access::checkGroup((int) $id, 'core.admin')) {
                throw new AutosaveException('forbidden', 'A protected administrator group is unavailable.');
            }
        }
        if (!(empty($item->id) ? ($user->authorise('core.create', $component) || \count($user->getAuthorisedCategories($component, 'core.create')) > 0) : $user->authorise('core.edit', $component))) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        if (!empty($item->checked_out) && (int) $item->checked_out !== (int) $user->id) {
            throw new AutosaveException('checkout_conflict', 'The record is checked out by another user.');
        }
    }
}
