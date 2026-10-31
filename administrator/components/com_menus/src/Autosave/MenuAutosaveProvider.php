<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_menus
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Menus\Administrator\Autosave;

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
 * Native Menu identity, prepared form and selected private values.
 *
 * @since  __DEPLOY_VERSION__
 */
final class MenuAutosaveProvider implements DraftProviderInterface
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
        return 'com_menus.menu';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $scope = $this->scope($item);
        $this->authorize($user, $item);


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
        if (array_keys($binding['scope']) !== ['client_id', 'menutype']) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }

        $model = $this->factory->createModel('Menu', 'Administrator', ['ignore_request' => true]);
        $model->setState('menu.id', $target === null ? 0 : (int) $target);
        $model->setState('client_id', (int) $binding['scope']['client_id']);
        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }
        if ($target === null) {
            $item->client_id = (int) $binding['scope']['client_id'];
            $item->menutype  = $binding['scope']['menutype'];
        }
        $this->authorize($user, $item);
        $form       = DraftModel::form($model, 'com_menus', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope'], 'The native extension, route or client changed.');
        return $descriptor;
    }

    private function scope(object $item): array
    {
        return ['client_id' => (string) $item->client_id, 'menutype' => (string) ($item->menutype ?? '')];
    }

    private function authorize(User $user, object $item): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_menus')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        if (!(empty($item->id) ? ($user->authorise('core.create', 'com_menus') || \count($user->getAuthorisedCategories('com_menus', 'core.create')) > 0) : $user->authorise('core.edit', 'com_menus'))) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user);
    }
}
