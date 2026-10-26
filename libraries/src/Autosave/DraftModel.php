<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\FormModel;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Small native read/form helpers; component policy and scope stay in their providers.
 *
 * @since  __DEPLOY_VERSION__
 */
final class DraftModel
{
    /**
     * Check that rebuilding the form kept the draft's scope.
     *
     * @since __DEPLOY_VERSION__
     */
    public static function assertScope(array $scope, array $expected, string $message = 'The native form scope changed.'): void
    {
        if ($scope !== $expected) {
            throw new AutosaveException('scope_conflict', $message);
        }
    }

    /**
     * Check an item's existing checkout without changing it.
     *
     * @since __DEPLOY_VERSION__
     */
    public static function assertCheckout(object $item, User $user, string $message = 'The native record is checked out by another user.'): void
    {
        if (!empty($item->checked_out) && (int) $item->checked_out !== (int) $user->id) {
            throw new AutosaveException('checkout_conflict', $message);
        }
    }

    /**
     * @param array{context: string, target_id: ?string, scope: array} $binding The native item identity and scope.
     * @param string                                                   $context The expected provider context.
     *
     * @since __DEPLOY_VERSION__
     */
    public static function target(array $binding, string $context): ?string
    {
        if ($binding['context'] !== $context) {
            throw new AutosaveException('scope_conflict', 'The draft context changed.');
        }
        $target = $binding['target_id'];
        if ($target !== null && (!\is_string($target) || preg_match('/^[1-9][0-9]*$/D', $target) !== 1 || (int) $target > 4294967295)) {
            throw new AutosaveException('invalid_target', 'The native record identity is invalid.');
        }
        return $target;
    }

    /**
     * Check a workflow route against its parent workflow's extension.
     *
     * @since  __DEPLOY_VERSION__
     */
    public static function extension(string $canonical, ?string $prepared = null): string
    {
        if ($prepared !== null && $prepared !== $canonical && $prepared !== explode('.', $canonical)[0]) {
            throw new AutosaveException('scope_conflict', 'The owning workflow extension changed.');
        }
        return $canonical;
    }

    /**
     * @since  __DEPLOY_VERSION__
     */
    public static function form(FormModel $model, string $component, object $item, array $data = []): Form
    {
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/' . $component . '/forms');
        $data += (array) $item;
        $form = $model->getForm($data, false);
        if (!$form) {
            throw new AutosaveException('unsupported_context', 'The native form is unavailable.');
        }
        $form->bind($data);
        return $form;
    }
}
