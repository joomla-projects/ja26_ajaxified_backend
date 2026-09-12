<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Event\CustomFields;

use Joomla\CMS\Event\Result\ResultAwareInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Event used by a fields plugin to declare administrator list-filter options.
 *
 * Each result is an array with an options key containing a list of arrays with
 * string value and text keys. Administrator filters always support multiple
 * selections, independently of the edit field cardinality.
 *
 * @since  __DEPLOY_VERSION__
 */
final class GetFilterOptionsEvent extends CustomFieldsEvent implements ResultAwareInterface
{
    /**
     * Get the field definition.
     *
     * @return  object
     *
     * @since  __DEPLOY_VERSION__
     */
    public function getField(): object
    {
        return $this->arguments['subject'];
    }

    /**
     * Append a result to the event.
     *
     * @param   mixed  $data  Result data.
     *
     * @return  void
     *
     * @since  __DEPLOY_VERSION__
     */
    public function addResult($data): void
    {
        $this->typeCheckResult($data);

        $this->arguments['result'] ??= [];
        $this->arguments['result'][] = $data;
    }

    /**
     * Validate result data.
     *
     * @param   mixed  $data  Result data.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException
     *
     * @since  __DEPLOY_VERSION__
     */
    public function typeCheckResult($data): void
    {
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(
                \sprintf('Event %s only accepts Array results.', $this->getName())
            );
        }
    }

    /**
     * Prevent replacing the result collection directly.
     *
     * @param   array  $value  Result value.
     *
     * @return  array
     *
     * @throws  \BadMethodCallException
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function onSetResult(array $value): array
    {
        throw new \BadMethodCallException(
            'You are not allowed to set the result argument directly. Use addResult() instead.'
        );
    }
}
