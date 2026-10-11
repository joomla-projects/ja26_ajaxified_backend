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
 * Optional component assistance for owning exact-context providers.
 *
 * Using this trait does not advertise support. Components must separately implement
 * {@see AutosaveServiceInterface}.
 *
 * @since  __DEPLOY_VERSION__
 */
trait AutosaveServiceTrait
{
    /**
     * Providers keyed by exact qualified context.
     *
     * @var    array<string, AutosaveProviderInterface>
     * @since  __DEPLOY_VERSION__
     */
    private array $autosaveProviders = [];

    /**
     * Register a component-owned provider for one exact context.
     *
     * @param   string                     $context   The exact qualified context.
     * @param   AutosaveProviderInterface  $provider  The provider.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function setAutosaveProvider(string $context, AutosaveProviderInterface $provider): void
    {
        if (AutosaveContext::getComponentName($context) === null) {
            throw new \InvalidArgumentException('The Autosave provider context is malformed.');
        }

        if ($provider->getContext() !== $context) {
            throw new \InvalidArgumentException('The Autosave provider context does not match its registration.');
        }

        if (isset($this->autosaveProviders[$context])) {
            throw new \LogicException('An Autosave provider is already registered for this context.');
        }

        $this->autosaveProviders[$context] = $provider;
    }

    /**
     * Return the provider registered for an exact context.
     *
     * @param   string  $context  The exact qualified context.
     *
     * @return  AutosaveProviderInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getAutosaveProvider(string $context): AutosaveProviderInterface
    {
        if (!isset($this->autosaveProviders[$context])) {
            throw new \OutOfBoundsException('No Autosave provider is registered for this context.');
        }

        return $this->autosaveProviders[$context];
    }
}
