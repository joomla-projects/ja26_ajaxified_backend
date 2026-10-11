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
$instanceId  = $instanceId !== '' ? $instanceId : 'joomla-autosave-recovery-' . $instance;
$headingId   = $instanceId . '-heading';

$urgentStates = [
    'authentication-required' => 'COM_AUTOSAVE_STATUS_AUTHENTICATION_REQUIRED',
    'conflict'                 => 'COM_AUTOSAVE_STATUS_CONFLICT',
    'terminal'                 => 'COM_AUTOSAVE_STATUS_TERMINAL',
    'error'                    => 'COM_AUTOSAVE_STATUS_ERROR',
    'recovery-required'        => 'COM_AUTOSAVE_STATUS_RECOVERY_REQUIRED',
];
?>
<div class="mb-3" data-joomla-autosave-recovery-ui hidden>
    <div class="visually-hidden" data-autosave-alert role="alert">
        <?php foreach ($urgentStates as $status => $languageKey) : ?>
            <span data-autosave-alert-state="<?php echo $status; ?>" hidden>
                <?php echo Text::_($languageKey); ?>
            </span>
        <?php endforeach; ?>
    </div>

    <section
        class="alert alert-warning mb-0"
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
        <p class="small" data-autosave-recovery-time-container hidden>
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
