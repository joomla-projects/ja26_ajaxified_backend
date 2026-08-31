<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

/**
 * Minimal input facade matching the controller calls used by the trait.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveTraitTestInput
{
    public object $post;

    public function __construct(private readonly string $task, array $post, private readonly mixed $recordId = 42)
    {
        $this->post = new class ($post) {
            public function __construct(private readonly array $data)
            {
            }

            public function getString(string $key, string $default = ''): string
            {
                return isset($this->data[$key]) ? (string) $this->data[$key] : $default;
            }

            public function get(string $key, mixed $default = null, string $filter = 'cmd'): mixed
            {
                return $this->data[$key] ?? $default;
            }
        };
    }

    public function getCmd(string $key, string $default = ''): string
    {
        return $key === 'task' ? $this->task : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return $key === 'id' && is_numeric($this->recordId) ? (int) $this->recordId : $default;
    }

    public function get(string $key, mixed $default = null, string $filter = 'cmd'): mixed
    {
        return $key === 'id' ? $this->recordId : $default;
    }
}
