<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveCanonicalActionServiceInterface;
use Joomla\CMS\User\User;

/**
 * Minimal application facade matching the controller calls used by the trait.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveTraitTestApplication
{
    public function __construct(
        private readonly AutosaveCanonicalActionServiceInterface $service,
        private readonly User $user
    ) {
    }

    public function bootComponent(string $component): AutosaveCanonicalActionServiceInterface
    {
        return $this->service;
    }

    public function getIdentity(): User
    {
        return $this->user;
    }

    public function getLanguage(): object
    {
        return new class () {
            public function load(string $extension, string $basePath): bool
            {
                return true;
            }
        };
    }
}
