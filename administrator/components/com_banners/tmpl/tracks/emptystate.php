<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_banners
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\LayoutHelper;

$displayData = [
    'textPrefix' => 'COM_BANNERS_TRACKS',
    'helpURL'    => 'https://guide.joomla.org/user-manual/banners/banners-banners',
    'icon'       => 'icon-bookmark banners',

    'controlFields' => $this->filterForm->renderControlFields(),
];

echo HTMLHelper::_('progressiveSynchronization.start', 'j-main-container');
?>
<div id="j-main-container" class="j-main-container">
    <?php echo LayoutHelper::render('joomla.content.emptystate', $displayData); ?>
</div>
<?php echo HTMLHelper::_('progressiveSynchronization.end'); ?>
