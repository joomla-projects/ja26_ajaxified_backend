<?php

namespace Joomla\Tests\Unit\Administrator\Components\Languages\View\Override;

use Joomla\Tests\Unit\UnitTestCase;

class HtmlViewAutosaveTest extends UnitTestCase
{
    public function testViewPublishesTheExactExistingOverrideWiring(): void
    {
        $source   = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_languages/src/View/Override/HtmlView.php');
        $template = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_languages/tmpl/override/edit.php');

        $this->assertStringContainsString("disable('com_languages.autosave.override')", $source);
        $this->assertStringContainsString("empty(\$this->item->key)", $source);
        $this->assertStringContainsString("'override-form'", $source);
        $this->assertStringContainsString("['key' =>", $source);
        $this->assertStringContainsString("'override' =>", $source);
        $this->assertStringContainsString("'both' =>", $source);
        $this->assertStringContainsString("'com_languages.override-autosave'", $source);
        $this->assertStringContainsString("joomla.autosave.status", $template);
        $this->assertStringContainsString("joomla.autosave.recovery", $template);
    }
}
