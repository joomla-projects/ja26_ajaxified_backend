<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2019 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Extension;

use Joomla\CMS\Autosave\AutosaveServiceInterface;
use Joomla\CMS\Autosave\AutosaveServiceTrait;
use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Categories\CategoryServiceTrait;
use Joomla\CMS\Extension\MVCComponent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Component class for com_fields
 *
 * @since  4.0.0
 */
class FieldsComponent extends MVCComponent implements CategoryServiceInterface, AutosaveServiceInterface
{
    use AutosaveServiceTrait;
    use CategoryServiceTrait;

    public function getAutosaveContexts(): array
    {
        return ['com_fields.group' => true];
    }

    /**
     * Returns the table for the count items functions for the given section.
     *
     * @param   ?string  $section  The section
     *
     * @return  string|null
     *
     * @since   4.0.0
     */
    protected function getTableNameForSection(?string $section = null)
    {
        return 'fields';
    }
}
