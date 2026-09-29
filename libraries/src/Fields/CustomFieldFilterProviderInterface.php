<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Fields;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Contract for a custom field type which can filter an item list.
 *
 * @since  __DEPLOY_VERSION__
 */
interface CustomFieldFilterProviderInterface
{
    /**
     * Returns one Joomla Form field for filtering the custom field.
     *
     * @param   object  $field  The custom-field definition.
     * @param   string  $name   The coordinator-supplied name to use for the Form field.
     *
     * @return  \SimpleXMLElement  The filter field definition.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getFilterField(object $field, string $name): \SimpleXMLElement;

    /**
     * Normalises raw filter state into provider-owned canonical data.
     *
     * Equivalent selections must produce the same deterministic, JSON-encodable
     * array. An empty array represents an inactive filter.
     *
     * @param   object  $field  The custom-field definition.
     * @param   mixed   $value  The raw filter value.
     *
     * @return  array  The canonical filter data.
     *
     * @throws  \InvalidArgumentException  When an active value is invalid.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function normaliseValue(object $field, mixed $value): array;

    /**
     * Applies canonical data, binding user-controlled values instead of interpolating them into SQL.
     *
     * @param   QueryInterface     $query             The host list query.
     * @param   DatabaseInterface  $database          The database connection.
     * @param   object             $field             The custom-field definition.
     * @param   array              $value             Canonical provider-owned filter data.
     * @param   string             $itemIdExpression  Trusted coordinator-generated SQL for the host item ID as text.
     * @param   string             $bindPrefix        Unique coordinator-generated bind-name prefix without a leading colon.
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
    ): void;
}
