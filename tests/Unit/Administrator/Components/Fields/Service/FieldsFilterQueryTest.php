<?php

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
    private mixed $previousComponents;

    protected function setUp(): void
    {
        parent::setUp();
        $components               = new \ReflectionProperty(ComponentHelper::class, 'components');
        $this->previousComponents = $components->getValue();
        $components->setValue(null, [
            'com_content' => new ComponentRecord(['option' => 'com_content', 'enabled' => 1]),
        ]);
    }

    protected function tearDown(): void
    {
        $components           = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setValue(null, $this->previousComponents);
        parent::tearDown();
    }

    public function testTwoFieldsBindIndependentValuesOnOuterQuery(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('quote')->willReturnCallback(static fn ($value) => "'" . $value . "'");
        $db->method('quoteName')->willReturnCallback(static function ($name, $alias = null) {
            $quoted = '`' . $name . '`';

            return $alias ? $quoted . ' AS `' . $alias . '`' : $quoted;
        });
        $db->method('createQuery')->willReturnCallback(fn () => $this->getWorkingQuery($db));

        $query    = $this->getWorkingQuery($db)->select('*')->from('items AS a');
        $existing = 'published';
        $query->where('a.state = :state')->bind(':state', $existing);

        $service = new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $db,
            $this->createMock(DispatcherInterface::class),
        );
        $prepared = new PreparedFieldsFilter(
            'com_content.article',
            [7 => [], 12 => []],
            [7 => ['india', 'japan'], 12 => ['high']],
        );

        $service->applyToQuery($query, $prepared, 'a.id', 'integer');

        $sql = (string) $query;
        $this->assertSame(2, substr_count($sql, 'EXISTS'));
        $this->assertStringContainsString('CONCATENATE(a.id', $sql);
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
        $service = new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $db,
            $this->createMock(DispatcherInterface::class),
        );

        $rejected = $this->getWorkingQuery($db)->select('*')->from('items');
        $service->applyToQuery($rejected, new PreparedFieldsFilter('x.y', [], [], [['code' => 'bad']], true), 'a.id');
        $this->assertStringContainsString('1 = 0', (string) $rejected);

        $inactive = $this->getWorkingQuery($db)->select('*')->from('items');
        $before   = (string) $inactive;
        $service->applyToQuery($inactive, new PreparedFieldsFilter('x.y', [], []), 'a.id');
        $this->assertSame($before, (string) $inactive);
    }

    public function testFormControlsAreFlatAndPreserveStringTokens(): void
    {
        $service = new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $this->createMock(DatabaseInterface::class),
            $this->createMock(DispatcherInterface::class),
        );
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
        $previous             = Factory::$application;
        $application          = $this->createMock(CMSApplication::class);
        $application->method('getIdentity')->willReturn(new User());
        $application->method('getLanguage')->willReturn(new Language('en-GB'));
        Factory::$application = $application;

        try {
            $form = new Form('com_content.articles.filter', ['control' => '']);
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
            $this->assertCount(2, $form->getGroup('filter'));

            $service->bindForm($form, new PreparedFieldsFilter('com_content.article', $prepared->getControls(), [7 => ['01']]));
            $this->assertSame(['01'], $form->getValue('customfield_7', 'filter'));
            $this->assertSame('unchanged', $form->getValue('search', 'filter'));

            $service->bindForm($form, new PreparedFieldsFilter('com_content.article', $prepared->getControls(), []));
            $this->assertSame([], $form->getValue('customfield_7', 'filter'));
            $this->assertSame('unchanged', $form->getValue('search', 'filter'));
        } finally {
            Factory::$application = $previous;
        }
    }

    /**
     * @dataProvider displayTextProvider
     */
    public function testRenderedControlEscapesMetadataAndOptionTextWithoutDamagingLabelAssociation(
        string $source,
        string $expected
    ): void {
        $previous    = Factory::$application;
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

        try {
            $service = new FieldsFilterService(
                $this->createMock(MVCFactoryInterface::class),
                $this->createMock(DatabaseInterface::class),
                $this->createMock(DispatcherInterface::class),
            );
            $controls = [7 => [
                'label'       => $source,
                'description' => '',
                'options'     => [
                    ['value' => 'safe&value', 'text' => $source],
                ],
            ]];
            $form = new Form('com_content.articles.filter', ['control' => '']);
            $form->load('<form><fields name="filter" /></form>');
            $prepared = new PreparedFieldsFilter('com_content.article', $controls, []);
            $service->augmentForm($form, $prepared);

            $field = $form->getField('customfield_7', 'filter');
            $dom   = new \DOMDocument();
            @$dom->loadHTML('<!doctype html><html><body>' . $field->renderField() . '</body></html>');
            $xpath  = new \DOMXPath($dom);
            $label  = $xpath->query('//label')->item(0);
            $select = $xpath->query('//select')->item(0);
            $option = $xpath->query('//select/option')->item(0);

            $this->assertNotNull($label);
            $this->assertNotNull($select);
            $this->assertSame($select->getAttribute('id'), $label->getAttribute('for'));
            $this->assertSame($expected, trim($label->textContent));
            $this->assertSame('safe&value', $option->getAttribute('value'));
            $this->assertSame($expected, $option->textContent);
            $this->assertSame('Select ' . $expected, $xpath->query('//joomla-field-fancy-select')->item(0)->getAttribute('placeholder'));
            $this->assertCount(0, $xpath->query('//label//b | //label//i | //option//b | //option//i'));
            $this->assertCount(0, $xpath->query('//*[@onmouseover]'));
        } finally {
            Factory::$application = $previous;
        }
    }

    public static function displayTextProvider(): iterable
    {
        yield 'literal markup is reduced to text' => ['<b>X</b>', 'X'];
        yield 'encoded markup remains text' => ['&lt;b&gt;X&lt;/b&gt;', '<b>X</b>'];
        yield 'double encoded markup remains text' => ['&amp;lt;b&amp;gt;X&amp;lt;/b&amp;gt;', '<b>X</b>'];
        yield 'quotes and ampersand' => ['Size & "colour"', 'Size & "colour"'];
        yield 'less-than text' => ['A < B', 'A < B'];
        yield 'XML significant option text' => ['A & B > "C"', 'A & B > "C"'];
        yield 'attribute-looking text' => ['" onmouseover="alert(1)', '" onmouseover="alert(1)'];
    }

    public function testRenderedControlSelectsNumericLookingTokensStrictly(): void
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
        $language = new Language('en-GB');

        $application->method('getIdentity')->willReturn(new User());
        $application->method('getLanguage')->willReturn($language);
        $application->method('getDocument')->willReturn($document);
        $application->method('getInput')->willReturn(new Input(['option' => 'com_content']));
        $document->method('getWebAssetManager')->willReturn($assets);
        Factory::$application = $application;

        try {
            $service = new FieldsFilterService(
                $this->createMock(MVCFactoryInterface::class),
                $this->createMock(DatabaseInterface::class),
                $this->createMock(DispatcherInterface::class),
            );
            $control = [7 => [
                'label'       => 'Code',
                'description' => '',
                'options'     => [
                    ['value' => '0', 'text' => 'Zero'],
                    ['value' => '01', 'text' => 'Leading zero'],
                    ['value' => '1', 'text' => 'One'],
                ],
            ]];
            $cases = [
                [['1'], ['1']],
                [['01'], ['01']],
                [['0'], ['0']],
                [['01', '1'], ['01', '1']],
                [[], []],
            ];

            foreach ($cases as [$selection, $expected]) {
                $form = new Form('com_content.articles.filter', ['control' => '']);
                $form->load('<form><fields name="filter" /></form>');
                $prepared = new PreparedFieldsFilter('com_content.article', $control, $selection ? [7 => $selection] : []);
                $service->augmentForm($form, $prepared);
                $service->bindForm($form, $prepared);

                $html = $form->getField('customfield_7', 'filter')->input;
                $dom  = new \DOMDocument();
                @$dom->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
                $selected = [];

                foreach ((new \DOMXPath($dom))->query('//option[@selected]') as $option) {
                    $selected[] = $option->getAttribute('value');
                }

                $this->assertSame($expected, $selected);
            }
        } finally {
            Factory::$application = $previous;
        }
    }

    public function testEmptySelectionRendersNoSelectedOptions(): void
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
        $application->method('getIdentity')->willReturn(new User());
        $application->method('getLanguage')->willReturn(new Language('en-GB'));
        $application->method('getDocument')->willReturn($document);
        $application->method('getInput')->willReturn(new Input(['option' => 'com_content']));
        $document->method('getWebAssetManager')->willReturn($assets);
        Factory::$application = $application;

        try {
            $service = new FieldsFilterService(
                $this->createMock(MVCFactoryInterface::class),
                $this->createMock(DatabaseInterface::class),
                $this->createMock(DispatcherInterface::class),
            );
            $form = new Form('com_content.articles.filter', ['control' => '']);
            $form->load('<form><fields name="filter" /></form>');
            $service->augmentForm($form, new PreparedFieldsFilter('com_content.article', [
                7 => ['label' => 'Code', 'description' => '', 'options' => [
                    ['value' => '0', 'text' => 'Zero'],
                    ['value' => '1', 'text' => 'One'],
                ]],
            ], []));

            $dom = new \DOMDocument();
            @$dom->loadHTML('<!doctype html><html><body>' . $form->getField('customfield_7', 'filter')->input . '</body></html>');
            $this->assertCount(0, (new \DOMXPath($dom))->query('//option[@selected]'));
        } finally {
            Factory::$application = $previous;
        }
    }

    public function testStrictSelectionIsForwardedByNormalListLayout(): void
    {
        $service = new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $this->createMock(DatabaseInterface::class),
            $this->createMock(DispatcherInterface::class),
        );
        $prepared = new PreparedFieldsFilter('com_content.article', [7 => [
            'label'       => 'Code',
            'description' => '',
            'options'     => [
                ['value' => '01', 'text' => 'Leading zero'],
                ['value' => '1', 'text' => 'One'],
            ],
        ]], [7 => ['1']]);
        $previous             = Factory::$application;
        $application          = $this->createMock(CMSApplication::class);
        $application->method('getIdentity')->willReturn(new User());
        $application->method('getLanguage')->willReturn(new Language('en-GB'));
        Factory::$application = $application;

        try {
            $form = new Form('com_content.articles.filter', ['control' => '']);
            $form->load('<form><fields name="filter" /></form>');
            $service->augmentForm($form, $prepared);
            $service->bindForm($form, $prepared);
            $form->setFieldAttribute('customfield_7', 'layout', 'joomla.form.field.list', 'filter');

            $dom = new \DOMDocument();
            @$dom->loadHTML('<select>' . $form->getField('customfield_7', 'filter')->input . '</select>');
            $selected = (new \DOMXPath($dom))->query('//option[@selected]');

            $this->assertCount(1, $selected);
            $this->assertSame('1', $selected->item(0)->getAttribute('value'));
        } finally {
            Factory::$application = $previous;
        }
    }

    public function testTextIdentityUsesTheTrustedHostExpressionWithoutCasting(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('quoteName')->willReturnCallback(static fn ($name, $alias = null) => $alias ? "$name AS $alias" : $name);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getWorkingQuery($db));
        $query   = $this->getWorkingQuery($db)->select('*')->from('fixture_records AS x');
        $service = new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $db,
            $this->createMock(DispatcherInterface::class),
        );

        $service->applyToQuery(
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

    public function testOptionNormalizationRejectsDuplicateTokensWithoutRewritingDistinctStrings(): void
    {
        $service = new FieldsFilterService(
            $this->createMock(MVCFactoryInterface::class),
            $this->createMock(DatabaseInterface::class),
            $this->createMock(DispatcherInterface::class),
        );
        $method = new \ReflectionMethod($service, 'normalizeOptions');

        $this->assertNull($method->invoke($service, [
            ['value' => 'same', 'text' => 'First'],
            ['value' => 'same', 'text' => 'Second'],
        ]));

        $options = [
            ['value' => '0', 'text' => 'Zero'],
            ['value' => '00', 'text' => 'Double zero'],
            ['value' => '01', 'text' => 'Leading zero'],
            ['value' => '1', 'text' => 'One'],
            ['value' => '1e0', 'text' => 'Exponent-looking'],
            ['value' => ' spaced ', 'text' => 'Whitespace'],
            ['value' => '日本', 'text' => 'Unicode'],
        ];

        $this->assertSame($options, $method->invoke($service, $options));
    }

    private function getWorkingQuery(DatabaseInterface $db): DatabaseQuery
    {
        return new class ($db) extends DatabaseQuery {
            public function groupConcat($expression, $separator = ',')
            {
            }

            public function processLimit($query, $limit, $offset = 0)
            {
                return $query;
            }
        };
    }
}
