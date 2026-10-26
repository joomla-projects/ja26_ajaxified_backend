<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Read-only component policy for a prepared native form and its private drafts.
 *
 * @since  __DEPLOY_VERSION__
 */
interface DraftProviderInterface
{
    /**
     * Return the component's registered form context.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getContext(): string;

    /**
     * Describe supported fields and saved item values.
     *
     * @return  array{context: string, target: ?string, scope: array, version: int, fields: array,
     *                revision: ?string, values: array, excluded: array}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function describe(Form $form, object $item, User $user): array;

    /**
     * Reload the item and prepared form and recheck access.
     *
     * Read without session error data or invoking save, validation or draft storage.
     *
     * @param array{context: string, target_id: ?string, scope: array} $binding The native item identity and scope.
     * @param User                                                     $user    The current user.
     * @param array|null                                               $payload Proposed field values, separate from the identity.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function read(array $binding, User $user, ?array $payload = null): array;
}
