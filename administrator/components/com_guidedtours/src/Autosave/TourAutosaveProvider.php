<?php

/**
 * @package    Joomla.Administrator
 * @subpackage com_guidedtours
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Guidedtours\Administrator\Autosave;

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
 * Draft fields and access checks for the Tour form.
 */
final class TourAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'uid', 'description', 'note', 'url', 'autostart'];

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
        return 'com_guidedtours.tour';
    }

    /**
     * @inheritDoc
     */
    public function describe(Form $form, object $item, User $user): array
    {
        $target = empty($item->id) ? null : (string) $item->id;
        $this->authorize($user, $item);
        return DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            $target,
            [],
            self::KEYS,
            [$item->version ?? null, $item->modified ?? null, $item->modified_by ?? null]
        );
    }

    /**
     * @inheritDoc
     */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if ($binding['scope'] !== []) {
            throw new AutosaveException('scope_conflict', 'The component scope changed.');
        }
        $model = $this->factory->createModel('Tour', 'Administrator', ['ignore_request' => true]);
        $model->setState('tour.id', $target === null ? 0 : (int) $target);

        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $this->authorize($user, $item);
        $form       = DraftModel::form($model, 'com_guidedtours', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope']);
        return $descriptor;
    }

    private function authorize(User $user, object $item): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_guidedtours')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        $allowed = empty($item->id) ? ($user->authorise('core.create', 'com_guidedtours') || \count($user->getAuthorisedCategories('com_guidedtours', 'core.create')) > 0) : $user->authorise('core.edit', 'com_guidedtours');
        if (!$allowed) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user);
    }
}
