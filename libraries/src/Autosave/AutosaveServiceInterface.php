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
 * Discoverable component Autosave capability.
 *
 * @since  __DEPLOY_VERSION__
 */
interface AutosaveServiceInterface
{
    /**
     * Return the exact qualified contexts supported by the component.
     *
     * Contexts are associative keys. Values are component-defined and are not
     * interpreted by Autosave capability discovery.
     *
     * @return  array<string, mixed>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getAutosaveContexts(): array;

    /**
     * Return the component-owned provider for an exact supported context.
     *
     * @param   string  $context  The exact qualified context.
     *
     * @return  AutosaveProviderInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getAutosaveProvider(string $context): AutosaveProviderInterface;
}
