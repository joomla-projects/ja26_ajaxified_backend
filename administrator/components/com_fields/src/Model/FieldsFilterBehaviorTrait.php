<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Model;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\Component\Fields\Administrator\Extension\FieldsComponent;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Database\QueryInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reusable custom-field filtering behavior for administrator list models.
 *
 * @since  __DEPLOY_VERSION__
 */
trait FieldsFilterBehaviorTrait
{
    /**
     * Prepared custom-field filters for this model lifecycle.
     *
     * @var    PreparedFieldsFilter|null
     * @since  __DEPLOY_VERSION__
     */
    private ?PreparedFieldsFilter $preparedFieldsFilter = null;

    /**
     * Shared fields-filter coordinator.
     *
     * @var    FieldsFilterService|null
     * @since  __DEPLOY_VERSION__
     */
    private ?FieldsFilterService $fieldsFilterService = null;

    /**
     * Prepares and synchronises custom-field filter state from host-supplied scope.
     *
     * @param   CMSWebApplicationInterface  $app              The current application.
     * @param   string                      $context          The custom-field context.
     * @param   integer[]                   $categoryIds      The effective category scope.
     * @param   string                      $language         The effective language scope.
     * @param   array                       $submitted        Filter values submitted by this request.
     * @param   array                       $previousFilters  Filter state before request processing.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function prepareFieldsFilter(
        CMSWebApplicationInterface $app,
        string $context,
        array $categoryIds,
        string $language,
        array $submitted,
        array $previousFilters
    ): void {
        $filterState = (array) $app->getUserState($this->context . '.filter', []);

        $this->preparedFieldsFilter = $this->getFieldsFilterService()->prepare(
            $context,
            $this->getCurrentUser(),
            $categoryIds,
            $language,
            $filterState,
            $submitted
        );

        $oldDynamic = [];

        foreach ($previousFilters as $name => $value) {
            if ($this->isCustomFieldFilterStateName((string) $name)) {
                $oldDynamic[$name] = $value;
                $this->setState('filter.' . $name, []);
            }
        }

        foreach ($filterState as $name => $value) {
            if ($this->isCustomFieldFilterStateName((string) $name)) {
                unset($filterState[$name]);
                $this->setState('filter.' . $name, []);
            }
        }

        foreach ($this->preparedFieldsFilter->getActive() as $name => $value) {
            $filterState[$name] = $value;
            $this->setState('filter.' . $name, $value);
        }

        $app->setUserState($this->context . '.filter', $filterState);

        $formState         = $app->getUserState($this->context, new \stdClass());
        $formState->filter = isset($formState->filter) ? (array) $formState->filter : [];

        foreach (array_keys($formState->filter) as $name) {
            if ($this->isCustomFieldFilterStateName((string) $name)) {
                unset($formState->filter[$name]);
            }
        }

        foreach ($this->preparedFieldsFilter->getActive() as $name => $value) {
            $formState->filter[$name] = $value;
        }

        $app->setUserState($this->context, $formState);

        if ($oldDynamic !== $this->preparedFieldsFilter->getActive()) {
            $app->getInput()->set('limitstart', 0);
            $app->setUserState($this->context . '.limitstart', 0);
            $this->setState('list.start', 0);
        }

        if ($this->preparedFieldsFilter->isRejected()) {
            $app->getLanguage()->load('com_fields', JPATH_ADMINISTRATOR);
            $app->enqueueMessage(Text::_('COM_FIELDS_FILTER_INVALID_SELECTION'), 'warning');
        }
    }

    /**
     * Adds prepared custom-field filters to a host filter Form.
     *
     * @param   Form     $form       The host filter Form.
     * @param   boolean  $setValues  Whether to set prepared values on the Form.
     *
     * @return  void
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function addFieldsFiltersToForm(Form $form, bool $setValues = true): void
    {
        if ($this->preparedFieldsFilter) {
            $this->getFieldsFilterService()->addFilterFields($form, $this->preparedFieldsFilter, $setValues);
        }
    }

    /**
     * Adds the prepared custom-field fingerprint to a host store identifier.
     *
     * @param   string  $id  The host store identifier.
     *
     * @return  string
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function addFieldsFilterStoreId(string $id): string
    {
        if ($this->preparedFieldsFilter) {
            $id .= ':' . $this->preparedFieldsFilter->getFingerprint();
        }

        return $id;
    }

    /**
     * Merges canonical custom-field filters into native active filters.
     *
     * @param   array  $active  The native active filters.
     *
     * @return  array
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function mergeFieldsActiveFilters(array $active): array
    {
        if ($this->preparedFieldsFilter) {
            $active = array_merge($active, $this->preparedFieldsFilter->getActive());
        }

        return $active;
    }

    /**
     * Applies prepared custom-field filters using a host-supplied item-ID expression.
     *
     * @param   QueryInterface  $query             The host list query.
     * @param   string          $itemIdExpression  The trusted item-ID expression.
     *
     * @return  void
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function applyPreparedFieldsFilters(QueryInterface $query, string $itemIdExpression): void
    {
        if ($this->preparedFieldsFilter) {
            $this->getFieldsFilterService()->applyToQuery($query, $this->preparedFieldsFilter, $itemIdExpression);
        }
    }

    /**
     * Tests whether a state key belongs to the numeric custom-field filter namespace.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function isCustomFieldFilterStateName(string $name): bool
    {
        return preg_match('/^customfield_(?:[0-9].*)?$/D', $name) === 1;
    }

    /**
     * Returns the shared custom-field filter service.
     *
     * @since  __DEPLOY_VERSION__
     */
    private function getFieldsFilterService(): FieldsFilterService
    {
        if ($this->fieldsFilterService === null) {
            $component = Factory::getApplication()->bootComponent('com_fields');

            if (!$component instanceof FieldsComponent) {
                throw new \UnexpectedValueException('The com_fields component does not provide a custom-field filtering service.');
            }

            $this->fieldsFilterService = $component->getFieldsFilterService();
        }

        return $this->fieldsFilterService;
    }
}
