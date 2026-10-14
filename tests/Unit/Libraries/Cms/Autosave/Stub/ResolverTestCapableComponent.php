<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Autosave\AutosaveServiceTrait;
use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;

final class ResolverTestCapableComponent implements ComponentInterface, AutosaveServiceInterface
{
    use AutosaveServiceTrait {
        getAutosaveProvider as private getRegisteredAutosaveProvider;
    }

    private array $forcedProviders = [];

    /**
     * @param  array<string, mixed>|list<string>  $contexts  Context map or deliberately invalid list.
     */
    public function __construct(private readonly array $contexts, private readonly bool $fail = false)
    {
    }

    /**
     * @return  array<string, mixed>|list<string>
     */
    public function getAutosaveContexts(): array
    {
        return $this->contexts;
    }

    public function getAutosaveProvider(string $context): DraftProviderInterface
    {
        if ($this->fail) {
            throw new \RuntimeException('provider failure');
        }

        if (isset($this->forcedProviders[$context])) {
            return $this->forcedProviders[$context];
        }

        return $this->getRegisteredAutosaveProvider($context);
    }

    public function forceProvider(string $context, DraftProviderInterface $provider): void
    {
        $this->forcedProviders[$context] = $provider;
    }

    public function getDispatcher(CMSApplicationInterface $application): DispatcherInterface
    {
        throw new \LogicException('Not used by this test.');
    }
}
