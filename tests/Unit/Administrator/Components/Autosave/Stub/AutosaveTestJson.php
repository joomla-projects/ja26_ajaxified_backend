<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave\Stub;

use Joomla\Input\Json;

final class AutosaveTestJson extends Json
{
    public function __construct(private readonly string $body)
    {
        parent::__construct([]);
    }

    public function getRaw()
    {
        return $this->body;
    }
}
