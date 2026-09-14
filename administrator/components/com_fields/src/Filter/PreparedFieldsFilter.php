<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Fields\Administrator\Filter;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Immutable result of Custom Field list-filter preparation.
 *
 * @since  __DEPLOY_VERSION__
 */
final class PreparedFieldsFilter
{
    /**
     * @param  string  $context     Fields context.
     * @param  array   $controls    Eligible control metadata keyed by field ID.
     * @param  array   $selections  Canonical selections keyed by field ID.
     * @param  array   $issues      Structured validation issues.
     * @param  bool    $rejected    Whether an explicit request was rejected.
     *
     * @since  __DEPLOY_VERSION__
     */
    public function __construct(
        private readonly string $context,
        private readonly array $controls,
        private readonly array $selections,
        private readonly array $issues = [],
        private readonly bool $rejected = false,
    ) {
    }

    /**
     * Get eligible control metadata keyed by field ID.
     *
     * @return  array
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getControls(): array
    {
        return $this->controls;
    }

    /**
     * Get canonical selections keyed by field ID.
     *
     * @return  array
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getSelections(): array
    {
        return $this->selections;
    }

    /**
     * Get structured preparation issues.
     *
     * @return  array
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Whether explicit invalid input must fail closed.
     *
     * @return  bool
     *
     * @since  __DEPLOY_VERSION__
     */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * Whether canonical selections will add query predicates.
     *
     * @return  bool
     *
     * @since  __DEPLOY_VERSION__
     */
    public function isActive(): bool
    {
        return $this->selections !== [];
    }

    /**
     * Get an order-independent cache fingerprint for query-affecting state.
     *
     * @return  string
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getFingerprint(): string
    {
        $selections = $this->selections;
        ksort($selections, SORT_NUMERIC);

        foreach ($selections as &$values) {
            sort($values, SORT_STRING);
        }

        return hash('sha256', json_encode([$this->context, $selections, $this->rejected], JSON_THROW_ON_ERROR));
    }

    /**
     * Get active filters using their dynamic form names.
     *
     * @return  array
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getActiveFilters(): array
    {
        $active = [];

        foreach ($this->selections as $fieldId => $values) {
            if (!isset($this->controls[$fieldId])) {
                continue;
            }

            $active['customfield_' . $fieldId] = $values;
        }

        return $active;
    }
}
