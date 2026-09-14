<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2019 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Extension;

use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Categories\CategoryServiceTrait;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Component class for com_fields
 *
 * @since  4.0.0
 */
class FieldsComponent extends MVCComponent implements CategoryServiceInterface
{
    use CategoryServiceTrait;

    /**
     * Administrator list-filter service.
     *
     * @var    FieldsFilterService
     * @since  __DEPLOY_VERSION__
     */
    private FieldsFilterService $fieldsFilterService;

    /**
     * Set the administrator Custom Field list-filter service.
     *
     * @param   FieldsFilterService  $fieldsFilterService  Filter service.
     *
     * @return  void
     *
     * @since  __DEPLOY_VERSION__
     */
    public function setFieldsFilterService(FieldsFilterService $fieldsFilterService): void
    {
        $this->fieldsFilterService = $fieldsFilterService;
    }

    /**
     * Get the administrator Custom Field list-filter service.
     *
     * @return  FieldsFilterService
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getFieldsFilterService(): FieldsFilterService
    {
        return $this->fieldsFilterService;
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
