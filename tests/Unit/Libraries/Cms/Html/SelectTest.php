<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  HTML
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\HTML;

use Joomla\CMS\HTML\Helpers\Select;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Tests\Unit\UnitTestCase;

class SelectTest extends UnitTestCase
{
    private array $formatOptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatOptions       = HTMLHelper::$formatOptions;
        HTMLHelper::$formatOptions = ['format.indent' => '', 'format.eol' => "\n"];
    }

    protected function tearDown(): void
    {
        HTMLHelper::$formatOptions = $this->formatOptions;
        parent::tearDown();
    }

    /**
     * @dataProvider selectionProvider
     */
    public function testOptionsPreserveLegacyAndOptInStrictSelection(array $options, mixed $selected, ?bool $strict, array $expected): void
    {
        $configuration = ['list.select' => $selected];

        if ($strict !== null) {
            $configuration['list.strict'] = $strict;
        }

        $html = Select::options($options, $configuration);
        $dom  = new \DOMDocument();
        @$dom->loadHTML('<select>' . $html . '</select>');
        $actual = [];

        foreach ((new \DOMXPath($dom))->query('//option[@selected]') as $option) {
            $actual[] = $option->getAttribute('value');
        }

        $this->assertSame($expected, $actual);
    }

    public static function selectionProvider(): iterable
    {
        $strings = [
            (object) ['value' => '0', 'text' => 'Zero'],
            (object) ['value' => '01', 'text' => 'Leading zero'],
            (object) ['value' => '1', 'text' => 'One'],
        ];
        $integers = [
            (object) ['value' => 1, 'text' => 'Integer one'],
            (object) ['value' => 2, 'text' => 'Integer two'],
        ];

        yield 'legacy omitted' => [$strings, ['1'], null, ['01', '1']];
        yield 'legacy explicit false' => [$strings, ['1'], false, ['01', '1']];
        yield 'strict array one' => [$strings, ['1'], true, ['1']];
        yield 'strict array leading zero' => [$strings, ['01'], true, ['01']];
        yield 'strict array zero' => [$strings, ['0'], true, ['0']];
        yield 'strict array two values' => [$strings, ['01', '1'], true, ['01', '1']];
        yield 'strict array empty' => [$strings, [], true, []];
        yield 'strict integer option with string selection' => [$integers, ['1'], true, ['1']];
        yield 'strict integer option with integer selection' => [$integers, [1], true, ['1']];
        yield 'scalar remains exact independently of array strictness' => [$strings, '1', true, ['1']];
    }

    public function testLegacyGroupsRemainDefaultAndCanBeDisabledForFlatData(): void
    {
        $options = [
            (object) ['value' => '<OPTGROUP>', 'text' => 'Group'],
            (object) ['value' => 'inside', 'text' => 'Inside'],
            (object) ['value' => '</OPTGROUP>', 'text' => 'End'],
        ];

        $legacy    = Select::options($options);
        $flat      = Select::options($options, ['groups' => false]);
        $legacyDom = new \DOMDocument();
        @$legacyDom->loadHTML('<select>' . $legacy . '</select>');
        $flatDom   = new \DOMDocument();
        @$flatDom->loadHTML('<select>' . $flat . '</select>');

        $this->assertCount(1, (new \DOMXPath($legacyDom))->query('//optgroup'));
        $this->assertCount(0, (new \DOMXPath($flatDom))->query('//optgroup'));
        $this->assertSame(
            ['<OPTGROUP>', 'inside', '</OPTGROUP>'],
            array_map(
                static fn (\DOMElement $option): string => $option->getAttribute('value'),
                iterator_to_array((new \DOMXPath($flatDom))->query('//option')),
            ),
        );
    }
}
