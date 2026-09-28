<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.radio
 *
 * @copyright   (C) 2017 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Fields\Radio\Extension;

use Joomla\CMS\Event\CustomFields\BeforePrepareFieldEvent;
use Joomla\CMS\Fields\CustomFieldFilterProviderInterface;
use Joomla\Component\Fields\Administrator\Plugin\FieldsListPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fields Radio Plugin
 *
 * @since  3.7.0
 */
final class Radio extends FieldsListPlugin implements CustomFieldFilterProviderInterface, SubscriberInterface
{
    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   5.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onCustomFieldsBeforePrepareField' => 'beforePrepareField',
        ]);
    }

    /**
     * Returns the Radio field's administrator filter definition.
     *
     * @param   object  $field  The custom-field definition.
     * @param   string  $name   The coordinator-supplied Form field name.
     *
     * @return  \SimpleXMLElement  The filter field definition.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getFilterField(object $field, string $name): \SimpleXMLElement
    {
        return $this->getSelectionFilterField($field, $name);
    }

    /**
     * Normalises selected Radio options into canonical filter data.
     *
     * @param   object  $field  The custom-field definition.
     * @param   mixed   $value  The raw filter value.
     *
     * @return  array  The canonical selected values.
     *
     * @throws  \InvalidArgumentException  When an active value is invalid.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function normaliseValue(object $field, mixed $value): array
    {
        return $this->normaliseSelectionFilterValue($field, $value);
    }

    /**
     * Applies canonical Radio selections to the item query.
     *
     * @param   QueryInterface     $query             The host list query.
     * @param   DatabaseInterface  $database          The database connection.
     * @param   object             $field             The custom-field definition.
     * @param   array              $value             Canonical selected values.
     * @param   string             $itemIdExpression  Trusted coordinator-generated item-ID SQL expression.
     * @param   string             $bindPrefix        Unique generated bind-name prefix without a leading colon.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function applyFilter(
        QueryInterface $query,
        DatabaseInterface $database,
        object $field,
        array $value,
        string $itemIdExpression,
        string $bindPrefix
    ): void {
        $this->applySelectionFilter($query, $database, $field, $value, $itemIdExpression, $bindPrefix);
    }

    /**
     * Before prepares the field value.
     *
     * @param   BeforePrepareFieldEvent $event    The event instance.
     *
     * @return  void
     *
     * @since   3.7.0
     */
    public function beforePrepareField(BeforePrepareFieldEvent $event): void
    {
        if (!$this->getApplication()->isClient('api')) {
            return;
        }

        $field = $event->getField();

        if (!$this->isTypeSupported($field->type)) {
            return;
        }

        $options         = $this->getOptionsFromField($field);
        $field->apivalue = [];

        if (!empty($field->value)) {
            $field->apivalue = [$field->value => $options[$field->value]];
        }
    }
}
