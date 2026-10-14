<?php

/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$part = \is_array($displayData) ? ($displayData['part'] ?? 'all') : 'all';
$statusId = \is_array($displayData) ? ($displayData['id'] ?? 'joomla-autosave-status') : 'joomla-autosave-status';
$scopeId = $statusId . '-scope';
$states = [
    'clean'       => ['COM_AUTOSAVE_DRAFT_STATE_CLEAN', 'icon-check'],
    'detecting'   => ['COM_AUTOSAVE_DRAFT_STATE_DETECTING', 'icon-spinner icon-spin'],
    'dirty'       => ['COM_AUTOSAVE_DRAFT_STATE_DIRTY', 'icon-spinner icon-spin'],
    'preserving'  => ['COM_AUTOSAVE_DRAFT_STATE_PRESERVING', 'icon-spinner icon-spin'],
    'preserved'   => ['COM_AUTOSAVE_DRAFT_STATE_PRESERVED', 'icon-check'],
    'offline'     => ['COM_AUTOSAVE_DRAFT_STATE_OFFLINE', 'icon-cloud'],
    'conflict'    => ['COM_AUTOSAVE_DRAFT_STATE_CONFLICT', 'icon-exclamation-triangle'],
    'unavailable' => ['COM_AUTOSAVE_DRAFT_STATE_UNAVAILABLE', 'icon-exclamation-triangle'],
    'discarded'   => ['COM_AUTOSAVE_DRAFT_STATE_DISCARDED', 'icon-times'],
];
$errors = [
    'authentication_required invalid_csrf_token csrf_failed invalid_token' => 'AUTH',
    'forbidden backend_access_denied' => 'ACCESS',
    'checkout_conflict' => 'CHECKOUT',
    'revision_conflict' => 'ORDER',
    'invalid_request invalid_payload malformed_json unsupported_media_type unsupported_operation invalid_target' => 'PAYLOAD',
    'unsupported_schema_version schema_version_conflict unsupported_context' => 'SCHEMA',
    'draft_not_found target_not_found draft_expired draft_retired' => 'EXPIRED',
    'payload_too_large request_too_large' => 'SIZE',
    'transport_failure service_unavailable internal_error' => 'TRANSPORT',
    'unsupported_controls' => 'CONTROLS',
    'disposed_form' => 'RELOAD',
    'scope_conflict' => 'SCOPE',
    'base_revision_conflict' => 'BASE',
    'render_admission_expired render_admission_missing' => 'RESUMPTION',
    'creation_recovery_unavailable' => 'CREATION_REFERENCE',
];
$confirm = static fn (string $key): string => htmlspecialchars(Text::_($key), ENT_QUOTES, 'UTF-8');
?>
<?php if (\in_array($part, ['status', 'all'], true)) : ?>
<div id="<?php echo htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8'); ?>" class="small fw-normal text-body align-self-end mb-1 ms-2" data-draft-ui>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span data-draft-status role="status" tabindex="0" aria-live="polite" aria-atomic="true" aria-busy="false"
            aria-describedby="<?php echo htmlspecialchars($scopeId, ENT_QUOTES, 'UTF-8'); ?>"
            title="<?php echo $confirm('COM_AUTOSAVE_DRAFT_SAVE_HELP'); ?>">
            <?php foreach ($states as $state => [$key, $icon]) : ?>
                <span data-draft-state="<?php echo $state; ?>" <?php echo $state === 'unavailable' ? '' : 'hidden'; ?>>
                    <span class="<?php echo $icon; ?>" aria-hidden="true"></span>
                    <?php echo Text::_($key); ?>
                </span>
            <?php endforeach; ?>
        </span>
        <span id="<?php echo htmlspecialchars($scopeId, ENT_QUOTES, 'UTF-8'); ?>" class="visually-hidden"
            data-draft-scope data-description="<?php echo $confirm('COM_AUTOSAVE_DRAFT_SAVE_HELP'); ?>">
            <?php echo Text::_('COM_AUTOSAVE_DRAFT_SAVE_HELP'); ?>
        </span>
        <button type="button" class="btn btn-link btn-sm p-0" data-draft-action="retry" hidden><?php echo Text::_('COM_AUTOSAVE_ACTION_RETRY'); ?></button>
    </div>
</div>
<?php endif; ?>
<?php if (\in_array($part, ['feedback', 'all'], true)) : ?>
<div class="alert alert-warning mb-3" data-draft-feedback role="alert" hidden>
    <?php foreach ($errors as $codes => $key) : ?>
        <p class="mb-0" data-draft-error="<?php echo $codes; ?>" hidden>
            <?php echo Text::_('COM_AUTOSAVE_DRAFT_ERROR_' . $key); ?>
        </p>
    <?php endforeach; ?>
    <p class="mb-0" data-draft-partial-recovery hidden><?php echo Text::_('COM_AUTOSAVE_DRAFT_PARTIAL_RECOVERY'); ?></p>
</div>
<?php endif; ?>
<?php if (\in_array($part, ['recovery', 'all'], true)) : ?>
<section class="alert alert-info mb-3" data-draft-recovery role="region" tabindex="-1" aria-label="<?php echo $confirm('COM_AUTOSAVE_RECOVERY_HEADING'); ?>" hidden>
    <h3 class="alert-heading h5">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_AUTOSAVE_RECOVERY_HEADING'); ?>
    </h3>
    <p><?php echo Text::_('COM_AUTOSAVE_RECOVERY_CURRENT'); ?></p>
    <p class="small" data-draft-partial hidden><?php echo Text::_('COM_AUTOSAVE_SUPPORT_PARTIAL'); ?></p>
    <div class="row mb-3">
        <div class="col-md-6">
            <label class="form-label" data-draft-candidate-label>
                <?php echo Text::_('COM_AUTOSAVE_RECOVERY_SAVED_AT'); ?>
                <select class="form-select" data-draft-candidates></select>
            </label>
            <button type="button" class="btn btn-secondary" data-draft-action="more" hidden>
                <?php echo Text::_('COM_AUTOSAVE_DRAFT_MORE'); ?>
            </button>
        </div>
    </div>
    <p class="fw-bold" data-draft-conflict hidden><?php echo Text::_('COM_AUTOSAVE_RECOVERY_STALE'); ?></p>
    <p data-draft-creation hidden><?php echo Text::_('COM_AUTOSAVE_DRAFT_UNCERTAIN_CREATION'); ?></p>
    <div class="table-responsive mb-3" data-draft-comparison-container hidden>
        <table class="table table-sm">
            <caption class="visually-hidden"><?php echo Text::_('COM_AUTOSAVE_DRAFT_REVIEW'); ?></caption>
            <thead>
                <tr>
                    <th scope="col" class="w-25"><?php echo Text::_('COM_AUTOSAVE_DRAFT_FIELD'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_AUTOSAVE_DRAFT_CURRENT'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_AUTOSAVE_DRAFT_RECOVERABLE'); ?></th>
                </tr>
            </thead>
            <tbody data-draft-comparison></tbody>
        </table>
    </div>
    <p class="small"><?php echo Text::_('COM_AUTOSAVE_RECOVERY_CONTINUE_EXPLANATION'); ?></p>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-primary" data-draft-action="restore" disabled
            data-confirm-title="<?php echo $confirm('COM_AUTOSAVE_RECOVERY_HEADING'); ?>"
            data-confirm-message="<?php echo $confirm('COM_AUTOSAVE_DRAFT_REPLACE_LOCAL'); ?>">
            <span class="icon-refresh" aria-hidden="true"></span>
            <?php echo Text::_('COM_AUTOSAVE_ACTION_RESTORE'); ?>
        </button>
        <button type="button" class="btn btn-secondary" data-draft-action="review">
            <span class="icon-eye" aria-hidden="true"></span>
            <?php echo Text::_('COM_AUTOSAVE_DRAFT_REVIEW'); ?>
        </button>
        <button type="button" class="btn btn-secondary" data-draft-action="keep"><?php echo Text::_('COM_AUTOSAVE_ACTION_KEEP_CURRENT'); ?></button>
        <button type="button" class="btn btn-danger" data-draft-action="discard"
            data-confirm-title="<?php echo $confirm('COM_AUTOSAVE_DISCARD_CONFIRM_TITLE'); ?>"
            data-confirm-message="<?php echo $confirm('COM_AUTOSAVE_DISCARD_CONFIRM_MESSAGE'); ?>">
            <span class="icon-trash" aria-hidden="true"></span>
            <?php echo Text::_('COM_AUTOSAVE_ACTION_DISCARD'); ?>
        </button>
    </div>
</section>
<?php endif; ?>
