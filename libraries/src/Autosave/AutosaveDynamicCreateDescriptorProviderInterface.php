<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Optional dynamic creation descriptor capability for a new-record Autosave provider.
 *
 * A provider implementing this capability declares that the effective dynamic
 * form/schema of a genuine new record is determined by creation choices (for
 * example the field type of a Custom Field) that must be canonicalized and
 * anchored server-side before the first provisional draft and stay immutable for
 * the whole P1 lineage.
 *
 * The anchored canonical descriptor reuses the immutable static creation scope
 * binding: every method of AutosaveStaticScopeProviderInterface operates on the
 * canonical descriptor token instead of a plain scope, and the token is bound and
 * recovered through the same continuation storage. The browser may propose a
 * candidate descriptor during initializeCreate, but it never becomes authority.
 *
 * A descriptor-bound provider additionally normalizes every provisional payload
 * against the schema reconstructed from the anchored descriptor, so a payload
 * that does not fit the descriptor-bound schema fails before any storage mutation.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveDynamicCreateDescriptorProviderInterface extends AutosaveStaticScopeProviderInterface
{
    /**
     * Normalize one provisional payload against the schema of the anchored descriptor.
     *
     * The lifecycle recovers the anchored descriptor from server storage and passes
     * it here before authorization, so the provider can reconstruct the applicable
     * dynamic schema and validate/normalize the exact payload (including dynamic
     * values) before any storage mutation. Schema drift between the anchored
     * descriptor and the current server schema fails closed.
     *
     * @param   string  $descriptor     The anchored canonical creation descriptor.
     * @param   mixed   $payload        Browser-supplied draft payload.
     * @param   int     $schemaVersion  The provider payload schema version.
     *
     * @return  array
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function normalizeCreatePayload(string $descriptor, mixed $payload, int $schemaVersion): array;
}
