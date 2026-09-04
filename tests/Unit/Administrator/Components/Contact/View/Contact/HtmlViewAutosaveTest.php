<?php

namespace Joomla\Tests\Unit\Administrator\Components\Contact\View\Contact;

use Joomla\Tests\Unit\UnitTestCase;

class HtmlViewAutosaveTest extends UnitTestCase
{
    public function testExistingEditAndModalActivateWhileIdZeroAndOtherLayoutsFallBack(): void
    {
        $source = $this->source();
        $this->assertStringContainsString("!\\in_array(\$this->getLayout(), ['edit', 'modal'], true)", $source);
        $this->assertStringContainsString('authorizeCreate', $source);
        $this->assertStringContainsString("getAutosaveProvider('com_contact.contact')", $source);
    }

    public function testExactFormFieldsContextAndAssetAreLiteral(): void
    {
        $source = $this->source();
        foreach (['name', 'catid', 'email_to', 'telephone', 'mobile', 'address', 'misc', 'image', 'publish_up', 'publish_down', 'metadesc'] as $field) {
            $this->assertStringContainsString("'{$field}'", $source);
        }
        foreach (['user_id', 'params', 'associations', 'com_fields'] as $excluded) {
            $this->assertStringNotContainsString("'{$excluded}'", substr($source, strpos($source, '$names = ['), strpos($source, '];', strpos($source, '$names = [')) - strpos($source, '$names = [')));
        }
        $this->assertStringContainsString("'com_contact.autosave.contact'", $source);
        $this->assertStringContainsString("'contact-form'", $source);
        $this->assertStringContainsString("'com_contact.contact-autosave'", $source);
    }

    private function source(): string
    {
        $source = file_get_contents(JPATH_ADMINISTRATOR . '/components/com_contact/src/View/Contact/HtmlView.php');
        $this->assertIsString($source);
        return $source;
    }
}
