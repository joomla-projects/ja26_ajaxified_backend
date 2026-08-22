<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\MVC\View;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Opt-in helpers for AJAX-enhanced administrator list views.
 *
 * @since  __DEPLOY_VERSION__
 */
trait AjaxifiedListViewTrait
{
    /**
     * Add AJAX submission options for a server-rendered list workspace.
     *
     * @param   array    $actions       Normalized task actions that are safe to submit through AJAX.
     * @param   boolean  $refresh       Whether empty-task list refresh submissions are AJAX eligible.
     * @param   array    $boundaries    DPU boundaries to synchronize, in replacement order.
     *
     * @return  void
     */
    protected function addAjaxifiedListViewOptions(
        array $actions,
        bool $refresh = true,
        array $boundaries = ['j-main-container', 'toolbar']
    ): void {
        $document = $this->getDocument();

        $document->getWebAssetManager()
            ->useScript('submission-enhancement');

        $eligibility = [];

        if ($refresh) {
            $eligibility[''] = 'ajax';
        }

        foreach ($actions as $action) {
            $eligibility[$action] = 'ajax';
        }

        $listWorkspaceStrategy = [
            'synchronizeControls' => false,
            'boundaries'          => $boundaries,
        ];

        $synchronization = [];

        foreach ($actions as $action) {
            $synchronization[$action] = $listWorkspaceStrategy;
        }

        $document->addScriptOptions('submission-eligibility', $eligibility);
        $document->addScriptOptions('submission-synchronization', $synchronization);
    }
}
