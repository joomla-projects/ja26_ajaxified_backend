<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  HTML
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Html;

use Joomla\CMS\HTML\Helpers\Select;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for the Select HTML helper.
 *
 * @since  __DEPLOY_VERSION__
 */
class SelectTest extends UnitTestCase
{
    /**
     * @testdox  Strict array selection compares option values as strings
     *
     * @dataProvider strictSelectionData
     *
     * @param   array  $selected  The selected values.
     * @param   array  $expected  The expected selected option values.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testStrictArraySelection(array $selected, array $expected): void
    {
        $html = Select::options($this->getNumericStringOptions(), [
            'list.select' => $selected,
            'list.strict' => true,
        ]);

        $this->assertSame($expected, $this->getSelectedValues($html));
    }

    /**
     * Provides strict selected values and expected matches.
     *
     * @return  iterable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function strictSelectionData(): iterable
    {
        yield 'one numeric string token' => [['01'], ['01']];
        yield 'multiple numeric string tokens' => [['1', '001'], ['1', '001']];
        yield 'integer token' => [[1], ['1']];
        yield 'string token' => [['1'], ['1']];
        yield 'empty selection' => [[], []];
    }

    /**
     * @testdox  Strict mode does not change null or scalar selection behaviour
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testStrictModePreservesNonArraySelectionBehaviour(): void
    {
        $options = $this->getNumericStringOptions();

        $this->assertSame([], $this->getSelectedValues(Select::options($options, ['list.strict' => true])));
        $this->assertSame(['01'], $this->getSelectedValues(Select::options($options, [
            'list.select' => '01',
            'list.strict' => true,
        ])));
    }

    /**
     * @testdox  Strict array selection distinguishes an empty token from no selected tokens
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testStrictSelectionOfEmptyStringOption(): void
    {
        $options = [HTMLHelper::_('select.option', '', 'Empty')];

        $this->assertSame([''], $this->getSelectedValues(Select::options($options, [
            'list.select' => [''],
            'list.strict' => true,
        ])));
        $this->assertSame([], $this->getSelectedValues(Select::options($options, [
            'list.select' => [],
            'list.strict' => true,
        ])));
    }

    /**
     * @testdox  Strict mode is opt in and explicit false preserves legacy matching
     *
     * @dataProvider legacySelectionData
     *
     * @param   array  $helperOptions  The helper options.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testLegacyArraySelectionIsPreserved(array $helperOptions): void
    {
        $this->assertSame(
            ['1', '01', '001'],
            $this->getSelectedValues(Select::options($this->getNumericStringOptions(), $helperOptions))
        );
    }

    /**
     * Provides legacy helper options.
     *
     * @return  iterable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function legacySelectionData(): iterable
    {
        yield 'option absent' => [['list.select' => ['01']]];
        yield 'explicit false' => [['list.select' => ['01'], 'list.strict' => false]];
    }

    /**
     * @testdox  Strict selection preserves option value and text escaping
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testStrictSelectionPreservesEscaping(): void
    {
        $html = Select::options(
            [HTMLHelper::_('select.option', '<value&"', '<Label & "quoted">')],
            ['list.select' => ['<value&"'], 'list.strict' => true]
        );

        $this->assertStringContainsString('value="&lt;value&amp;&quot;" selected="selected"', $html);
        $this->assertStringContainsString('&lt;Label &amp; &quot;quoted&quot;&gt;', $html);
    }

    /**
     * Returns three options whose numeric-looking string values are distinct in HTML.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getNumericStringOptions(): array
    {
        return [
            HTMLHelper::_('select.option', '1', 'One'),
            HTMLHelper::_('select.option', '01', 'Zero one'),
            HTMLHelper::_('select.option', '001', 'Zero zero one'),
        ];
    }

    /**
     * Extracts the selected option values from rendered option markup.
     *
     * @param   string  $html  The rendered option markup.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getSelectedValues(string $html): array
    {
        preg_match_all('/<option value="([^"]*)"[^>]* selected="selected"/', $html, $matches);

        return array_map('html_entity_decode', $matches[1]);
    }
}
