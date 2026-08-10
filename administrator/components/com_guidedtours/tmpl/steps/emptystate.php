<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_guidedtours
 *
 * @copyright   (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \Joomla\Component\Guidedtours\Administrator\View\Steps\HtmlView $this */

$displayData = [
    'textPrefix' => 'COM_GUIDEDTOURS_STEPS',
    'formURL'    => 'index.php?option=com_guidedtours&view=steps',
    'helpURL'    => 'https://docs.joomla.org/Special:MyLanguage/Help5.x:Guided_Tours:_Steps',
    'icon'       => 'icon-map-signs',

    'controlFields' => $this->filterForm->renderControlFields(),
];

$user = $this->getCurrentUser();

if ($user->authorise('core.create', 'com_guidedtours')) {
    $displayData['createURL'] = 'index.php?option=com_guidedtours&task=step.add';
}

echo HTMLHelper::_('progressiveSynchronization.start', 'j-main-container');
?>
<div id="j-main-container" class="j-main-container">
    <?php echo LayoutHelper::render('joomla.content.emptystate', $displayData); ?>
</div>
<?php echo HTMLHelper::_('progressiveSynchronization.end'); ?>
