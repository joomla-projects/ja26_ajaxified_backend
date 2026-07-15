<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_finder
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\LayoutHelper;

$displayData = [
    'textPrefix' => 'COM_FINDER',
    'formURL'    => 'index.php?option=com_finder&view=searches',
    'helpURL'    => 'https://docs.joomla.org/Special:MyLanguage/Help5.x:Smart_Search:_Search_Term_Analysis',
    'icon'       => 'icon-search',
    'title'      => Text::_('COM_FINDER_MANAGER_SEARCHES'),
    'content'    => Text::_('COM_FINDER_EMPTYSTATE_SEARCHES_CONTENT'),

    'controlFields' => $this->filterForm->renderControlFields(),
];

echo HTMLHelper::_('progressiveSynchronization.start', 'j-main-container');
?>
<div id="j-main-container" class="j-main-container">
    <?php echo LayoutHelper::render('joomla.content.emptystate', $displayData); ?>
</div>
<?php echo HTMLHelper::_('progressiveSynchronization.end'); ?>
