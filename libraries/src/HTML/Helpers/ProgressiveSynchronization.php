<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\HTML\Helpers;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Helper for declaring progressive synchronization boundaries.
 *
 * These boundaries are consumed by the client-side synchronization runtime.
 *
 * @since  __DEPLOY_VERSION__
 */
abstract class ProgressiveSynchronization
{
    /**
     * Start a named progressive synchronization boundary.
     *
     * @param   string  $name  Boundary name.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function start(string $name): string
    {
        self::assertValidBoundaryName($name);

        return '<?start name="' . $name . '"?>';
    }

    /**
     * End the current progressive synchronization boundary.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function end(): string
    {
        return '<?end?>';
    }

    /**
     * Validate a boundary name.
     *
     * @param   string  $name  Boundary name.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function assertValidBoundaryName(string $name): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid progressive synchronization boundary name "%s".', $name));
        }
    }
}
