<?php

namespace Joomla\Tests\Unit\Administrator\Components\Finder\View\Filter;

use Joomla\Tests\Unit\UnitTestCase;

class HtmlViewAutosaveTest extends UnitTestCase
{
    public function testExistingEditUsesExactLiteralWiringAndIdZeroFallsBack(): void
    {
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_finder/src/View/Filter/HtmlView.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("\$this->getLayout() !== 'edit'", $source);
        $this->assertStringContainsString('authorizeCreate', $source);
        foreach (['title', 'alias', 'created', 'created_by', 'created_by_alias', 'state', 'w1', 'd1', 'w2', 'd2'] as $field) {
            $this->assertStringContainsString("'{$field}'", $source);
        }
        $this->assertStringContainsString("'com_finder.autosave.filter'", $source);
        $this->assertStringContainsString("'adminForm'", $source);
        $this->assertStringContainsString("'com_finder.filter-autosave'", $source);
        $this->assertStringContainsString("\$name === 'created_by' ? \$field->id . '_id'", $source);
    }
}
