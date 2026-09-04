<?php

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\Tests\Unit\UnitTestCase;

class SafeMetadataIntegrationWiringTest extends UnitTestCase
{
    /**
     * @dataProvider contextProvider
     */
    public function testControllerViewTemplateAndAssetWiring(
        string $component,
        string $entity,
        string $context,
        string $formId,
        array $fields
    ): void {
        $root       = JPATH_ADMINISTRATOR . '/components/' . $component;
        $controller = file_get_contents($root . '/src/Controller/' . ucfirst($entity) . 'Controller.php');
        $view       = file_get_contents($root . '/src/View/' . ucfirst($entity) . '/HtmlView.php');
        $template   = file_get_contents($root . '/tmpl/' . $entity . '/edit.php');
        $asset      = file_get_contents(JPATH_ROOT . '/media_source/' . $component . '/joomla.asset.json');

        $this->assertStringContainsString('use AutosaveFormControllerTrait;', $controller);
        $this->assertStringContainsString("'" . $context . "'", $controller);
        $this->assertStringContainsString('captureAutosaveCanonicalResult', $controller);
        $this->assertStringContainsString("'" . $context . "'", $view);
        $this->assertStringContainsString("'" . $formId . "'", $view);
        $this->assertStringContainsString("'" . $component . '.' . $entity . "-autosave'", $view);
        $this->assertStringContainsString('joomla.autosave.status', $template);
        $this->assertStringContainsString('joomla.autosave.recovery', $template);
        $this->assertStringContainsString('"name": "' . $component . '.' . $entity . '-autosave"', $asset);
        $this->assertStringContainsString('"com_autosave.integration-controller"', $asset);

        foreach ($fields as $field) {
            $this->assertStringContainsString("'" . $field . "'", $view);
        }
    }

    public static function contextProvider(): array
    {
        return [
            'workflow' => ['com_workflow', 'workflow', 'com_workflow.workflow', 'workflow-form', ['title', 'description']],
            'stage'    => ['com_workflow', 'stage', 'com_workflow.stage', 'workflow-form', ['title', 'description']],
            'language' => ['com_languages', 'language', 'com_languages.language', 'language-form', ['title', 'title_native', 'description', 'metadesc', 'sitename']],
            'group'    => ['com_users', 'group', 'com_users.group', 'group-form', ['title']],
            'level'    => ['com_users', 'level', 'com_users.level', 'level-form', ['title', 'rules']],
            'note'     => ['com_users', 'note', 'com_users.note', 'note-form', ['subject', 'body']],
        ];
    }
}
