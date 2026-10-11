<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Optional immutable static creation scope for a new-record Autosave provider.
 *
 * A provider implementing this capability declares that a genuine new record can only be
 * created inside one bounded, server-verified creation scope (for example the owning
 * extension of a category tree) that must be known before the first provisional draft,
 * stays immutable for the whole P1 lineage, and cannot be reconstructed later from the
 * ordinary Autosave payload or Joomla user state.
 *
 * The browser may transport a candidate scope during initializeCreate, but it never
 * becomes authority: the provider canonicalizes and authorizes the candidate before the
 * lifecycle anchors it onto the continuation. Every later provisional operation recovers
 * the anchored scope from server storage and passes it back through
 * authorizeStaticCreateScope(); the browser never resubmits authoritative scope.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveStaticScopeProviderInterface
{
    /**
     * Version of the provider-defined static scope contract.
     *
     * Joins the create base revision so a scope contract change invalidates drafts,
     * exactly like the payload schema and create contract versions do.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getStaticScopeContractVersion(): string;

    /**
     * Parse, bound and canonicalize one candidate creation scope.
     *
     * The candidate is treated as untrusted input. The returned value is the compact,
     * bounded, server-verifiable canonical representation that may be anchored onto a
     * provisional lineage.
     *
     * @param   mixed  $candidateScope  Browser-supplied candidate scope.
     *
     * @return  string
     *
     * @throws  AutosaveException  invalid_scope when the candidate cannot be canonicalized.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function canonicalizeStaticCreateScope(mixed $candidateScope): string;

    /**
     * Authorize one provisional operation against the anchored canonical scope.
     *
     * Invoked for initializeCreate with the freshly canonicalized candidate and for every
     * later provisional operation (preserve, read, detect, prepare, query and canonical
     * verification) with the scope recovered from server storage. Permissions are never
     * cached and are re-evaluated for every operation.
     *
     * @param   User                 $user              The current user.
     * @param   string               $canonicalScope    The anchored canonical creation scope.
     * @param   AutosaveOperation    $operation         The current provisional operation.
     * @param   array|null           $normalizedPayload Normalized payload of this generation.
     *
     * @return  void
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function authorizeStaticCreateScope(User $user, string $canonicalScope, AutosaveOperation $operation, ?array $normalizedPayload): void;

    /**
     * Verify that one native final target belongs to the anchored canonical scope.
     *
     * Invoked after a genuine native save, before the P1 lineage is reconciled to the
     * final canonical target. Reconciliation fails closed on mismatch; the native save is
     * never replayed or rolled back.
     *
     * @param   string  $finalTargetId    Canonical identity of the record Joomla saved.
     * @param   string  $canonicalScope   The anchored canonical creation scope.
     *
     * @return  void
     *
     * @throws  AutosaveException  scope_mismatch when the final record escapes the scope.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function verifyFinalTargetStaticScope(string $finalTargetId, string $canonicalScope): void;
}
