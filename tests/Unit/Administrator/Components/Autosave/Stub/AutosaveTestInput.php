<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave\Stub;

use Joomla\Input\Input;

final class AutosaveTestInput extends Input
{
    public function __construct(
        string $task,
        string $body,
        string $method = 'POST',
        string $contentType = 'application/json',
        string $token = 'token',
        ?string $contentLength = null
    ) {
        parent::__construct(['task' => $task, 'format' => 'json']);

        $this->inputs['server'] = new Input(
            [
                'REQUEST_METHOD'    => $method,
                'CONTENT_TYPE'      => $contentType,
                'CONTENT_LENGTH'    => $contentLength ?? (string) \strlen($body),
                'HTTP_X_CSRF_TOKEN' => $token,
            ]
        );
        $this->inputs['json'] = new AutosaveTestJson($body);
    }
}
