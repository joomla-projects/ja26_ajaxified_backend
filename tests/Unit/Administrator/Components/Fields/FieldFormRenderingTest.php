<?php

namespace Joomla\Tests\Unit\Administrator\Components\Fields;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

class FieldFormRenderingTest extends UnitTestCase
{
    public function testAdministratorFilterSwitchIsRenderedOnceInFormOptionsAndBindsBothValues(): void
    {
        $previous    = Factory::$application;
        $application = $this->createMock(CMSApplication::class);
        $document    = $this->createMock(HtmlDocument::class);
        $assets      = new class (new WebAssetRegistry()) extends WebAssetManager {
            public function __call($method, $arguments)
            {
                return $this;
            }
        };

        $application->method('getIdentity')->willReturn($this->createMock(User::class));
        $application->method('getDocument')->willReturn($document);
        $application->method('getInput')->willReturn(new Input(['option' => 'com_fields']));
        $application->method('getTemplate')->willReturn('atum');
        $document->method('getWebAssetManager')->willReturn($assets);
        Factory::$application = $application;

        try {
            $form = new Form('com_fields.field', ['control' => 'jform']);
            $this->assertTrue($form->loadFile(JPATH_ADMINISTRATOR . '/components/com_fields/forms/field.xml'));

            $xml         = $form->getXml();
            $definitions = $xml->xpath('//fields[@name="params"]//field[@name="show_in_admin_list_filter"]');
            $leafMarkup  = '';

            foreach ($form->getFieldset('formoptions') as $field) {
                $leafMarkup .= $field->renderField();
            }

            $this->assertCount(1, $definitions);
            $this->assertSame('formoptions', (string) $definitions[0]->xpath('parent::fieldset')[0]['name']);
            $this->assertSame(2, substr_count($leafMarkup, 'name="jform[params][show_in_admin_list_filter]"'));
            $field = $form->getField('show_in_admin_list_filter', 'params');
            $this->assertSame('0', (string) $field->value);
            $this->assertSame('joomla.form.field.radio.switcher', $field->layout);
            $this->assertStringContainsString('name="jform[params][hint]"', $leafMarkup);

            $dom = new \DOMDocument();
            @$dom->loadHTML('<!doctype html><html><body>' . $field->renderField() . '</body></html>');
            $xpath  = new \DOMXPath($dom);
            $label  = $xpath->query('//label')->item(0);
            $inputs = $xpath->query('//input[@name="jform[params][show_in_admin_list_filter]"]');
            $this->assertNotNull($label);
            $this->assertCount(2, $inputs);
            $this->assertSame(1, $xpath->query('//*[@id="' . $label->getAttribute('for') . '"]')->length);

            foreach (['0', '1'] as $value) {
                $form->bind(['params' => ['show_in_admin_list_filter' => $value]]);
                $this->assertSame($value, (string) $form->getValue('show_in_admin_list_filter', 'params'));
            }
        } finally {
            Factory::$application = $previous;
        }
    }
}
