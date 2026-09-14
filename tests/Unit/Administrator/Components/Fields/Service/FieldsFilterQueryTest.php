<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Service;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Language;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\Component\Fields\Administrator\Filter\PreparedFieldsFilter;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\DatabaseQuery;
use Joomla\Event\DispatcherInterface;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

class FieldsFilterQueryTest extends UnitTestCase
{
    private mixed $previousApplication;
    private mixed $previousComponents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApplication = Factory::$application;
        $components                = new \ReflectionProperty(ComponentHelper::class, 'components');
        $this->previousComponents  = $components->getValue();
        $components->setValue(null, [
            'com_content' => new ComponentRecord(['option' => 'com_content', 'enabled' => 1]),
        ]);

        $application = $this->createMock(CMSApplication::class);
        $document    = $this->createMock(HtmlDocument::class);
        $assets      = new class (new WebAssetRegistry()) extends WebAssetManager {
            public function __call($method, $arguments)
            {
                return $this;
            }
        };
        $application->method('getIdentity')->willReturn(new User());
        $application->method('getLanguage')->willReturn(new Language('en-GB'));
        $application->method('getDocument')->willReturn($document);
        $application->method('getInput')->willReturn(new Input(['option' => 'com_content']));
        $document->method('getWebAssetManager')->willReturn($assets);
        Factory::$application = $application;
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $components           = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setValue(null, $this->previousComponents);

        parent::tearDown();
    }

    public function testTwoFieldsBindIndependentValuesOnOuterQuery(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getServerType')->willReturn('mysql');
        $db->method('quote')->willReturnCallback(static fn ($value) => "'" . $value . "'");
        $db->method('quoteName')->willReturnCallback(static function ($name, $alias = null) {
            $quoted = '`' . $name . '`';

            return $alias ? $quoted . ' AS `' . $alias . '`' : $quoted;
        });
        $db->method('createQuery')->willReturnCallback(fn () => $this->getWorkingQuery($db));

        $query    = $this->getWorkingQuery($db)->select('*')->from('items AS a');
        $existing = 'published';
        $query->where('a.state = :state')->bind(':state', $existing);
        $prepared = new PreparedFieldsFilter(
            'com_content.article',
            [7 => [], 12 => []],
            [7 => ['india', 'japan'], 12 => ['high']],
        );

        $this->service($db)->applyToQuery($query, $prepared, 'a.id', 'integer');

        $sql = (string) $query;
        $this->assertSame(2, substr_count($sql, 'EXISTS'));
        $this->assertStringContainsString('CONCATENATE(a.id', $sql);
        $this->assertSame(3, substr_count($sql, 'BINARY `cffv'));
        $this->assertCount(6, $query->getBounded());
        $this->assertSame('published', $query->getBounded(':state')->value);
        $this->assertSame(
            [7, 'india', 'japan', 12, 'high'],
            array_values(array_map(static fn ($bound) => $bound->value, array_filter(
                $query->getBounded(),
                static fn ($key) => $key !== ':state',
                ARRAY_FILTER_USE_KEY,
            ))),
        );
    }

    public function testRejectedFilterFailsClosedAndInactiveFilterDoesNothing(): void
    {
        $db      = $this->createMock(DatabaseInterface::class);
        $service = $this->service($db);

        $rejected = $this->getWorkingQuery($db)->select('*')->from('items');
        $service->applyToQuery($rejected, new PreparedFieldsFilter('x.y', [], [], [['code' => 'bad']], true), 'a.id');
        $this->assertStringContainsString('1 = 0', (string) $rejected);

        $inactive = $this->getWorkingQuery($db)->select('*')->from('items');
        $before   = (string) $inactive;
        $service->applyToQuery($inactive, new PreparedFieldsFilter('x.y', [], []), 'a.id');
        $this->assertSame($before, (string) $inactive);
    }

    public function testFormControlsAreFlatAndBindWithoutChangingNativeFilters(): void
    {
        $prepared = new PreparedFieldsFilter(
            'com_content.article',
            [7 => [
                'label'       => 'Region',
                'description' => 'Article region',
                'options'     => [
                    ['value' => '0', 'text' => 'Zero'],
                    ['value' => '01', 'text' => 'Leading zero'],
                ],
            ]],
            [7 => ['0', '01']],
        );
        $service = $this->service();
        $form    = new Form('com_content.articles.filter', ['control' => '']);
        $form->load('<form><fields name="filter"><field name="search" type="text" /></fields></form>');
        $form->setValue('search', 'filter', 'unchanged');

        $service->augmentForm($form, $prepared);
        $service->bindForm($form, $prepared);

        $field = $form->getField('customfield_7', 'filter');
        $this->assertNotNull($field);
        $this->assertSame('filter[customfield_7][]', $field->name);
        $this->assertSame('Select Region', $field->hint);
        $this->assertSame(['0', '01'], $field->value);
        $this->assertSame(['0', '01'], array_map(static fn ($option) => (string) $option->value, $field->options));

        $service->bindForm($form, new PreparedFieldsFilter('com_content.article', $prepared->getControls(), []));
        $this->assertSame([], $form->getValue('customfield_7', 'filter'));
        $this->assertSame('unchanged', $form->getValue('search', 'filter'));
    }

    /**
     * @dataProvider listLayoutProvider
     */
    public function testRenderedFilterIsStrictFlatAndEscaped(string $layout): void
    {
        $prepared = new PreparedFieldsFilter('com_content.article', [7 => [
            'label'       => 'Filter "label" & <b>markup</b>',
            'description' => 'Description &lt;i&gt;text&lt;/i&gt;',
            'options'     => [
                ['value' => '<OPTGROUP>', 'text' => 'Opening "group" & label'],
                ['value' => '</OPTGROUP>', 'text' => '&lt;b&gt;Encoded markup&lt;/b&gt;'],
                ['value' => '01', 'text' => 'Leading zero'],
                ['value' => '1', 'text' => 'One'],
                ['value' => 'a&b', 'text' => 'Fish & Chips'],
                ['value' => 'attribute', 'text' => '" onmouseover="alert(1)'],
            ],
        ]], [7 => ['</OPTGROUP>', '1']]);
        $form = new Form('com_content.articles.filter', ['control' => '']);
        $form->load('<form><fields name="filter" /></form>');
        $service = $this->service();
        $service->augmentForm($form, $prepared);
        $service->bindForm($form, $prepared);
        $form->setFieldAttribute('customfield_7', 'layout', $layout, 'filter');

        $xpath  = $this->render($form->getField('customfield_7', 'filter')->renderField());
        $label  = $xpath->query('//label')->item(0);
        $select = $xpath->query('//select')->item(0);
        $actual = [];

        foreach ($xpath->query('//select/option') as $option) {
            $actual[$option->getAttribute('value')] = $option->textContent;
        }

        $selected = array_map(
            static fn (\DOMElement $option): string => $option->getAttribute('value'),
            iterator_to_array($xpath->query('//option[@selected]')),
        );

        $this->assertCount(0, $xpath->query('//optgroup'));
        $this->assertSame($select->getAttribute('id'), $label->getAttribute('for'));
        $this->assertSame('Filter "label" & <b>markup</b>', trim($label->textContent));
        $this->assertSame([
            '<OPTGROUP>'  => 'Opening "group" & label',
            '</OPTGROUP>' => '<b>Encoded markup</b>',
            '01'          => 'Leading zero',
            '1'           => 'One',
            'a&b'         => 'Fish & Chips',
            'attribute'   => '" onmouseover="alert(1)',
        ], $actual);
        $this->assertSame(['</OPTGROUP>', '1'], $selected);
        $this->assertCount(0, $xpath->query('//*[@onmouseover] | //label//b | //option//b'));
    }

    /**
     * @dataProvider listLayoutProvider
     */
    public function testReadonlyListUsesStrictFlatOptionsAndFaithfulHiddenValues(string $layout): void
    {
        $form = new Form('strict-readonly', ['control' => '']);
        $form->load('<form><field name="code" type="list" multiple="true" strict="true" groups="false" readonly="true" layout="'
            . $layout . '"><option value="01">Leading zero</option><option value="1">One</option>'
            . '<option value="&lt;OPTGROUP&gt;">Sentinel</option></field></form>');
        $form->bind(['code' => ['1', '<OPTGROUP>']]);
        $xpath = $this->render($form->getField('code')->input);

        $selected = array_map(
            static fn (\DOMElement $option): string => $option->getAttribute('value'),
            iterator_to_array($xpath->query('//option[@selected]')),
        );
        $hidden = array_map(
            static fn (\DOMElement $input): string => $input->getAttribute('value'),
            iterator_to_array($xpath->query('//input[@type="hidden"]')),
        );

        $this->assertSame(['1', '<OPTGROUP>'], $selected);
        $this->assertSame(['1', '<OPTGROUP>'], $hidden);
        $this->assertCount(0, $xpath->query('//optgroup'));
        $this->assertSame('disabled', $xpath->query('//select')->item(0)->getAttribute('disabled'));
    }

    /**
     * @dataProvider listLayoutProvider
     */
    public function testDisabledListUsesStrictFlatOptions(string $layout): void
    {
        $form = new Form('strict-disabled', ['control' => '']);
        $form->load('<form><field name="code" type="list" multiple="true" strict="true" groups="false" disabled="true" layout="'
            . $layout . '"><option value="01">Leading zero</option><option value="1">One</option>'
            . '<option value="&lt;/OPTGROUP&gt;">Sentinel</option></field></form>');
        $form->bind(['code' => ['1']]);
        $xpath = $this->render($form->getField('code')->input);

        $this->assertCount(0, $xpath->query('//optgroup'));
        $this->assertCount(1, $xpath->query('//option[@selected and @value="1"]'));
        $this->assertCount(0, $xpath->query('//option[@selected and @value="01"]'));
        $this->assertSame('disabled', $xpath->query('//select')->item(0)->getAttribute('disabled'));
    }

    /**
     * @dataProvider listLayoutProvider
     */
    public function testListFieldPreservesLegacySelectionWhenStrictIsOmittedOrDisabled(string $layout): void
    {
        foreach (['', ' strict="false"'] as $strictAttribute) {
            $form = new Form('legacy-selection', ['control' => '']);
            $form->load('<form><field name="code" type="list" multiple="true" layout="' . $layout . '"'
                . $strictAttribute . '><option value="01">Leading zero</option><option value="1" disabled="true"'
                . ' class="documented-class">One</option></field></form>');
            $form->bind(['code' => ['1']]);
            $xpath = $this->render($form->getField('code')->input);

            $selected = array_map(
                static fn (\DOMElement $option): string => $option->getAttribute('value'),
                iterator_to_array($xpath->query('//option[@selected]')),
            );

            $this->assertSame(['01', '1'], $selected);
            $this->assertSame('disabled', $xpath->query('//option[@value="1"]')->item(0)->getAttribute('disabled'));
            $this->assertSame('documented-class', $xpath->query('//option[@value="1"]')->item(0)->getAttribute('class'));
        }
    }

    public static function listLayoutProvider(): iterable
    {
        yield 'basic list' => ['joomla.form.field.list'];
        yield 'fancy list' => ['joomla.form.field.list-fancy-select'];
    }

    /**
     * @dataProvider listLayoutProvider
     */
    public function testRenderedFilterDecodesOnlyOneStoredEntityLayer(string $layout): void
    {
        $prepared = new PreparedFieldsFilter('com_content.article', [7 => [
            'label'       => 'Encoded',
            'description' => '',
            'options'     => [
                ['value' => 'once', 'text' => '&lt;b&gt;Once&lt;/b&gt;'],
                ['value' => 'twice', 'text' => '&amp;lt;b&amp;gt;Twice&amp;lt;/b&amp;gt;'],
            ],
        ]], [7 => []]);
        $form = new Form('com_content.articles.filter', ['control' => '']);
        $form->load('<form><fields name="filter" /></form>');
        $service = $this->service();
        $service->augmentForm($form, $prepared);
        $service->bindForm($form, $prepared);
        $form->setFieldAttribute('customfield_7', 'layout', $layout, 'filter');

        $xpath  = $this->render($form->getField('customfield_7', 'filter')->renderField());
        $actual = [];

        foreach ($xpath->query('//select/option') as $option) {
            $actual[$option->getAttribute('value')] = $option->textContent;
        }

        // Exactly one stored entity layer is decoded: single-encoded text becomes
        // markup-looking text, double-encoded text keeps its literal layer, so an
        // accidental extra or missing decode cannot pass silently.
        $this->assertSame([
            'once'  => '<b>Once</b>',
            'twice' => '&lt;b&gt;Twice&lt;/b&gt;',
        ], $actual);
        $this->assertCount(0, $xpath->query('//select//b'));
    }

    public function testFancyFilterEscapesHintThroughTheFinalAttributeBoundary(): void
    {
        $label    = 'Filter "label" & <b>markup</b>';
        $prepared = new PreparedFieldsFilter('com_content.article', [7 => [
            'label'       => $label,
            'description' => '',
            'options'     => [['value' => '01', 'text' => 'Leading zero']],
        ]], [7 => []]);
        $form = new Form('com_content.articles.filter', ['control' => '']);
        $form->load('<form><fields name="filter" /></form>');
        $service = $this->service();
        $service->augmentForm($form, $prepared);
        $form->setFieldAttribute('customfield_7', 'layout', 'joomla.form.field.list-fancy-select', 'filter');

        $html = $form->getField('customfield_7', 'filter')->renderField();

        $this->assertStringContainsString(
            ' placeholder="Select Filter &quot;label&quot; &amp; &lt;b&gt;markup&lt;/b&gt;" ',
            $html
        );

        $placeholder = $this->render($html)->query('//*[@placeholder]')->item(0);
        $this->assertNotNull($placeholder);
        $this->assertSame('Select ' . $label, $placeholder->getAttribute('placeholder'));
    }

    public function testTextIdentityUsesTheTrustedHostExpressionWithoutCasting(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getServerType')->willReturn('postgresql');
        $db->method('quoteName')->willReturnCallback(static fn ($name, $alias = null) => $alias ? "$name AS $alias" : $name);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getWorkingQuery($db));
        $query = $this->getWorkingQuery($db)->select('*')->from('fixture_records AS x');

        $this->service($db)->applyToQuery(
            $query,
            new PreparedFieldsFilter('com_fixture.record', [3 => []], [3 => ['01', 'uuid-like-key']]),
            'x.external_key',
            'string',
        );

        $sql = (string) $query;
        $this->assertStringContainsString('cffv3.item_id = x.external_key', $sql);
        $this->assertStringNotContainsString('CONCATENATE(x.external_key', $sql);
        $this->assertSame([3, '01', 'uuid-like-key'], array_values(array_map(
            static fn ($bound) => $bound->value,
            $query->getBounded(),
        )));
    }

    private function service(?DatabaseInterface $database = null): FieldsFilterService
    {
        return new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $database ?? $this->createMock(DatabaseInterface::class),
            $this->createMock(DispatcherInterface::class),
        );
    }

    private function render(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');

        return new \DOMXPath($dom);
    }

    private function getWorkingQuery(DatabaseInterface $db): DatabaseQuery
    {
        return new class ($db) extends DatabaseQuery {
            public function groupConcat($expression, $separator = ',')
            {
                return '';
            }

            public function processLimit($query, $limit, $offset = 0)
            {
                return $query;
            }
        };
    }
}
