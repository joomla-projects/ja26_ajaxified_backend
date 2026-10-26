<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Exact Autosave context validation and component derivation.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveContext
{
    /**
     * Maximum length for a bounded public exact-context identifier.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const MAX_LENGTH = 255;

    /**
     * Return the owning component for a valid exact context.
     *
     * Contexts use lowercase com_component.entity names; component names may include hyphens.
     *
     * @param   string  $context  The proposed exact context.
     *
     * @return  ?string  The owning component, or null when the context is invalid.
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getComponentName(string $context): ?string
    {
        if (
            StringHelper::strlen($context) > self::MAX_LENGTH
            || preg_match(
                '/^(com_[a-z][a-z0-9_]*(?:-[a-z0-9][a-z0-9_]*)*)\.[a-z][a-z0-9_]*$/D',
                $context,
                $matches
            ) !== 1
        ) {
            return null;
        }

        return $matches[1];
    }
}
