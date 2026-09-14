<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Event\CustomFields;

use Joomla\CMS\Event\Result\ResultAware;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Event\Result\ResultTypeArrayAware;

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
    use ResultAware;
    use ResultTypeArrayAware;

    /**
     * Validate provider result data.
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
            throw new \InvalidArgumentException(\sprintf('Event %s only accepts Array results.', $this->getName()));
        }
    }

    /**
     * Reject direct replacement of the accumulated result.
     *
     * @param   array  $value  Replacement result.
     *
     * @return  never
     *
     * @throws  \BadMethodCallException
     *
     * @since  __DEPLOY_VERSION__
     */
    protected function onSetResult(array $value)
    {
        throw new \BadMethodCallException('You are not allowed to set the result argument directly. Use addResult() instead.');
    }

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
}
