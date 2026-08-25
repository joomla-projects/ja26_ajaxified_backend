<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\Tests\Unit\UnitTestCase;

class HierarchicalMetadataIntegrationWiringTest extends UnitTestCase
{
    /**
     * @dataProvider contextProvider
     */
    public function testComponentOwnedWiring(string $component, string $entity, string $context, array $fields): void
    {
        $root       = JPATH_ADMINISTRATOR . '/components/' . $component;
        $controller = file_get_contents($root . '/src/Controller/' . ucfirst($entity) . 'Controller.php');
        $view       = file_get_contents($root . '/src/View/' . ucfirst($entity) . '/HtmlView.php');
        $template   = file_get_contents($root . '/tmpl/' . $entity . '/edit.php');
        $service    = file_get_contents($root . '/services/provider.php');
        $asset      = json_decode(file_get_contents(JPATH_ROOT . '/media_source/' . $component . '/joomla.asset.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('use AutosaveFormControllerTrait;', $controller);
        $this->assertStringContainsString("'" . $context . "'", $controller);
        $this->assertStringContainsString('captureAutosaveCanonicalResult', $controller);
        $this->assertStringContainsString("getAutosaveProvider('" . $context . "')", $view);
        $this->assertStringContainsString("'item-form'", $view);
        $this->assertStringContainsString('joomla.autosave.status', $template);
        $this->assertStringContainsString('joomla.autosave.recovery', $template);
        $this->assertStringContainsString('AutosaveProvider', $service);
        $this->assertContains($component . '.' . $entity . '-autosave', array_column($asset['assets'], 'name'));

        foreach ($fields as $field) {
            $this->assertStringContainsString("'" . $field . "'", $view);
        }
    }

    public static function contextProvider(): array
    {
        $rich = ['title', 'note', 'description', 'version_note', 'metadesc', 'metakey'];

        return [
            'category'    => ['com_categories', 'category', 'com_categories.category', $rich],
            'tag'         => ['com_tags', 'tag', 'com_tags.tag', $rich],
            'field group' => ['com_fields', 'group', 'com_fields.group', ['title', 'note', 'description']],
        ];
    }
}
