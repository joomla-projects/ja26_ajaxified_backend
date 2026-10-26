<?php

/**
 * @package    Joomla.Administrator
 * @subpackage com_menus
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
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
 * Native Item identity, prepared form and selected private values.
 *
 * @since __DEPLOY_VERSION__
 */
final class ItemAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'alias', 'note', 'browserNav'];

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
        return 'com_menus.item';
    }

    /**
     * @inheritDoc
     */
    public function describe(Form $form, object $item, User $user): array
    {
        $scope = $this->scope($item);
        $this->authorize($user, $item, $scope);

        $keys       = array_merge(self::KEYS, DraftProjection::groupKeys($form, 'params'));
        $descriptor = DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            empty($item->id) ? null : (string) $item->id,
            $scope,
            $keys,
            []
        );

        $descriptor['unsupported'] = array_values(array_diff(DraftProjection::groupKeys($form, 'params', false), array_keys($descriptor['fields'])));
        return $descriptor;
    }

    /**
     * @inheritDoc
     */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== ['menutype', 'menu_id', 'client_id', 'type', 'link']) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }

        $model = $this->factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
        $model->setState('item.id', $target === null ? 0 : (int) $target);
        $model->setState('item.menutype', $binding['scope']['menutype']);
        $model->setState('item.menutypeid', (int) $binding['scope']['menu_id']);
        $model->setState('item.client_id', (int) $binding['scope']['client_id']);
        if ($target === null) {
            $model->setState('item.type', $binding['scope']['type']);
            $model->setState('item.link', $binding['scope']['link']);
        }
        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $this->authorize($user, $item, $binding['scope']);
        $form       = DraftModel::form($model, 'com_menus', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope'], 'The native extension, route or client changed.');
        return $descriptor;
    }

    private function scope(object $item): array
    {
        $menutype = (string) ($item->menutype ?? '');
        if ($menutype === '' && empty($item->id) && \in_array((int) $item->client_id, [0, 1], true)) {
            // Native ItemController.allowAdd authorizes an incomplete creation on menu.0.
            return ['menutype' => '', 'menu_id' => '0', 'client_id' => (string) (int) $item->client_id,
                'type'         => (string) $item->type, 'link' => (string) $item->link];
        }
        if ($menutype === '' || $menutype === 'main') {
            throw new AutosaveException('scope_conflict', 'The native menu type is unavailable for editing.');
        }
        $model = $this->factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
        $table = $model->getTable('MenuType');
        if (!$table->load(['menutype' => $menutype]) || empty($table->id) || (int) $table->client_id !== (int) $item->client_id) {
            throw new AutosaveException('scope_conflict', 'The native menu identity changed.');
        }
        return ['menutype' => $menutype, 'menu_id' => (string) $table->id, 'client_id' => (string) $table->client_id,
            'type'         => (string) $item->type, 'link' => (string) $item->link];
    }

    private function authorize(User $user, object $item, array $scope): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_menus')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        if (!(empty($item->id) ? $user->authorise('core.create', 'com_menus.menu.' . $scope['menu_id']) : $user->authorise('core.edit', 'com_menus.menu.' . $scope['menu_id']))) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user);
    }
}
