<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.autosave
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\Autosave\Response;

use Joomla\CMS\Response\JsonResponse as CoreJsonResponse;
use Joomla\CMS\String\StringableInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Render the native response once through com_ajax's supported direct-result contract.
 *
 * @since  __DEPLOY_VERSION__
 */
final class JsonResponse extends CoreJsonResponse implements StringableInterface
{
    /** @since  __DEPLOY_VERSION__ */
    public function __toString(): string
    {
        return (string) parent::__toString();
    }
}
