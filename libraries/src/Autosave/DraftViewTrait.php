<?php

/**
 * Joomla! Content Management System
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

use Joomla\CMS\Factory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Draft setup and toolbar status for administrator edit views.
 *
 * @since __DEPLOY_VERSION__
 */
trait DraftViewTrait
{
    /**
     * Whether draft controls are shown for this form.
     *
     * @var   boolean
     * @since __DEPLOY_VERSION__
     */
    public bool $autosaveEnabled = false;

    /**
     * Layout ID for the draft status.
     *
     * @var   string
     * @since __DEPLOY_VERSION__
     */
    public string $autosaveStatusId = '';

    /**
     * Configure drafts for an edit form using its explicit integration settings.
     *
     * @param array $integration Context, form/status IDs, save tasks and an optional prepared item.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    protected function prepareAutosave(array $integration): void
    {
        if (!\in_array($this->getLayout(), ['edit', 'modal'], true)) {
            return;
        }

        $this->autosaveStatusId = $integration['statusId'];

        try {
            $item = $integration['item'] ?? $this->item;
            unset($integration['item']);

            $this->autosaveEnabled = Factory::getContainer()->get(DraftView::class)->configure(
                Factory::getApplication(),
                $this->getDocument(),
                $this->getCurrentUser(),
                $this->form,
                $item,
                $integration
            );
        } catch (AutosaveException) {
            // Render the form without draft controls.
            $this->autosaveEnabled = false;
        } catch (\Throwable $exception) {
            $this->autosaveEnabled = false;
            try {
                Factory::getApplication()->getLogger()->warning('Optional draft view setup failed.', ['exception_class' => $exception::class]);
            } catch (\Throwable) {
                // Continue rendering if logging fails.
            }
        }
    }

    /**
     * Add draft status after the view has built its native toolbar.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    protected function addAutosaveStatus(): void
    {
        if ($this->autosaveEnabled) {
            DraftView::addStatus($this->getDocument()->getToolbar(), $this->autosaveStatusId);
        }
    }
}
