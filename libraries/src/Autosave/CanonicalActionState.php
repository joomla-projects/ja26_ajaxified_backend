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
 * Server-authoritative canonical action outcomes.
 *
 * @since  __DEPLOY_VERSION__
 */
enum CanonicalActionState: string
{
    case Pending    = 'pending';
    case Successful = 'successful';
    case Failed     = 'failed';
    case Unknown    = 'unknown';
}
