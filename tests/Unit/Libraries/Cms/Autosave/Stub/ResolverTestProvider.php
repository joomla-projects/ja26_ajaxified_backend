<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\DraftProviderInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;

final class ResolverTestProvider implements DraftProviderInterface
{
    public function __construct(private readonly string $context)
    {
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function describe(Form $form, object $item, User $user): array
    {
        throw new \LogicException('Provider registration does not prepare a form.');
    }

    public function read(array $binding, User $user, ?array $payload = null): array
    {
        throw new \LogicException('Provider registration does not read content.');
    }
}
