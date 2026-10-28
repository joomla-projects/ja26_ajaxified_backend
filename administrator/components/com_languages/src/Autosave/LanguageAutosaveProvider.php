<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_languages
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Languages\Administrator\Autosave;

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
 * Draft fields and access checks for the Language form.
 */
final class LanguageAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'title_native', 'description', 'metadesc', 'sitename'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_languages.language';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $target = empty($item->lang_id) ? null : (string) $item->lang_id;
        $this->authorize($user, $item);
        return DraftProjection::descriptor(
            $form,
            $item,
            $this->getContext(),
            $target,
            ['lang_code' => (string) ($item->lang_code ?? $form->getValue('lang_code', null, ''))],
            self::KEYS,
            [$item->lang_code ?? null]
        );
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== ['lang_code']) {
            throw new AutosaveException('scope_conflict', 'The language scope changed.');
        }
        $model = $this->factory->createModel('Language', 'Administrator', ['ignore_request' => true]);
        $model->setState('language.id', $target === null ? 0 : (int) $target);

        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->lang_id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }
        if ($target === null) {
            $item->lang_code = $binding['scope']['lang_code'];
        }
        $this->authorize($user, $item);
        $form       = DraftModel::form($model, 'com_languages', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope']);
        return $descriptor;
    }

    private function authorize(User $user, object $item): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_languages')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        $allowed = empty($item->lang_id) ? ($user->authorise('core.create', 'com_languages') || \count($user->getAuthorisedCategories('com_languages', 'core.create')) > 0) : $user->authorise('core.edit', 'com_languages');
        if (!$allowed) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user);
    }
}
