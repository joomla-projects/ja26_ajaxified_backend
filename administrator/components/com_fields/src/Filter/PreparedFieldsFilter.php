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
 * Immutable result of preparing custom-field list filters.
 *
 * @internal
 * @since  __DEPLOY_VERSION__
 */
final class PreparedFieldsFilter
{
    public function __construct(
        private readonly array $fields,
        private readonly array $active,
        private readonly bool $rejected
    ) {
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getActive(): array
    {
        return $this->active;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function getFingerprint(): string
    {
        return hash('sha256', json_encode([$this->rejected, $this->active], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
