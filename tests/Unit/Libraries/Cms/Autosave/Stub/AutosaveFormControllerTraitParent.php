<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Psr\Log\NullLogger;

/**
 * Parent save seam used to prove exact delegation behavior.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveFormControllerTraitParent
{
    public int $parentSaveCalls = 0;
    public bool $parentSaveResult = true;
    public ?\Throwable $parentSaveException = null;
    public mixed $duringParentSave = null;
    public ?string $redirect = null;

    public function __construct(public object $input, public object $app)
    {
    }

    public function save($key = null, $urlVar = null)
    {
        $this->parentSaveCalls++;

        if ($this->duringParentSave) {
            ($this->duringParentSave)();
        }

        if ($this->parentSaveException) {
            throw $this->parentSaveException;
        }

        return $this->parentSaveResult;
    }

    public function setMessage(string $message, string $type = 'message'): void
    {
    }

    public function setRedirect(string $url): void
    {
        $this->redirect = $url;
    }

    public function getLogger(): NullLogger
    {
        return new NullLogger();
    }

    protected function getRedirectUrlToItem(int $recordId): string
    {
        return 'item/' . $recordId;
    }
}
