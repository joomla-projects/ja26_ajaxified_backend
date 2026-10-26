<?php

/**
 * @package    Joomla.Administrator
 * @subpackage com_contact
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Contact\Administrator\Autosave;

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
 * Draft fields and access checks for the Contact form.
 */
final class ContactAutosaveProvider implements DraftProviderInterface
{
    private const KEYS = ['name', 'alias', 'version_note', 'misc', 'image', 'con_position', 'email_to', 'address', 'suburb', 'state', 'postcode', 'country', 'telephone', 'mobile', 'fax', 'webpage', 'sortname1', 'sortname2', 'sortname3', 'publish_up', 'publish_down', 'metakey', 'metadesc', 'catid'];

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
        return 'com_contact.contact';
    }

    /**
     * @inheritDoc
     */
    public function describe(Form $form, object $item, User $user): array
    {
        $target = empty($item->id) ? null : (string) $item->id;
        $this->authorize($user, $item, (int) $form->getValue('catid', null, 0));
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
        $model = $this->factory->createModel('Contact', 'Administrator', ['ignore_request' => true]);
        $model->setState('contact.id', $target === null ? 0 : (int) $target);

        $item = $model->getItem($target === null ? 0 : (int) $target);
        if (!$item || ($target !== null && (string) $item->id !== $target)) {
            throw new AutosaveException('target_not_found', 'The native record was not found.');
        }

        $category = $target === null ? (int) ($payload['catid'] ?? 0) : (int) $item->catid;
        $this->authorize($user, $item, $category);
        $data       = $target === null ? ['catid' => $category] : [];
        $form       = DraftModel::form($model, 'com_contact', $item, $data);
        $descriptor = $this->describe($form, $item, $user);
        DraftModel::assertScope($descriptor['scope'], $binding['scope']);
        return $descriptor;
    }

    private function authorize(User $user, object $item, int $category): void
    {
        if ((int) $user->id <= 0 || $user->guest || !$user->authorise('core.manage', 'com_contact')) {
            throw new AutosaveException('forbidden', 'Native component access is denied.');
        }
        $allowed = empty($item->id) ? ($category > 0 ? $user->authorise('core.create', 'com_contact.category.' . $category) : ($user->authorise('core.create', 'com_contact') || \count($user->getAuthorisedCategories('com_contact', 'core.create')) > 0)) : ($user->authorise('core.edit', 'com_contact.category.' . (int) $item->catid) || ($user->authorise('core.edit.own', 'com_contact.category.' . (int) $item->catid) && (int) ($item->created_by ?? 0) === (int) $user->id));
        if (!$allowed) {
            throw new AutosaveException('forbidden', 'The native form cannot be edited by this user.');
        }
        DraftModel::assertCheckout($item, $user);
    }
}
