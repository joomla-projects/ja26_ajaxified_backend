<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\Tests\Unit\UnitTestCase;

class CanonicalRelationsIntegrationWiringTest extends UnitTestCase
{
    /** @dataProvider contextProvider */
    public function testComponentOwnedWiring(string $component, string $entity, string $context, string $formId, array $fields): void
    {
        $root       = JPATH_ADMINISTRATOR . '/components/' . $component;
        $controller = file_get_contents($root . '/src/Controller/' . ucfirst($entity) . 'Controller.php');
        $view       = file_get_contents($root . '/src/View/' . ucfirst($entity) . '/HtmlView.php');
        $template   = file_get_contents($root . '/tmpl/' . $entity . '/edit.php');
        $asset      = json_decode(file_get_contents(JPATH_ROOT . '/media_source/' . $component . '/joomla.asset.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('use AutosaveFormControllerTrait;', $controller);
        $this->assertStringContainsString("private const AUTOSAVE_CONTEXT      = '" . $context . "';", $controller);
        $this->assertStringContainsString('captureAutosaveCanonicalResult', $controller);
        $this->assertStringContainsString("getAutosaveProvider('" . $context . "')", $view);
        $this->assertStringContainsString("'" . $formId . "'", $view);
        $this->assertStringContainsString('joomla.autosave.status', $template);
        $this->assertStringContainsString('joomla.autosave.recovery', $template);
        $this->assertContains($component . '.' . $entity . '-autosave', array_column($asset['assets'], 'name'));

        foreach ($fields as $field) {
            $this->assertStringContainsString("'" . $field . "'", $view);
        }
    }

    public static function contextProvider(): array
    {
        return [
            'newsfeed'   => ['com_newsfeeds', 'newsfeed', 'com_newsfeeds.newsfeed', 'newsfeed-form', ['name', 'description', 'link', 'version_note', 'numarticles', 'cache_time', 'metadesc', 'metakey']],
            'transition' => ['com_workflow', 'transition', 'com_workflow.transition', 'workflow-form', ['title', 'description', 'from_stage_id', 'to_stage_id']],
        ];
    }
}
