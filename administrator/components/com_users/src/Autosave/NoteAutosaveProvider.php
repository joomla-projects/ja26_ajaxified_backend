<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_users
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Users\Administrator\Autosave;

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
 * Native Note scope, authority and selected values; no canonical persistence.
 *
 * @since  __DEPLOY_VERSION__
 */
final class NoteAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['subject', 'body'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_users.note';
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


        return $descriptor;
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== ['user_id', 'catid']) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }
        $model = $this->factory->createModel('Note', 'Administrator', ['ignore_request' => true]);
        $model->setState('note.id', $target === null ? 0 : (int) $target);
        $model->setState('note.user_id', (int) $binding['scope']['user_id']);
        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }
        if ($target === null) {
            $item->user_id = (int) $binding['scope']['user_id'];
            $item->catid   = (int) $binding['scope']['catid'];
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
        return ['user_id' => (string) ($item->user_id ?? 0), 'catid' => (string) ($item->catid ?? 0)];
    }

    private function authorize(User $user, object $item, array $scope): void
    {
        $component = 'com_users';
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', $component)) {
            throw new AutosaveException('forbidden', 'The native component access is denied.');
        }

        if (!(empty($item->id) ? ($user->authorise('core.create', $component) || \count($user->getAuthorisedCategories($component, 'core.create')) > 0) : $user->authorise('core.edit', $component))) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        if (!empty($item->checked_out) && (int) $item->checked_out !== (int) $user->id) {
            throw new AutosaveException('checkout_conflict', 'The record is checked out by another user.');
        }
    }
}
