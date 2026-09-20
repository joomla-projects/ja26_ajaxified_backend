<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Form
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Form\Field;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\User\User;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for ListField rendering.
 *
 * @since  __DEPLOY_VERSION__
 */
class ListFieldTest extends UnitTestCase
{
    /**
     * The previous application instance.
     *
     * @var  CMSWebApplicationInterface|null
     */
    private $application;

    /**
     * The previous language instance.
     *
     * @var  Language|null
     */
    private $language;

    /**
     * Provides the application services used by the fancy-select layout.
     *
     * @return  void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->application = Factory::$application;
        $this->language    = Factory::$language;

        $assets = $this->getMockBuilder(WebAssetManager::class)
            ->disableOriginalConstructor()
            ->addMethods(['usePreset', 'useScript'])
            ->getMock();
        $assets->method('usePreset')->willReturnSelf();
        $assets->method('useScript')->willReturnSelf();

        $document = $this->createMock(Document::class);
        $document->method('getScriptOptions')->willReturn([]);
        $document->method('getWebAssetManager')->willReturn($assets);

        $language = $this->createMock(Language::class);
        $language->method('_')->willReturnCallback(static fn ($string) => $string);

        $application = $this->createMock(CMSWebApplicationInterface::class);
        $application->method('getDocument')->willReturn($document);
        $application->method('getInput')->willReturn(new Input());
        $application->method('getLanguage')->willReturn($language);

        Factory::$application = $application;
        Factory::$language    = $language;
    }

    /**
     * Restores the previous application instance.
     *
     * @return  void
     */
    protected function tearDown(): void
    {
        Factory::$application = $this->application;
        Factory::$language    = $this->language;

        parent::tearDown();
    }

    /**
     * @testdox  XML strictselection reaches standard and fancy ListField layouts
     *
     * @dataProvider strictLayoutData
     *
     * @param   string  $layout            The ListField layout.
     * @param   string  $strictSelection   The enabled strictselection representation.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testStrictSelectionThroughListField(string $layout, string $strictSelection): void
    {
        $html = $this->renderField($layout, ' strictselection="' . $strictSelection . '"', ['01']);

        $this->assertSame(['01'], $this->getSelectedValues($html));
        $this->assertStringContainsString('name="filter[example][]"', $html);
        $this->assertStringContainsString('id="filter_example"', $html);
        $this->assertStringContainsString('multiple', $html);
        $this->assertStringContainsString('data-test="preserved"', $html);
        $this->assertStringNotContainsString('strictselection=', $html);
    }

    /**
     * @testdox  Legacy layout data without strictSelection preserves legacy matching
     *
     * @dataProvider layoutData
     *
     * @param   string  $layout  The ListField layout.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testLegacyLayoutDataWithoutStrictSelection(string $layout): void
    {
        $renderer = new FileLayout($layout);
        $html     = $renderer->render([
            'autofocus'     => false,
            'class'         => '',
            'description'   => '',
            'disabled'      => false,
            'hint'          => '',
            'id'            => 'legacy_list',
            'multiple'      => true,
            'name'          => 'legacy[]',
            'onchange'      => '',
            'readonly'      => false,
            'required'      => false,
            'size'          => null,
            'value'         => ['01'],
            'options'       => $this->getNumericStringOptions(),
            'dataAttribute' => '',
        ]);

        $this->assertSame(['1', '01', '001'], $this->getSelectedValues($html));
    }

    /**
     * @testdox  ListField defaults and explicit false preserve legacy matching
     *
     * @dataProvider legacyLayoutData
     *
     * @param   string  $layout     The ListField layout.
     * @param   string  $attribute  The strictselection XML attribute.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testLegacySelectionThroughListField(string $layout, string $attribute): void
    {
        $html = $this->renderField($layout, $attribute, ['01']);

        $this->assertSame(['1', '01', '001'], $this->getSelectedValues($html));
    }

    /**
     * @testdox  Read-only strict ListField renders one exact selection and preserves hidden values
     *
     * @dataProvider layoutData
     *
     * @param   string  $layout  The ListField layout.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadOnlyStrictSelectionThroughListField(string $layout): void
    {
        $html = $this->renderField($layout, ' strictselection="true" readonly="true"', ['01']);

        $this->assertSame(['01'], $this->getSelectedValues($html));
        $this->assertStringContainsString('<input type="hidden" name="filter[example][]" value="01">', $html);
    }

    /**
     * Provides the standard and fancy ListField layouts.
     *
     * @return  iterable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function layoutData(): iterable
    {
        yield 'standard' => ['joomla.form.field.list'];
        yield 'fancy' => ['joomla.form.field.list-fancy-select'];
    }

    /**
     * Provides enabled strictselection representations for both ListField layouts.
     *
     * @return  iterable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function strictLayoutData(): iterable
    {
        foreach (self::layoutData() as $name => [$layout]) {
            yield $name . ', true' => [$layout, 'true'];
            yield $name . ', 1' => [$layout, '1'];
        }
    }

    /**
     * Provides legacy ListField configurations.
     *
     * @return  iterable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function legacyLayoutData(): iterable
    {
        foreach (self::layoutData() as $name => [$layout]) {
            yield $name . ', absent' => [$layout, ''];
            yield $name . ', false' => [$layout, ' strictselection="false"'];
        }
    }

    /**
     * Renders a real Form ListField using the requested layout.
     *
     * @param   string  $layout      The layout name.
     * @param   string  $attributes  Additional field attributes.
     * @param   array   $value       The selected values.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function renderField(string $layout, string $attributes, array $value): string
    {
        $form = new Form('strict-selection', ['control' => 'filter']);
        $form->setCurrentUser(new User());
        $form->load(
            '<form><field name="example" type="list" layout="' . $layout . '" multiple="true"'
            . ' data-test="preserved"' . $attributes . '>'
            . '<option value="1">One</option>'
            . '<option value="01">Zero one</option>'
            . '<option value="001">Zero zero one</option>'
            . '</field></form>'
        );

        return $form->getInput('example', null, $value);
    }

    /**
     * Returns three options whose numeric-looking string values are distinct in HTML.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getNumericStringOptions(): array
    {
        return [
            (object) ['value' => '1', 'text' => 'One'],
            (object) ['value' => '01', 'text' => 'Zero one'],
            (object) ['value' => '001', 'text' => 'Zero zero one'],
        ];
    }

    /**
     * Extracts selected option values from a rendered select.
     *
     * @param   string  $html  Rendered field markup.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getSelectedValues(string $html): array
    {
        preg_match_all('/<option value="([^"]*)"[^>]* selected="selected"/', $html, $matches);

        return array_map('html_entity_decode', $matches[1]);
    }
}
