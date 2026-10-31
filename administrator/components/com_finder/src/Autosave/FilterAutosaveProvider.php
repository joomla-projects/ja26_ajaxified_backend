<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_finder
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Finder\Administrator\Autosave;

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
 * Native Filter identity, prepared form and selected private values.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FilterAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'alias', 'created', 'created_by', 'created_by_alias', 'state', 'params.w1', 'params.d1', 'params.w2', 'params.d2', 't[]'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_finder.filter';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $this->authorize($user, $item);
        $item          = clone $item;
        $item->{'t[]'} = array_values(array_unique(array_map('strval', \is_array($item->data ?? null) ? $item->data : array_filter(explode(',', (string) ($item->data ?? ''))))));
        sort($item->{'t[]'}, SORT_NUMERIC);

        $descriptor = DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            empty($item->filter_id) ? null : (string) $item->filter_id,
            [],
            self::KEYS,
            [$item->modified ?? null, $item->modified_by ?? null]
        );
        $descriptor['fields']['t[]'] = ['id' => 'finder-taxonomy', 'name' => 't[]', 'control' => 'checkboxes', 'label' => 'COM_FINDER_EDIT_FILTER'];
        $descriptor['excluded']      = array_values(array_diff($descriptor['excluded'], ['t[]']));
        $descriptor['values']['t[]'] = $item->{'t[]'};
        if ($descriptor['target'] !== null) {
            $descriptor['revision'] = hash('sha256', json_encode([$descriptor['revision'], $item->{'t[]'}], JSON_THROW_ON_ERROR));
        }

        return $descriptor;
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== []) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }

        $model = $this->factory->createModel('Filter', 'Administrator', ['ignore_request' => true]);
        $model->setState('filter.id', $target === null ? 0 : (int) $target);

        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->filter_id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $this->authorize($user, $item);
        $form       = DraftModel::form($model, 'com_finder', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope'], 'The native extension, route or client changed.');
        return $descriptor;
    }

    private function authorize(User $user, object $item): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_finder')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        if (!(empty($item->filter_id) ? ($user->authorise('core.create', 'com_finder') || \count($user->getAuthorisedCategories('com_finder', 'core.create')) > 0) : $user->authorise('core.edit', 'com_finder'))) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user);
    }
}
