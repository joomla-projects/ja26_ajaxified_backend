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
 * Optional capability for controllers whose canonical Autosave identity is
 * composite/component-owned rather than the numeric table primary key.
 *
 * A controller implementing this marker declares that it supplies the
 * authoritative route identity itself through the AutosaveFormControllerTrait
 * hook resolveAutosaveCanonicalTarget(): a canonical composite identity for an
 * existing record, or an empty string for a genuine new record. The shared
 * canonical-save orchestration then skips the strict numeric route/submitted
 * identity gate and verifies the operation against the component-provided
 * target, while numeric components keep the existing behaviour unchanged.
 *
 * The marker exposes no methods and no component semantics: the shared layer
 * only knows that this controller provides an authoritative non-numeric final
 * target, never what that target means.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveCompositeCanonicalIdentityInterface
{
}
