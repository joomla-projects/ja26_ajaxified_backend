<?php

/**
 * @package   Joomla.UnitTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

trait ContextTestCases
{
    public function malformedContextProvider(): array
    {
        return [
            'empty'                => [''],
            'component only'       => ['com_example'],
            'uppercase component'  => ['com_Example.record'],
            'uppercase entity'     => ['com_example.Record'],
            'leading whitespace'   => [' com_example.record'],
            'trailing whitespace'  => ['com_example.record '],
            'internal whitespace'  => ['com_example.re cord'],
            'empty component'      => ['com_.record'],
            'empty entity'         => ['com_example.'],
            'extra segment'        => ['com_example.record.extra'],
            'path syntax'          => ['com_example../record'],
            'class syntax'         => ['Vendor\\Provider'],
            'wildcard'             => ['com_*.record'],
            'leading prefix'       => ['xcom_example.record'],
            'trailing suffix'      => ['com_example.record*'],
            'leading hyphen'       => ['com_-example.record'],
            'trailing hyphen'      => ['com_example-.record'],
            'empty hyphen segment' => ['com_example--addon.record'],
        ];
    }
}
