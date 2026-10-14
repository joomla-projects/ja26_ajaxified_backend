<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_languages
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Languages\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Languages\Administrator\Helper\LanguagesHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Private override drafts keep native string identity and file persistence separate.
 *
 * @since  __DEPLOY_VERSION__
 */
final class OverrideAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['key', 'override', 'both'];

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
        return 'com_languages.override';
    }

    /**
     * Validate the native client/language pair before it is used in a file path.
     *
     * @since  __DEPLOY_VERSION__
     */
    public static function scope(string $client, string $language): array
    {
        if (
            !\in_array($client, ['site', 'administrator'], true)
            || InputFilter::getInstance()->clean($language, 'cmd') !== $language
            || !LanguageHelper::exists($language, \constant('JPATH_' . strtoupper($client)))
        ) {
            throw new AutosaveException('scope_conflict', 'The native override scope is unavailable.');
        }

        return ['client' => $client, 'language' => $language];
    }

    /** @inheritDoc */
    public function describe(Form $form, object $item, User $user): array
    {
        $scope  = self::scope((string) ($item->client ?? ''), (string) ($item->language ?? ''));
        $target = (string) ($item->key ?? '');
        $target = $target === '' ? null : $this->key($target);
        $this->authorize($user, $target);
        $fields = DraftProjection::describe($form, self::KEYS);
        // This key-level snapshot was captured by the same native read as the rendered values.
        $canonical = $item->draftRevision ?? null;
        if (
            $target !== null && (!\is_array($canonical) || !\array_key_exists('site', $canonical)
            || !\array_key_exists('administrator', $canonical))
        ) {
            throw new AutosaveException('target_not_found', 'The native override revision is unavailable.');
        }

        return [
            'context'  => $this->getContext(),
            'target'   => $target,
            'scope'    => $scope,
            'version'  => 1,
            'fields'   => $fields,
            'revision' => $target === null ? null : hash('sha256', json_encode(
                [$target, $scope, $canonical],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )),
            'values' => [
                'key'      => (string) ($item->key ?? ''),
                'override' => (string) ($item->override ?? ''),
                'both'     => !empty($item->both) ? '1' : '0',
            ],
            'excluded' => array_values(array_diff(self::KEYS, array_keys($fields))),
        ];
    }

    /** @inheritDoc */
    public function read(array $binding, User $user, ?array $payload = null): array
    {
        if ($binding['context'] !== $this->getContext() || array_keys($binding['scope']) !== ['client', 'language']) {
            throw new AutosaveException('scope_conflict', 'The override binding is incompatible.');
        }
        $scope  = self::scope($binding['scope']['client'], $binding['scope']['language']);
        $target = $binding['target_id'] === null ? null : $this->key($binding['target_id']);
        $this->authorize($user, $target);
        $model = $this->factory->createModel('Override', 'Administrator', ['ignore_request' => true]);
        $model->setState('filter.client', $scope['client']);
        $model->setState('filter.language', $scope['language']);
        // An explicit empty key is a creation, never the request's unrelated id.
        $item = $model->getItem($target ?? '');
        if (!$item || ($target !== null && (string) ($item->key ?? '') !== $target)) {
            throw new AutosaveException('target_not_found', 'The native override was not found.');
        }
        $item->client   = $scope['client'];
        $item->language = $scope['language'];
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_languages/forms');
        $data = (array) $item;
        $form = $model->getForm($data, false);
        if (!$form) {
            throw new AutosaveException('unsupported_context', 'The native override form is unavailable.');
        }
        $form->bind(array_intersect_key($data, array_flip(self::KEYS)));

        return $this->describe($form, $item, $user);
    }

    private function key(string $key): string
    {
        if ($key === '' || LanguagesHelper::filterKey($key) !== $key) {
            throw new AutosaveException('invalid_target', 'The native override identity is invalid.');
        }

        return $key;
    }

    private function authorize(User $user, ?string $target): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_languages')) {
            throw new AutosaveException('forbidden', 'Override draft access is denied.');
        }
        if ($target === null) {
            if (
                !$user->authorise('core.create', 'com_languages')
                && \count($user->getAuthorisedCategories('com_languages', 'core.create')) === 0
            ) {
                throw new AutosaveException('forbidden', 'An override cannot be created by this user.');
            }
        } elseif (!$user->authorise('core.edit', 'com_languages')) {
            throw new AutosaveException('forbidden', 'The override cannot be edited by this user.');
        }
    }
}
