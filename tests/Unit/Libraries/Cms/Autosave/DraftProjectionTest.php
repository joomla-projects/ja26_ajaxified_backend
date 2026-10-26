<?php

/**
 * @package   Joomla.UnitTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Tests\Unit\UnitTestCase;

class DraftProjectionTest extends UnitTestCase
{
    public function testPreparedParametersProtectOnlyDeclaredSupportedControls(): void
    {
        $form = new Form('extension', ['control' => 'jform']);
        $form->setCurrentUser(new User());
        $form->load(
            '<form><fields name="params"><fieldset>'
            . '<field name="safe_text" type="text" autosave="true"/>'
            . '<field name="safe_boolean" type="checkbox" autosave="true"/>'
            . '<field name="safe_collection" type="list" multiple="true" autosave="true"><option value="a">A</option></field>'
            . '<field name="password" type="password" autosave="true"/>'
            . '<field name="secret" type="text" sensitive="true" autosave="true"/>'
            . '<field name="undeclared" type="text"/>'
            . '<field name="upload" type="file" autosave="true"/>'
            . '<field name="nested" type="subform" autosave="true"/>'
            . '</fieldset></fields></form>'
        );
        $keys = DraftProjection::groupKeys($form, 'params');
        $this->assertNotContains('params.undeclared', $keys);
        $fields = DraftProjection::describe($form, $keys);
        $this->assertSame(['params.safe_text', 'params.safe_boolean', 'params.safe_collection'], array_keys($fields));
        $this->assertSame(['text', 'checkbox', 'multiple'], array_column($fields, 'control'));
        $this->assertSame(
            ['params.safe_text' => '', 'params.safe_boolean' => '1', 'params.safe_collection' => ['a']],
            DraftProjection::normalize(['params.safe_text' => '', 'params.safe_boolean' => true, 'params.safe_collection' => ['a']], $fields)
        );

        $descriptor = DraftProjection::descriptor($form, (object) ['params' => ['safe_text' => 'Public', 'secret' => 'Sensitive']], 'com_example.item', '1', [], $keys);
        $this->assertSame('Public', $descriptor['values']['params.safe_text']);
        $this->assertStringNotContainsString('Sensitive', json_encode($descriptor));
    }

    /**
     * @dataProvider siteTemplateStyleForms
     */
    public function testSiteTemplateStyleFormsRespectExplicitParameterCoverage(string $template, bool $brandProtected): void
    {
        $form = new Form('style', ['control' => 'jform']);
        $form->setCurrentUser(new User());
        $this->assertTrue(
            $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_templates/forms/style.xml')
        );
        $this->assertTrue(
            $form->loadFile(JPATH_SITE . '/templates/' . $template . '/templateDetails.xml', false, '//config')
        );

        $fields = DraftProjection::describe($form, DraftProjection::groupKeys($form, 'params'));

        $this->assertSame($brandProtected, \array_key_exists('params.brand', $fields));
        $this->assertSame($brandProtected, \array_key_exists('params.systemFontBody', $fields));
    }

    public static function siteTemplateStyleForms(): array
    {
        return [
            'Cassiopeia'          => ['cassiopeia', true],
            'Cassiopeia Extended' => ['cassiopeia_extended', false],
        ];
    }

    /**
     * @dataProvider nativeParameterForms
     */
    public function testRealNativeParameterDeclarationsProtectPublicPrimitiveSiblings(string $path, string $group, string $protected, ?string $excluded): void
    {
        $form = new Form('native.parameters', ['control' => 'jform']);
        $form->setCurrentUser(new User());
        $this->assertTrue($form->loadFile(JPATH_ROOT . '/' . $path, true, str_ends_with($path, 'templateDetails.xml') ? '//config' : false));
        $keys = DraftProjection::groupKeys($form, $group);
        $this->assertContains($group . '.' . $protected, $keys);
        $fields = DraftProjection::describe($form, $keys);
        $this->assertArrayHasKey($group . '.' . $protected, $fields);
        if ($excluded !== null) {
            $this->assertSame($excluded, $form->getFieldAttribute($excluded, 'name', null, $group));
            $this->assertArrayNotHasKey($group . '.' . $excluded, $fields);
        }
    }

    public static function nativeParameterForms(): array
    {
        return [
            ['plugins/fields/text/params/text.xml', 'fieldparams', 'maxlength', null],
            ['plugins/fields/sql/params/sql.xml', 'fieldparams', 'multiple', 'query'],
            ['plugins/fields/list/params/list.xml', 'fieldparams', 'multiple', 'options'],
            ['administrator/components/com_modules/forms/advanced.xml', 'params', 'module_tag', 'style'],
            ['templates/cassiopeia/templateDetails.xml', 'params', 'brand', 'useFontScheme'],
        ];
    }
}
