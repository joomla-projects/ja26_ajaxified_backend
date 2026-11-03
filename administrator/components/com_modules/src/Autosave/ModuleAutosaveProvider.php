<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_modules
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Modules\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftModel;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Native Module identity, prepared form and selected private values.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ModuleAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'note', 'version_note', 'showtitle', 'position', 'content'];

    /**
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_modules.module';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $scope = $this->scope($item);
        $this->authorize($user, $item);

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

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        $target = DraftModel::target($binding, $this->getContext());
        if (array_keys($binding['scope']) !== ['client_id', 'module', 'extension_id']) {
            throw new AutosaveException('scope_conflict', 'The native scope shape changed.');
        }

        $model = $this->factory->createModel('Module', 'Administrator', ['ignore_request' => true]);
        $model->setState('module.id', $target === null ? 0 : (int) $target);
        $model->setState('extension.id', (int) $binding['scope']['extension_id']);
        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $this->authorize($user, $item);
        $form       = DraftModel::form($model, 'com_modules', $item);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope'], 'The native extension, route or client changed.');
        return $descriptor;
    }

    private function scope(object $item): array
    {
        $client  = (int) $item->client_id;
        $element = (string) $item->module;
        if (!\in_array($client, [0, 1], true) || InputFilter::getInstance()->clean($element, 'cmd') !== $element || $element === '') {
            throw new AutosaveException('scope_conflict', 'The native extension identity is invalid.');
        }
        $extension = ExtensionHelper::getExtensionRecord($element, 'module', $client);
        if (!$extension || !(int) $extension->enabled || empty($item->xml)) {
            throw new AutosaveException('unsupported_context', 'The installed native extension form is unavailable.');
        }
        return ['client_id' => (string) $client, 'module' => $element, 'extension_id' => (string) $extension->extension_id];
    }

    private function authorize(User $user, object $item): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_modules')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        if (!(empty($item->id) ? ($user->authorise('core.create', 'com_modules') || \count($user->getAuthorisedCategories('com_modules', 'core.create')) > 0) : $user->authorise('core.edit', 'com_modules.module.' . (int) $item->id))) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user);
    }
}
