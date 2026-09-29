<?php

namespace Joomla\Tests\Unit\Administrator\Components\Menus\View\Menu;

use Joomla\Tests\Unit\UnitTestCase;

class HtmlViewAutosaveTest extends UnitTestCase
{
    public function testExactExistingRecordConfiguration(): void
    {
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_menus/src/View/Menu/HtmlView.php');
        $this->assertStringContainsString("if (\$this->getLayout() !== 'edit') {", $source);
        $this->assertStringContainsString('AutosaveCreateProviderInterface', $source);
        $this->assertStringContainsString('AutosaveOperation::InitializeCreate', $source);
        $this->assertStringContainsString("getAutosaveProvider('com_menus.menu')", $source);
        $this->assertStringContainsString("foreach (['title', 'description'] as \$fieldName)", $source);
        $this->assertStringContainsString("'item-form'", $source);
        $this->assertStringContainsString("'com_menus.menu-autosave'", $source);
    }
}
