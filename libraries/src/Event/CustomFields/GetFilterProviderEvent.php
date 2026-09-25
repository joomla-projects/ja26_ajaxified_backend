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
use Joomla\CMS\Event\Result\ResultTypeObjectAware;
use Joomla\CMS\Fields\CustomFieldFilterProviderInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Event used to discover the filter provider for a verified custom field.
 *
 * @since  __DEPLOY_VERSION__
 */
class GetFilterProviderEvent extends CustomFieldsEvent implements ResultAwareInterface
{
    use ResultAware;
    use ResultTypeObjectAware;

    /**
     * Constructs the provider discovery event.
     *
     * @param   string  $name       The event name.
     * @param   array   $arguments  The event arguments.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct($name, array $arguments = [])
    {
        $this->resultAcceptableClasses  = [
            CustomFieldFilterProviderInterface::class,
        ];

        parent::__construct($name, $arguments);
    }

    /**
     * Returns the custom field being evaluated.
     *
     * @return  object
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getField(): object
    {
        return $this->getArgument('subject');
    }

    /**
     * Prevents direct assignment of the event result.
     *
     * @param   array  $value  The result value.
     *
     * @return  array
     *
     * @throws  \BadMethodCallException
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function onSetResult(array $value): array
    {
        throw new \BadMethodCallException(
            'You are not allowed to set the result argument directly. Use addResult() instead.'
        );
    }
}
