<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_categories
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Categories\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftModel;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Category drafts reuse native forms and permission rules, without nested-set writes.
 *
 * @since  __DEPLOY_VERSION__
 */
final class CategoryAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'note', 'description', 'version_note', 'metadesc', 'metakey', 'parent_id'];

    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /** @inheritDoc */
    public function getContext(): string
    {
        return 'com_categories.category';
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $extension = $this->extension((string) ($item->extension ?? ''));
        if (isset($item->draftExtension) && $item->draftExtension !== $extension) {
            throw new AutosaveException('scope_conflict', 'The native Category form has a different owning extension.');
        }
        $target    = empty($item->id) ? null : (string) $item->id;
        $this->authorize($user, $item, $extension);
        $fields = DraftProjection::describe($form, self::KEYS);
        $scope  = ['extension' => $extension];

        return [
            'context'  => $this->getContext(),
            'target'   => $target,
            'scope'    => $scope,
            'version'  => 1,
            'fields'   => $fields,
            'revision' => $target === null ? null : hash('sha256', json_encode(
                [$target, $scope, $this->values($item), $item->modified_time ?? null, $item->modified_user_id ?? null],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )),
            'values'   => $this->values($item),
            'excluded' => array_values(array_diff(self::KEYS, array_keys($fields))),
        ];
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        if ($binding['context'] !== $this->getContext() || array_keys($binding['scope']) !== ['extension']) {
            throw new AutosaveException('scope_conflict', 'The Category binding is incompatible.');
        }
        $extension = $this->extension($binding['scope']['extension']);
        $target    = DraftModel::target($binding, $this->getContext());

        $model = $this->factory->createModel('Category', 'Administrator', ['ignore_request' => true]);
        $parts = explode('.', $extension);
        $model->setState('category.extension', $extension);
        $model->setState('category.component', $parts[0]);
        $model->setState('category.section', $parts[1] ?? null);
        $model->setState('category.id', $target === null ? 0 : (int) $target);
        $model->setState('category.parent_id', $target === null ? ($payload['parent_id'] ?? 0) : 0);
        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The Category was not found.');
        }
        if (($item->extension ?? '') !== $extension) {
            throw new AutosaveException('scope_conflict', 'The Category owning extension changed.');
        }
        $this->authorize($user, $item, $extension);
        $form = DraftModel::form($model, 'com_categories', $item);

        return $this->describe($form, $item, $user);
    }

    private function extension(string $extension): string
    {
        $component = explode('.', $extension)[0];
        if (
            !str_starts_with($component, 'com_') || $component === 'com_'
            || InputFilter::getInstance()->clean($extension, 'cmd') !== $extension
        ) {
            throw new AutosaveException('scope_conflict', 'The owning extension is invalid.');
        }

        return $extension;
    }

    private function authorize(User $user, object $item, string $extension): void
    {
        $component = explode('.', $extension)[0];
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', $component)) {
            throw new AutosaveException('forbidden', 'Category draft access is denied.');
        }
        if (empty($item->id)) {
            // CategoryController.allowAdd checks the owning extension, not a guessed parent asset.
            if (!$user->authorise('core.create', $extension) && \count($user->getAuthorisedCategories($extension, 'core.create')) === 0) {
                throw new AutosaveException('forbidden', 'A Category cannot be created by this user.');
            }

            return;
        }
        $asset = $extension . '.category.' . (int) $item->id;
        if (
            !$user->authorise('core.edit', $asset)
            && !($user->authorise('core.edit.own', $asset) && (int) ($item->created_user_id ?? 0) === (int) $user->id)
        ) {
            throw new AutosaveException('forbidden', 'The Category cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user, 'The Category is checked out by another user.');
    }

    private function values(object $item): array
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = (string) ($item->$key ?? '');
        }

        return $values;
    }
}
