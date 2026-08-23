<?php

namespace Joomla\Tests\Unit\Administrator\Components\Guidedtours\View\Step;

use Joomla\Tests\Unit\UnitTestCase;

class HtmlViewAutosaveTest extends UnitTestCase
{
    public function testExactExistingStepConfiguration(): void
    {
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_guidedtours/src/View/Step/HtmlView.php');
        $this->assertStringContainsString("getLayout() !== 'edit' || (int) \$this->item->id <= 0", $source);
        $this->assertStringContainsString("getAutosaveProvider('com_guidedtours.step')", $source);
        $this->assertStringContainsString("'guidedtour-dates-form'", $source);
        $this->assertStringContainsString("'com_guidedtours.step-autosave'", $source);
        foreach (['position', 'target', 'title', 'description', 'type', 'url', 'interactive_type', 'note', 'required', 'requiredvalue'] as $field) {
            $this->assertStringContainsString("'$field'", $source);
        }
    }
}
