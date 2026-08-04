<?php

/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var array $displayData */
static $instance = 0;

$instance++;
$requestedId = $displayData['id'] ?? '';
$instanceId  = \is_string($requestedId) ? preg_replace('/[^A-Za-z0-9_.:-]/', '-', $requestedId) : '';
$instanceId  = $instanceId !== '' ? $instanceId : 'joomla-autosave-' . $instance;
$headingId   = $instanceId . '-recovery-heading';

$states = [
    'clean'                   => ['COM_AUTOSAVE_STATUS_CLEAN', 'icon-check'],
    'detecting'               => ['COM_AUTOSAVE_STATUS_DETECTING', 'icon-spinner icon-spin'],
    'dirty'                   => ['COM_AUTOSAVE_STATUS_DIRTY', 'icon-edit'],
    'waiting-debounce'        => ['COM_AUTOSAVE_STATUS_WAITING_DEBOUNCE', 'icon-info-circle'],
    'initializing'            => ['COM_AUTOSAVE_STATUS_INITIALIZING', 'icon-spinner icon-spin'],
    'preserving'              => ['COM_AUTOSAVE_STATUS_PRESERVING', 'icon-spinner icon-spin'],
    'preserved'               => ['COM_AUTOSAVE_STATUS_PRESERVED', 'icon-check'],
    'offline'                 => ['COM_AUTOSAVE_STATUS_OFFLINE', 'icon-cloud'],
    'retry-waiting'           => ['COM_AUTOSAVE_STATUS_RETRY_WAITING', 'icon-info-circle'],
    'paused'                  => ['COM_AUTOSAVE_STATUS_PAUSED', 'icon-exclamation-triangle'],
    'authentication-required' => ['COM_AUTOSAVE_STATUS_AUTHENTICATION_REQUIRED', 'icon-exclamation-triangle'],
    'conflict'                => ['COM_AUTOSAVE_STATUS_CONFLICT', 'icon-exclamation-triangle'],
    'terminal'                => ['COM_AUTOSAVE_STATUS_TERMINAL', 'icon-times'],
    'error'                   => ['COM_AUTOSAVE_STATUS_ERROR', 'icon-exclamation-triangle'],
    'recovery-required'       => ['COM_AUTOSAVE_STATUS_RECOVERY_REQUIRED', 'icon-info-circle'],
    'recovery-applying'       => ['COM_AUTOSAVE_STATUS_RECOVERY_APPLYING', 'icon-spinner icon-spin'],
    'recovery-discarding'     => ['COM_AUTOSAVE_STATUS_RECOVERY_DISCARDING', 'icon-spinner icon-spin'],
    'destroyed'               => ['COM_AUTOSAVE_STATUS_DESTROYED', 'icon-times'],
    'unknown'                 => ['COM_AUTOSAVE_STATUS_UNKNOWN', 'icon-info-circle'],
];

$urgentStates = [
    'authentication-required',
    'conflict',
    'terminal',
    'error',
    'recovery-required',
];
?>
<div class="mb-3" data-joomla-autosave-ui hidden>
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
        <span data-autosave-saved-time-container hidden>
            <span aria-hidden="true">—</span>
            <span class="visually-hidden"><?php echo Text::_('COM_AUTOSAVE_TIME_AT'); ?></span>
            <time data-autosave-time></time>
        </span>
    </div>

    <button
        type="button"
        class="btn btn-secondary btn-sm mt-2"
        data-autosave-action="retry"
        hidden
    >
        <span class="icon-refresh" aria-hidden="true"></span>
        <?php echo Text::_('COM_AUTOSAVE_ACTION_RETRY'); ?>
    </button>

    <div class="visually-hidden" data-autosave-alert role="alert">
        <?php foreach ($urgentStates as $status) : ?>
            <span data-autosave-alert-state="<?php echo $status; ?>" hidden>
                <?php echo Text::_($states[$status][0]); ?>
            </span>
        <?php endforeach; ?>
    </div>

    <section
        class="alert alert-warning mt-2 mb-0"
        data-autosave-recovery
        role="region"
        aria-labelledby="<?php echo $headingId; ?>"
        aria-busy="false"
        hidden
    >
        <h3 class="alert-heading h5" id="<?php echo $headingId; ?>">
            <span class="icon-info-circle" aria-hidden="true"></span>
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_HEADING'); ?>
        </h3>

        <p data-autosave-recovery-current hidden>
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_CURRENT'); ?>
        </p>
        <p data-autosave-recovery-stale hidden>
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_STALE'); ?>
        </p>
        <p data-autosave-recovery-unknown hidden>
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_UNKNOWN'); ?>
        </p>
        <p class="small" data-autosave-recovery-local-edits hidden>
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_LOCAL_EDITS'); ?>
        </p>
        <p class="small text-muted" data-autosave-recovery-time-container hidden>
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_SAVED_AT'); ?>
            <time data-autosave-recovery-time></time>
        </p>
        <p class="small">
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_CONTINUE_EXPLANATION'); ?>
        </p>

        <div class="small mb-2" data-autosave-busy hidden>
            <span class="icon-spinner icon-spin" aria-hidden="true"></span>
            <?php echo Text::_('COM_AUTOSAVE_RECOVERY_PROCESSING'); ?>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-primary" data-autosave-action="restore">
                <span class="icon-refresh" aria-hidden="true"></span>
                <?php echo Text::_('COM_AUTOSAVE_ACTION_RESTORE'); ?>
            </button>
            <button type="button" class="btn btn-secondary" data-autosave-action="keep-current">
                <?php echo Text::_('COM_AUTOSAVE_ACTION_KEEP_CURRENT'); ?>
            </button>
            <button
                type="button"
                class="btn btn-danger"
                data-autosave-action="discard"
                data-autosave-confirm-title="<?php echo htmlspecialchars(Text::_('COM_AUTOSAVE_DISCARD_CONFIRM_TITLE'), ENT_QUOTES, 'UTF-8'); ?>"
                data-autosave-confirm-message="<?php echo htmlspecialchars(Text::_('COM_AUTOSAVE_DISCARD_CONFIRM_MESSAGE'), ENT_QUOTES, 'UTF-8'); ?>"
            >
                <span class="icon-trash" aria-hidden="true"></span>
                <?php echo Text::_('COM_AUTOSAVE_ACTION_DISCARD'); ?>
            </button>
        </div>
    </section>
</div>
