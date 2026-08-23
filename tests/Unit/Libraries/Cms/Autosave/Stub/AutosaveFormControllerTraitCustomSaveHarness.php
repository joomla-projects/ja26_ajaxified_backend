<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave\Stub;

use Joomla\CMS\Autosave\AutosaveFormControllerTrait;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Controller-owned save seam proving explicit Autosave delegation.
 *
 * @since  __DEPLOY_VERSION__
 */
class AutosaveFormControllerTraitCustomSaveHarness extends AutosaveFormControllerTraitParent
{
    use AutosaveFormControllerTrait;

    private const AUTOSAVE_CONTEXT      = 'com_example.item';
    private const AUTOSAVE_TASK_INTENTS = [
        'apply'    => 'apply',
        'save'     => 'save-exit',
        'save2new' => 'save-new',
    ];

    public int $nativeSaveCalls               = 0;
    public bool $nativeSaveResult             = true;
    public ?\Throwable $nativeSaveException   = null;
    public ?BaseDatabaseModel $savedModel     = null;
    public array $nativeSaveArguments         = [];
    public array $operationOrder              = [];
    public ?string $nativeRedirect            = null;
    public array $nativeMessages              = [];
    public array $nativeSession               = [];
    public int $componentSideEffects          = 0;

    public function save($key = null, $urlVar = null)
    {
        return $this->executeAutosaveCanonicalSave(
            fn () => $this->executeNativeSave($key, $urlVar),
            $urlVar
        );
    }

    private function executeNativeSave($key, $urlVar): bool
    {
        $this->nativeSaveCalls++;
        $this->nativeSaveArguments[]  = [$key, $urlVar];
        $this->operationOrder[]       = 'native';
        $this->nativeRedirect         = 'native/redirect';
        $this->nativeMessages[]       = 'native message';
        $this->nativeSession['saved'] = true;
        $this->componentSideEffects++;

        if ($this->nativeSaveException) {
            throw $this->nativeSaveException;
        }

        if ($this->nativeSaveResult && $this->savedModel) {
            $this->captureAutosaveCanonicalResult($this->savedModel, 'item.id');
        }

        return $this->nativeSaveResult;
    }
}
