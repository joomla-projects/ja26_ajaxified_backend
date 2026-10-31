<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Autosave;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A safe, transport-neutral Autosave domain failure.
 *
 * Error identifiers are stable lowercase snake-case tokens defined by the
 * operation that raises the failure.
 *
 * @since  __DEPLOY_VERSION__
 */
final class AutosaveException extends \RuntimeException
{
    /**
     * Constructor.
     *
     * @param   string  $errorCode  The stable public error identifier.
     * @param   string  $message    The non-sensitive public message.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(
        private readonly string $errorCode,
        string $message
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('The Autosave error identifier is invalid.');
        }

        parent::__construct($message);
    }

    /**
     * Return the stable public error identifier.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
