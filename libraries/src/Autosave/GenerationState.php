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
 * Autosave generation lifecycle states.
 *
 * @since  __DEPLOY_VERSION__
 */
enum GenerationState: string
{
    case Active    = 'active';
    case Discarded = 'discarded';
    case Expired   = 'expired';

    /**
     * Determine whether this state can transition to a target state.
     *
     * @param   self  $target  The target generation state.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function canTransitionTo(self $target): bool
    {
        return $this === self::Active && $target !== self::Active;
    }
}
