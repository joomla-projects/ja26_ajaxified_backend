<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_content
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Content\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Article drafts use the native prepared form, ArticleModel read and component permissions.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ArticleAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['title', 'alias', 'articletext', 'catid'];

    /**
     * Constructor.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(private readonly MVCFactoryInterface $factory)
    {
    }

    /**
     * @inheritDoc
     */
    public function getContext(): string
    {
        return 'com_content.article';
    }

    /**
     * @inheritDoc
     */
    public function describe(Form $form, object $item, User $user): array
    {
        $target = empty($item->id) ? null : (string) $item->id;
        $this->authorize($user, $item, $target === null ? (string) $form->getValue('catid', null, '') : null);
        $fields = DraftProjection::describe($form, self::KEYS);

        return [
            'context'  => $this->getContext(),
            'target'   => $target,
            'scope'    => [],
            'version'  => 1,
            'fields'   => $fields,
            'revision' => $target === null ? null : $this->revision($item),
            'values'   => $this->values($item),
            'excluded' => array_values(array_diff(self::KEYS, array_keys($fields))),
        ];
    }

    /**
     * @inheritDoc
     */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        if ($binding['context'] !== $this->getContext() || $binding['scope'] !== []) {
            throw new AutosaveException('scope_conflict', 'The Article draft binding is incompatible.');
        }

        $target = $binding['target_id'];

        if ($target !== null && (preg_match('/^[1-9][0-9]*$/D', $target) !== 1 || (int) $target > 4294967295)) {
            throw new AutosaveException('invalid_target', 'The Article identity is invalid.');
        }

        $model = $this->factory->createModel('Article', 'Administrator', ['ignore_request' => true]);
        $item  = $model->getItem($target === null ? 0 : (int) $target);

        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The Article was not found.');
        }

        $category = $payload['catid'] ?? '';
        $this->authorize($user, $item, $target === null ? (string) $category : null);

        // Seed this native model explicitly, without validation-error or list-filter user state.
        $model->setState('article.id', $target === null ? 0 : (int) $target);
        $data = (array) $item;

        if ($target === null) {
            $data['catid'] = $category;
        }

        // Draft requests run in com_autosave; retain the native Article form and preparation.
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_content/forms');
        $form = $model->getForm($data, false);

        if (!$form) {
            throw new AutosaveException('unsupported_context', 'The native Article form is unavailable.');
        }

        $form->bind($data);

        return $this->describe($form, $item, $user);
    }

    private function authorize(User $user, object $item, ?string $category): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_content')) {
            throw new AutosaveException('forbidden', 'Article draft access is denied.');
        }

        if (empty($item->id)) {
            // An incomplete category is permitted. Native Save owns final relationship validation.
            if ($category !== '' && $category !== '0' && preg_match('/^[1-9][0-9]*$/D', $category) === 1) {
                if ((int) $category > 4294967295 || !$user->authorise('core.create', 'com_content.category.' . $category)) {
                    throw new AutosaveException('forbidden', 'An Article cannot be created in this category.');
                }
            } elseif (
                !$user->authorise('core.create', 'com_content')
                && \count($user->getAuthorisedCategories('com_content', 'core.create')) === 0
            ) {
                throw new AutosaveException('forbidden', 'An Article cannot be created by this user.');
            }

            return;
        }

        $asset = $this->getContext() . '.' . (int) $item->id;

        if (
            !$user->authorise('core.edit', $asset)
            && !($user->authorise('core.edit.own', $asset) && (int) $item->created_by === (int) $user->id)
        ) {
            throw new AutosaveException('forbidden', 'The Article cannot be edited by this user.');
        }

        if (!empty($item->checked_out) && (int) $item->checked_out !== (int) $user->id) {
            throw new AutosaveException('checkout_conflict', 'The Article is checked out by another user.');
        }
    }

    private function values(object $item): array
    {
        $values = [];

        foreach (self::KEYS as $key) {
            // ArticleModel assembles articletext with Joomla's native read-more representation.
            $values[$key] = (string) ($item->$key ?? '');
        }

        return $values;
    }

    private function revision(object $item): string
    {
        return hash('sha256', json_encode(
            [$this->getContext(), (string) $item->id, [], (int) $item->version, $this->values($item)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }
}
