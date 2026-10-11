<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_banners
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Banners\View\Client;

use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests the Banner Client view's literal Autosave integration boundary.
 *
 * @since  __DEPLOY_VERSION__
 */
class HtmlViewAutosaveTest extends UnitTestCase
{
    /**
     * @testdox  Only the edit layout can activate Autosave and new records go through create authorization
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExistingRecordEligibilityAndNewRecordFallback(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("if (\$this->getLayout() !== 'edit') {", $source);
        $this->assertStringContainsString('AutosaveCreateProviderInterface', $source);
        $this->assertStringContainsString('AutosaveOperation::InitializeCreate', $source);
        $this->assertStringContainsString("\$targetId = null;", $source);
        $this->assertStringContainsString("getAutosaveProvider('com_banners.client')", $source);
        $this->assertStringContainsString("\$this->autosaveEnabled = true;", $source);
    }

    /**
     * @testdox  View wiring publishes the exact form, fields and component-owned asset
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExactFormFieldAndAssetConfiguration(): void
    {
        $source         = $this->viewSource();
        $expectedFields = [
            'name',
            'contact',
            'email',
            'extrainfo',
            'metakey',
            'metakey_prefix',
            'version_note',
            'purchase_type',
            'track_impressions',
            'track_clicks',
            'own_prefix',
        ];

        preg_match('/foreach \(\s*\[(.*?)\] as \$fieldName\s*\)/s', $source, $matches);
        preg_match_all("/'([^']+)'/", $matches[1] ?? '', $fieldMatches);

        $this->assertSame($expectedFields, $fieldMatches[1]);
        $this->assertStringContainsString("'com_banners.autosave.client'", $source);
        $this->assertStringContainsString("'client-form'", $source);
        $this->assertStringContainsString("'com_banners.client-autosave'", $source);
    }

    private function viewSource(): string
    {
        $source = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/com_banners/src/View/Client/HtmlView.php'
        );

        $this->assertIsString($source);

        return $source;
    }
}
