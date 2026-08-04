<?php

/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Layout\LayoutHelper;

/** @var array $displayData */
$id = isset($displayData['id']) && \is_string($displayData['id'])
    ? $displayData['id']
    : 'joomla-autosave';
?>
<div class="mb-3">
    <?php echo LayoutHelper::render('joomla.autosave.status', ['id' => $id . '-status']); ?>
    <?php echo LayoutHelper::render('joomla.autosave.recovery', ['id' => $id . '-recovery']); ?>
</div>
