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
 * Draft fields, scope and access checks for the Group form.
 *
 * @since  __DEPLOY_VERSION__
 */
final class GroupAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_users.group';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $this->authorize($user, $item);

        return DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            empty($item->id) ? null : (string) $item->id,
            [],
            self::KEYS,
            [(int) ($item->parent_id ?? 0)]
        );
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== []) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }
        $model = $this->factory->createModel('Group', 'Administrator', ['ignore_request' => true]);
        $model->setState('group.id', $target === null ? 0 : (int) $target);

        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $this->authorize($user, $item);
        $form       = DraftModel::form($model, 'com_users', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope'], 'The native identity or owning relation changed.');
        return $descriptor;
    }

    private function authorize(User $user, object $item): void
    {
        $component = 'com_users';
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', $component)) {
            throw new AutosaveException('forbidden', 'The native component access is denied.');
        }
        if (!$user->authorise('core.admin', 'com_users') || (!$user->authorise('core.admin') && !empty($item->id) && Access::checkGroup((int) $item->id, 'core.admin'))) {
            throw new AutosaveException('forbidden', 'Native administrator group access is denied.');
        }
        if (!(empty($item->id) ? ($user->authorise('core.create', $component) || \count($user->getAuthorisedCategories($component, 'core.create')) > 0) : $user->authorise('core.edit', $component))) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user, 'The record is checked out by another user.');
    }
}
