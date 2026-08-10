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
 * Operations for which a provider must authorize access.
 *
 * @since  __DEPLOY_VERSION__
 */
enum AutosaveOperation: string
{
    case Initialize             = 'initialize';
    case Preserve               = 'preserve';
    case Detect                 = 'detect';
    case Read                   = 'read';
    case PrepareCanonicalAction = 'prepare-canonical-action';
    case QueryCanonicalAction   = 'query-canonical-action';
}
