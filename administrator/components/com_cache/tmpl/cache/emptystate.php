<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_cache
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\LayoutHelper;

$displayData = [
    'textPrefix' => 'COM_CACHE',
    'helpURL'    => 'https://guide.joomla.org/user-manual/system/system-cache',
    'icon'       => 'icon-bolt clear',

    'controlFields' => $this->filterForm->renderControlFields(),
];

echo HTMLHelper::_('progressiveSynchronization.start', 'j-main-container');
?>
<div id="j-main-container" class="j-main-container">
    <?php echo LayoutHelper::render('joomla.content.emptystate', $displayData); ?>
</div>
<?php echo HTMLHelper::_('progressiveSynchronization.end'); ?>
