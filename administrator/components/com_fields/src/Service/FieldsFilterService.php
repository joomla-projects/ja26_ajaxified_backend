<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\CustomFields\GetFilterProviderEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Event\DispatcherInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Coordinates generic custom-field filtering for list models.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FieldsFilterService
{
    private const FIELD_PREFIX = 'customfield_';
    private const MAX_FILTERS = 32;

    public function __construct(
        private readonly MVCFactoryInterface $mvcFactory,
        private readonly DispatcherInterface $dispatcher,
        private readonly DatabaseInterface $database
    ) {
    }

    /**
     * Prepares eligible fields and distinguishes submitted selections from remembered state.
     *
     * @param   string  $context    Field context whose component settings govern eligibility.
     * @param   array   $state      Current filter state.
     * @param   array   $submitted  Filters explicitly submitted in this request.
     *
     * @return  PreparedFieldsFilter
     *
     * @since   __DEPLOY_VERSION__
     */
    public function prepare(
        string $context,
        User $user,
        array $categoryIds,
        string $language,
        array $state,
        array $submitted
    ): PreparedFieldsFilter {
        $model = $this->mvcFactory->createModel('Fields', 'Administrator', ['ignore_request' => true]);
        $model->setCurrentUser($user);
        $model->setState('filter.context', $context);
        $model->setState('filter.state', 1);
        $model->setState('filter.only_use_in_subform', 0);
        $model->setState('list.limit', 0);

        if ($categoryIds) {
            $model->setState('filter.assigned_cat_ids', array_merge([0], $categoryIds));
        }

        if ($language !== '') {
            $model->setState('filter.language', ['*', $language]);
        }

        PluginHelper::importPlugin('fields', null, true, $this->dispatcher);

        $items = $model->getItems();

        if ($items === false) {
            throw new \RuntimeException('Custom-field filter discovery failed.');
        }

        $fields              = [];
        $component           = explode('.', $context, 2)[0];
        $customFieldsEnabled = (int) ComponentHelper::getParams($component)->get('custom_fields_enable', 1);

        foreach ($items as $field) {
            if (!$customFieldsEnabled) {
                continue;
            }

            // FieldsModel skips its group-state predicate on the com_fields administrator list.
            // Keep discovery safe when a host model is used programmatically in that context.
            if ((int) ($field->group_id ?? 0) > 0 && (int) ($field->group_state ?? 0) !== 1) {
                continue;
            }

            if (!(int) $field->params->get('show_in_admin_list_filter', 0)) {
                continue;
            }

            $event = new GetFilterProviderEvent('onCustomFieldsGetFilterProvider', ['subject' => $field]);
            $this->dispatcher->dispatch($event->getName(), $event);
            $providers = $event->getArgument('result', []);

            if (\count($providers) !== 1) {
                continue;
            }

            $name          = self::FIELD_PREFIX . (int) $field->id;
            $fields[$name] = ['field' => $field, 'provider' => $providers[0]];
        }

        $dynamic  = [];
        $rejected = false;

        foreach ($state as $name => $value) {
            $name      = (string) $name;
            $validName = preg_match('/^' . self::FIELD_PREFIX . '[1-9][0-9]*$/D', $name) === 1;
            $ownedName = preg_match('/^' . self::FIELD_PREFIX . '(?:[0-9].*)?$/D', $name) === 1;

            if (!$ownedName) {
                continue;
            }

            $explicit = array_key_exists($name, $submitted);

            if (!$validName || !isset($fields[$name])) {
                if ($explicit && $this->isActive($value)) {
                    $rejected = true;
                }

                continue;
            }

            if (!$this->isActive($value)) {
                continue;
            }

            try {
                $normalised = $fields[$name]['provider']->normaliseValue($fields[$name]['field'], $value);
            } catch (\InvalidArgumentException) {
                if ($explicit) {
                    $rejected = true;
                }

                continue;
            }

            if ($normalised) {
                $dynamic[$name] = $normalised;
            }
        }

        if (\count($dynamic) > self::MAX_FILTERS) {
            if (array_intersect_key($dynamic, $submitted)) {
                $rejected = true;
            } else {
                $dynamic = [];
            }
        }

        ksort($dynamic, SORT_STRING);

        return new PreparedFieldsFilter($fields, $dynamic, $rejected);
    }

    /**
     * Adds eligible filter fields without replacing fields already on the Form.
     *
     * @param   bool  $setValues  Whether to load the prepared selections into the Form.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function addFilterFields(Form $form, PreparedFieldsFilter $prepared, bool $setValues = true): void
    {
        foreach ($prepared->getFields() as $name => $definition) {
            if ($form->getField($name, 'filter')) {
                continue;
            }

            $element = $definition['provider']->getFilterField($definition['field'], $name);

            if ($element->getName() !== 'field' || (string) $element['name'] !== $name) {
                throw new \UnexpectedValueException('A custom-field filter provider returned an invalid Form field definition.');
            }

            $form->setField($element, 'filter', false);

            if ($setValues) {
                $form->setValue($name, 'filter', $prepared->getActive()[$name] ?? []);
            }
        }
    }

    /**
     * Applies prepared filters using a trusted SQL expression for the item ID.
     *
     * @param   string  $itemIdExpression  Trusted item-ID SQL expression supplied by the host model.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function applyToQuery(
        QueryInterface $query,
        PreparedFieldsFilter $prepared,
        string $itemIdExpression
    ): void {
        if ($prepared->isRejected()) {
            $query->where('1 = 0');

            return;
        }

        $index      = 0;
        $serverType = $this->database->getServerType();

        if ($serverType === 'mysql') {
            $itemIdExpression = 'CONVERT(' . $itemIdExpression . ', CHAR)';
        } else {
            $itemIdExpression = $query->castAs('CHAR', $itemIdExpression);
        }

        foreach ($prepared->getActive() as $name => $value) {
            $definition = $prepared->getFields()[$name];
            $definition['provider']->applyFilter(
                $query,
                $this->database,
                $definition['field'],
                $value,
                $itemIdExpression,
                'cff' . $index++ . '_'
            );
        }
    }

    private function isActive(mixed $value): bool
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (\is_array($item) || \is_object($item) || ($item !== null && $item !== '')) {
                    return true;
                }
            }

            return false;
        }

        return $value !== null && $value !== '';
    }
}
