<?php

/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$states = [
    'clean'                     => ['COM_AUTOSAVE_STATUS_CLEAN', 'icon-check'],
    'detecting'                 => ['COM_AUTOSAVE_STATUS_DETECTING', 'icon-spinner icon-spin'],
    'dirty'                     => ['COM_AUTOSAVE_STATUS_DIRTY', 'icon-edit'],
    'waiting-debounce'          => ['COM_AUTOSAVE_STATUS_WAITING_DEBOUNCE', 'icon-info-circle'],
    'initializing'              => ['COM_AUTOSAVE_STATUS_INITIALIZING', 'icon-spinner icon-spin'],
    'preserving'                => ['COM_AUTOSAVE_STATUS_PRESERVING', 'icon-spinner icon-spin'],
    'preserved'                 => ['COM_AUTOSAVE_STATUS_PRESERVED', 'icon-check'],
    'offline'                   => ['COM_AUTOSAVE_STATUS_OFFLINE', 'icon-cloud'],
    'retry-waiting'             => ['COM_AUTOSAVE_STATUS_RETRY_WAITING', 'icon-info-circle'],
    'paused'                    => ['COM_AUTOSAVE_STATUS_PAUSED', 'icon-exclamation-triangle'],
    'authentication-required'   => ['COM_AUTOSAVE_STATUS_AUTHENTICATION_REQUIRED', 'icon-exclamation-triangle'],
    'conflict'                  => ['COM_AUTOSAVE_STATUS_CONFLICT', 'icon-exclamation-triangle'],
    'terminal'                  => ['COM_AUTOSAVE_STATUS_TERMINAL', 'icon-times'],
    'error'                     => ['COM_AUTOSAVE_STATUS_ERROR', 'icon-exclamation-triangle'],
    'recovery-required'         => ['COM_AUTOSAVE_STATUS_RECOVERY_REQUIRED', 'icon-info-circle'],
    'recovery-applying'         => ['COM_AUTOSAVE_STATUS_RECOVERY_APPLYING', 'icon-spinner icon-spin'],
    'recovery-discarding'       => ['COM_AUTOSAVE_STATUS_RECOVERY_DISCARDING', 'icon-spinner icon-spin'],
    'canonical-preparing'       => ['COM_AUTOSAVE_STATUS_CANONICAL_PREPARING', 'icon-spinner icon-spin'],
    'canonical-submitting'      => ['COM_AUTOSAVE_STATUS_CANONICAL_SUBMITTING', 'icon-spinner icon-spin'],
    'canonical-outcome-pending' => ['COM_AUTOSAVE_STATUS_CANONICAL_OUTCOME_PENDING', 'icon-spinner icon-spin'],
    'canonical-failed'          => ['COM_AUTOSAVE_STATUS_CANONICAL_FAILED', 'icon-exclamation-triangle'],
    'canonical-prepare-failed'  => ['COM_AUTOSAVE_STATUS_CANONICAL_PREPARE_FAILED', 'icon-exclamation-triangle'],
    'canonical-outcome-unknown' => ['COM_AUTOSAVE_STATUS_CANONICAL_OUTCOME_UNKNOWN', 'icon-exclamation-triangle'],
    'destroyed'                 => ['COM_AUTOSAVE_STATUS_DESTROYED', 'icon-times'],
    'unknown'                   => ['COM_AUTOSAVE_STATUS_UNKNOWN', 'icon-info-circle'],
];
?>
<div data-joomla-autosave-status-ui hidden>
    <div
        class="d-flex flex-wrap align-items-center gap-2 small"
        data-autosave-status
        role="status"
        aria-live="polite"
        aria-atomic="true"
        aria-busy="false"
    >
        <?php foreach ($states as $status => [$languageKey, $icon]) : ?>
            <span data-autosave-state="<?php echo $status; ?>" hidden>
                <span class="<?php echo $icon; ?>" aria-hidden="true"></span>
                <?php echo Text::_($languageKey); ?>
            </span>
        <?php endforeach; ?>
    </div>

    <p class="small text-muted mb-0" data-autosave-support="partial" hidden>
        <?php echo Text::_('COM_AUTOSAVE_SUPPORT_PARTIAL'); ?>
    </p>
    <p class="small text-warning mb-0" data-autosave-support="unsupported" hidden>
        <?php echo Text::_('COM_AUTOSAVE_SUPPORT_UNSUPPORTED'); ?>
    </p>

    <button
        type="button"
        class="btn btn-secondary btn-sm mt-2"
        data-autosave-action="retry"
        hidden
    >
        <span class="icon-refresh" aria-hidden="true"></span>
        <?php echo Text::_('COM_AUTOSAVE_ACTION_RETRY'); ?>
    </button>
</div>
