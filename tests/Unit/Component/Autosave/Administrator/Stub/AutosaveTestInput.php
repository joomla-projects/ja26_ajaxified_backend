<?php

namespace Joomla\Tests\Unit\Component\Autosave\Administrator\Stub;

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
        parent::__construct(['option' => 'com_ajax', 'plugin' => 'autosave', 'group' => 'system', 'task' => $task, 'format' => 'json']);

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
