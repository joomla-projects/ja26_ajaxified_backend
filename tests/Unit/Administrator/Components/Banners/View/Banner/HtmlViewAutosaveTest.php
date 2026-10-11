<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_banners
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Banners\View\Banner;

use Joomla\Tests\Unit\UnitTestCase;

class HtmlViewAutosaveTest extends UnitTestCase
{
    public function testExistingRecordEligibilityAndCreateModeAuthorization(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("if (\$this->getLayout() !== 'edit') {", $source);
        $this->assertStringContainsString('AutosaveCreateProviderInterface', $source);
        $this->assertStringContainsString('AutosaveOperation::InitializeCreate', $source);
        $this->assertStringContainsString("\$target   = null;", $source);
        $this->assertStringContainsString("getAutosaveProvider('com_banners.banner')", $source);
        $this->assertStringContainsString("\$this->autosaveEnabled = true;", $source);
    }

    public function testPublishesExactFormFieldsContextAndAsset(): void
    {
        $source = $this->viewSource();
        preg_match('/\$fieldGroups\s*=\s*\[(.*?)\];/s', $source, $matches);
        preg_match_all("/'([^']+)'\s*=>/", $matches[1] ?? '', $fields);

        $this->assertSame([
            'name', 'catid', 'cid', 'alias', 'description', 'type', 'custombannercode', 'clickurl',
            'version_note', 'publish_up', 'publish_down', 'imageurl', 'width', 'height', 'alt',
            'metakey', 'metakey_prefix', 'own_prefix',
        ], $fields[1]);
        $this->assertStringContainsString("'com_banners.autosave.banner'", $source);
        $this->assertStringContainsString("'banner-form'", $source);
        $this->assertStringContainsString("'com_banners.banner-autosave'", $source);
    }

    private function viewSource(): string
    {
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_banners/src/View/Banner/HtmlView.php');
        $this->assertIsString($source);

        return $source;
    }
}
