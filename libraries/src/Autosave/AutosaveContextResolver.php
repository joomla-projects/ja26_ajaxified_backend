<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Resolve a component-owned provider from a strictly validated exact context.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveContextResolver
{
    /**
     * Component enabled-state resolver.
     *
     * @var    \Closure(string): boolean
     * @since  __DEPLOY_VERSION__
     */
    private \Closure $isEnabled;

    /**
     * Constructor.
     *
     * @param   CMSApplicationInterface  $application  The current application.
     * @param   ?callable                $isEnabled    Optional enabled-state resolver for testing.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(
        private readonly CMSApplicationInterface $application,
        ?callable $isEnabled = null
    ) {
        $this->isEnabled = $isEnabled !== null
            ? \Closure::fromCallable($isEnabled)
            : static fn (string $component): bool => ComponentHelper::isEnabled($component);
    }

    /**
     * Resolve an exact context to its component-owned provider.
     *
     * @param   string  $context  The requested exact context.
     *
     * @return  AutosaveProviderInterface
     *
     * @throws  AutosaveException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function resolve(string $context): AutosaveProviderInterface
    {
        $componentName = AutosaveContext::getComponentName($context);

        if ($componentName === null) {
            throw new AutosaveException('malformed_context', 'The Autosave context is malformed.');
        }

        try {
            if (!($this->isEnabled)($componentName)) {
                throw new \RuntimeException();
            }

            $component = $this->application->bootComponent($componentName);

            if (!$component instanceof AutosaveServiceInterface) {
                throw new \RuntimeException();
            }

            if (!\array_key_exists($context, $component->getAutosaveContexts())) {
                throw new \RuntimeException();
            }

            $provider = $component->getAutosaveProvider($context);

            if ($provider->getContext() !== $context) {
                throw new \RuntimeException();
            }

            return $provider;
        } catch (\Throwable $exception) {
            throw new AutosaveException(
                'unsupported_context',
                'The Autosave context is not supported.'
            );
        }
    }
}
