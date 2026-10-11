<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Date\Date;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Optional persistence capability for immutable static creation scope.
 *
 * Storage implementations without this capability remain fully valid for providers that
 * do not anchor static creation scope. A provider that requires the capability fails
 * closed when the bound storage does not provide it.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveStaticScopeStorageInterface extends AutosaveCreateStorageInterface
{
    /**
     * Anchor one canonical scope onto a continuation when none is bound yet.
     *
     * The bound scope is immutable for the lifetime of the continuation: a second call for
     * the same continuation returns the previously anchored value without modifying it.
     *
     * @param   int     $userId          The owning user id.
     * @param   string  $continuationId  Public continuation identity.
     * @param   string  $canonicalScope  Bounded canonical creation scope.
     * @param   Date    $now             Current UTC date.
     *
     * @return  string|null  The previously anchored scope, or null when newly bound.
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function bindContinuationStaticScope(int $userId, string $continuationId, string $canonicalScope, Date $now): ?string;

    /**
     * Recover the anchored canonical scope of one owner-bound continuation.
     *
     * @param   int     $userId          The owning user id.
     * @param   string  $continuationId  Public continuation identity.
     *
     * @return  string|null
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getContinuationStaticScope(int $userId, string $continuationId): ?string;
}
