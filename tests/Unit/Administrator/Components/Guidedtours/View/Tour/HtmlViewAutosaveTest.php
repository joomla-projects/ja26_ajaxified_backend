<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_guidedtours
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Guidedtours\View\Tour;

use Joomla\Tests\Unit\UnitTestCase;

class HtmlViewAutosaveTest extends UnitTestCase
{
    public function testExistingEditRecordEligibilityAndNewRecordFallback(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("if (\$this->getLayout() !== 'edit') {", $source);
        $this->assertStringContainsString('AutosaveCreateProviderInterface', $source);
        $this->assertStringContainsString('AutosaveOperation::InitializeCreate', $source);
        $this->assertStringContainsString("getAutosaveProvider('com_guidedtours.tour')", $source);
        $this->assertStringContainsString("\$this->autosaveEnabled = true;", $source);
    }

    public function testExactFormFieldsOptionsAndAsset(): void
    {
        $source = $this->viewSource();
        preg_match('/foreach \(\[(.*?)\] as \$fieldName\)/s', $source, $matches);
        preg_match_all("/'([^']+)'/", $matches[1] ?? '', $fieldMatches);

        $this->assertSame(['title', 'uid', 'description', 'note', 'url', 'autostart'], $fieldMatches[1]);
        $this->assertStringContainsString("'com_guidedtours.autosave.tour'", $source);
        $this->assertStringContainsString("'guidedtours-form'", $source);
        $this->assertStringContainsString("'com_guidedtours.tour-autosave'", $source);
    }

    private function viewSource(): string
    {
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_guidedtours/src/View/Tour/HtmlView.php');
        $this->assertIsString($source);

        return $source;
    }
}
