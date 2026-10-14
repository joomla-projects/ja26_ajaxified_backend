<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_redirect
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Redirect\Administrator\Autosave;

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

/** Selected values from the native Link form; canonical saving stays native. */
final class LinkAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['old_url', 'new_url', 'comment'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_redirect.link';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $target = empty($item->id) ? null : (string) $item->id;
        $this->authorize($user, $item, 0);
        return DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            $target,
            [],
            self::KEYS,
            [$item->modified_date ?? null]
        );
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if ($binding['scope'] !== []) {
            throw new AutosaveException('scope_conflict', 'The component scope changed.');
        }
        $model = $this->factory->createModel('Link', 'Administrator', ['ignore_request' => true]);
        $model->setState('link.id', $target === null ? 0 : (int) $target);

        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $category = 0;
        $this->authorize($user, $item, $category);
        $data       = [];
        $form       = DraftModel::form($model, 'com_redirect', $item, $data);
        $descriptor = $this->describe($form, $item, $user);
        if ($descriptor['scope'] !== $binding['scope']) {
            throw new AutosaveException('scope_conflict', 'The native form scope changed.');
        }
        return $descriptor;
    }

    private function authorize(User $user, object $item, int $category): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_redirect')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        $allowed = empty($item->id) ? ($user->authorise('core.create', 'com_redirect') || \count($user->getAuthorisedCategories('com_redirect', 'core.create')) > 0) : $user->authorise('core.edit', 'com_redirect');
        if (!$allowed) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        if (!empty($item->checked_out) && (int) $item->checked_out !== (int) $user->id) {
            throw new AutosaveException('checkout_conflict', 'The native record is checked out by another user.');
        }
    }
}
