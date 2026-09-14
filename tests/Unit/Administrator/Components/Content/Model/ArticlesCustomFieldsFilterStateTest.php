<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Content\Model;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\Dispatcher\DispatcherInterface as ComponentDispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Fields\FieldsServiceInterface;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ModelInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\Administrator\Components\Content\Fixtures\TestArticlesModel;
use Joomla\Tests\Unit\Administrator\Components\Fields\Fixtures\ThirdPartyOptionFieldSubscriber;
use Joomla\Tests\Unit\UnitTestCase;

class ArticlesCustomFieldsFilterStateTest extends UnitTestCase
{
    private mixed $previousApplication;
    private mixed $previousPlugins;
    private mixed $previousComponents;
    private bool $previousMultilanguage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication   = Factory::$application;
        $this->previousMultilanguage = Multilanguage::$enabled;
        $plugins                     = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $this->previousPlugins       = $plugins->getValue();
        $plugins->setValue(null, []);
        $components                  = new \ReflectionProperty(ComponentHelper::class, 'components');
        $this->previousComponents    = $components->getValue();
        Multilanguage::$enabled      = true;
    }

    protected function tearDown(): void
    {
        Factory::$application   = $this->previousApplication;
        Multilanguage::$enabled = $this->previousMultilanguage;
        $plugins                = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $plugins->setValue(null, $this->previousPlugins);
        $components = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setValue(null, $this->previousComponents);
        parent::tearDown();
    }

    public function testActiveFiltersCanBeFirstAccessAndPreparedResultIsReused(): void
    {
        $session = ['com_content.articles.filter' => ['customfield_7' => ['01', 'north'], 'state' => '1']];
        $model   = $this->createModel(new Input(), $session);

        $this->assertSame('com_content.articles', $model->contextName());
        $this->assertEquals(['customfield_7' => ['01', 'north'], 'state' => '1'], $model->getActiveFilters());
        $first = $model->fieldsFingerprint();
        $this->assertSame($first, $model->fieldsFingerprint());
        $this->assertEquals(['customfield_7' => ['01', 'north'], 'state' => '1'], $session['com_content.articles.filter']);
    }

    public function testExplicitRemovalAndFinalClearReplaceRememberedSelections(): void
    {
        $session = ['com_content.articles.filter' => ['customfield_7' => ['01', 'north'], 'state' => '1']];
        $model   = $this->createModel(new Input(['filter' => ['customfield_7' => ['01'], 'state' => '1']]), $session);

        $this->assertEquals(['customfield_7' => ['01'], 'state' => '1'], $model->getActiveFilters());
        $this->assertSame(['01'], $session['com_content.articles.filter']['customfield_7']);

        $session = ['com_content.articles.filter' => ['customfield_7' => ['01'], 'state' => '1']];
        $model   = $this->createModel(new Input(['filter' => ['state' => '1']]), $session);

        $this->assertEquals(['state' => '1'], $model->getActiveFilters());
        $this->assertArrayNotHasKey('customfield_7', $session['com_content.articles.filter']);
    }

    public function testEquivalentReorderingDoesNotResetPaginationButEffectiveChangeDoes(): void
    {
        $session = [
            'com_content.articles.filter'     => ['customfield_7' => ['01', 'north']],
            'com_content.articles.limitstart' => 40,
        ];
        $model = $this->createModel(new Input(['filter' => ['customfield_7' => ['north', '01']], 'limitstart' => 40]), $session);
        $model->getActiveFilters();
        $this->assertSame(40, $session['com_content.articles.limitstart']);

        $session = [
            'com_content.articles.filter'     => ['customfield_7' => ['01', 'north']],
            'com_content.articles.limitstart' => 40,
        ];
        $model = $this->createModel(new Input(['filter' => ['customfield_7' => ['01']], 'limitstart' => 40]), $session);
        $model->getActiveFilters();
        $this->assertSame(0, $session['com_content.articles.limitstart']);
    }

    public function testIgnoreRequestUsesOnlyProgrammaticState(): void
    {
        $session = ['com_content.articles.filter' => ['customfield_7' => ['north']]];
        $model   = $this->createModel(new Input(['filter' => ['customfield_7' => ['01']]]), $session, true);
        $model->setState('filter.customfield_7', ['0']);

        $this->assertSame(['customfield_7' => ['0']], $model->getActiveFilters());
        $this->assertSame(['customfield_7' => ['north']], $session['com_content.articles.filter']);
    }

    public function testProgrammaticSelectionCanBeAddedChangedClearedAndReorderedOnOneModel(): void
    {
        $session  = [];
        $counter  = (object) ['value' => 0];
        $model    = $this->createModel(new Input(), $session, true, discoveryCounter: $counter);
        $emptyKey = $model->fieldsFingerprint();

        $this->assertSame([], $model->getActiveFilters());
        $this->assertSame(1, $counter->value);
        $this->assertSame($emptyKey, $model->fieldsFingerprint());
        $this->assertSame(1, $counter->value);
        $form = new Form('lifecycle-filter', ['control' => '']);
        $form->load('<form><fields name="filter" /></form>');
        $model->syncFieldsForm($form);
        $this->assertSame([], $form->getValue('customfield_7', 'filter'));

        $model->setState('filter.customfield_7', ['01']);
        $firstKey = $model->fieldsFingerprint();
        $this->assertNotSame($emptyKey, $firstKey);
        $this->assertSame(['customfield_7' => ['01']], $model->getActiveFilters());
        $model->syncFieldsForm($form);
        $this->assertSame(['01'], $form->getValue('customfield_7', 'filter'));

        $model->setState('filter.customfield_7', ['north']);
        $changedKey = $model->fieldsFingerprint();
        $this->assertNotSame($firstKey, $changedKey);
        $this->assertSame(['customfield_7' => ['north']], $model->getActiveFilters());
        $model->syncFieldsForm($form);
        $this->assertSame(['north'], $form->getValue('customfield_7', 'filter'));

        $model->setState('filter.customfield_7', []);
        $this->assertSame($emptyKey, $model->fieldsFingerprint());
        $this->assertSame([], $model->getActiveFilters());
        $model->syncFieldsForm($form);
        $this->assertSame([], $form->getValue('customfield_7', 'filter'));

        $model->setState('filter.customfield_7', ['north', '01']);
        $orderedKey = $model->fieldsFingerprint();
        $model->setState('filter.customfield_7', ['01', 'north']);
        $this->assertSame($orderedKey, $model->fieldsFingerprint());
    }

    public function testChangingCurrentUserRevalidatesFieldEligibilityAndStoreIdentity(): void
    {
        $session              = [];
        $field                = $this->fieldFixture(7, [['value' => '01', 'text' => 'Leading zero']]);
        $field->allowedUserId = 41;
        $counter              = (object) ['value' => 0];
        $model                = $this->createModel(new Input(), $session, true, fields: [$field], discoveryCounter: $counter);
        $user                 = $this->userFixture(41, [1]);
        $model->setCurrentUser($user);
        $model->setState('filter.customfield_7', ['01']);

        $firstKey = $model->fieldsFingerprint();
        $this->assertSame(['customfield_7' => ['01']], $model->getActiveFilters());

        $secondUser = $this->userFixture(42, [1, 99]);
        $model->setCurrentUser($secondUser);
        $this->expectException(\InvalidArgumentException::class);

        try {
            $model->fieldsFingerprint();
        } finally {
            $this->assertGreaterThanOrEqual(2, $counter->value);
            $this->assertNotSame('', $firstKey);
        }
    }

    public function testChangingEligibleUserChangesStoreIdentity(): void
    {
        $session   = [];
        $model     = $this->createModel(new Input(), $session, true);
        $firstUser = $this->userFixture(41, [1]);
        $model->setCurrentUser($firstUser);
        $model->setState('filter.customfield_7', ['01']);
        $firstKey = $model->fieldsFingerprint();

        $secondUser = $this->userFixture(42, [1]);
        $model->setCurrentUser($secondUser);

        $this->assertNotSame($firstKey, $model->fieldsFingerprint());
        $this->assertSame(['customfield_7' => ['01']], $model->getActiveFilters());
    }

    public function testRejectedExplicitInputRestoresSessionAndProgrammaticInvalidInputThrows(): void
    {
        $session  = ['com_content.articles.filter' => ['customfield_7' => ['01'], 'state' => '1']];
        $messages = [];
        $model    = $this->createModel(
            new Input(['filter' => ['customfield_7' => ['unknown'], 'state' => '0']]),
            $session,
            false,
            $messages,
        );

        $model->getActiveFilters();
        $this->assertSame(['customfield_7' => ['01'], 'state' => '1'], $session['com_content.articles.filter']);
        $this->assertCount(1, $messages);
        $this->assertSame('error', $messages[0][1]);

        $session = [];
        $model   = $this->createModel(new Input(), $session, true);
        $model->setState('filter.customfield_7', ['unknown']);
        $this->expectException(\InvalidArgumentException::class);
        $model->getActiveFilters();
    }

    public function testStaleRememberedSelectionIsCleanedAndWarnedOnce(): void
    {
        $session  = ['com_content.articles.filter' => ['customfield_7' => ['removed']]];
        $messages = [];
        $this->createModel(new Input(), $session, false, $messages)->getActiveFilters();

        $this->assertSame([], $session['com_content.articles.filter']);
        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0][1]);

        $this->createModel(new Input(), $session, false, $messages)->getActiveFilters();
        $this->assertCount(1, $messages);
    }

    public function testRememberedAggregateInvalidStateIsClearedAsAWholeAndWarnedOnce(): void
    {
        $fields  = [];
        $filters = ['state' => '1'];

        for ($fieldId = 1; $fieldId <= FieldsFilterService::MAX_FIELDS + 1; $fieldId++) {
            $fields[]                            = $this->fieldFixture($fieldId, [['value' => 'v', 'text' => 'Value']]);
            $filters['customfield_' . $fieldId]  = ['v'];
        }

        $session  = ['com_content.articles.filter' => $filters];
        $messages = [];
        $model    = $this->createModel(new Input(), $session, false, $messages, fields: $fields);

        $this->assertSame(['state' => '1'], $model->getActiveFilters());
        $this->assertSame(['state' => '1'], $session['com_content.articles.filter']);
        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0][1]);

        $this->createModel(new Input(), $session, false, $messages, fields: $fields)->getActiveFilters();
        $this->assertCount(1, $messages);
    }

    public function testExplicitAggregateInvalidInputRestoresSessionAndProgrammaticInputThrows(): void
    {
        $fields  = [];
        $filters = ['state' => '0'];

        for ($fieldId = 1; $fieldId <= FieldsFilterService::MAX_FIELDS + 1; $fieldId++) {
            $fields[]                            = $this->fieldFixture($fieldId, [['value' => 'v', 'text' => 'Value']]);
            $filters['customfield_' . $fieldId]  = ['v'];
        }

        $session  = ['com_content.articles.filter' => ['customfield_1' => ['v'], 'state' => '1']];
        $messages = [];
        $model    = $this->createModel(new Input(['filter' => $filters]), $session, false, $messages, fields: $fields);
        $model->getActiveFilters();

        $this->assertSame(['customfield_1' => ['v'], 'state' => '1'], $session['com_content.articles.filter']);
        $this->assertSame('error', $messages[0][1]);

        $session = [];
        $model   = $this->createModel(new Input(), $session, true, fields: $fields);

        foreach ($filters as $name => $value) {
            if (str_starts_with($name, 'customfield_')) {
                $model->setState('filter.' . $name, $value);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $model->getActiveFilters();
    }

    public function testSelectionLimitsCountCanonicalNonEmptyDistinctTokens(): void
    {
        $values = static function (int $count, int $length = 8): array {
            $options = [];

            for ($index = 0; $index < $count; $index++) {
                $value     = str_pad((string) $index, $length, 'x');
                $options[] = ['value' => $value, 'text' => $value];
            }

            return $options;
        };

        $session = [];
        $options = $values(FieldsFilterService::MAX_VALUES_PER_FIELD + 1);
        $model   = $this->createModel(new Input(), $session, true, fields: [$this->fieldFixture(7, $options)]);
        $tokens  = array_column($options, 'value');

        $boundary = $model->prepareFields([
            'customfield_7' => array_merge(\array_slice($tokens, 0, FieldsFilterService::MAX_VALUES_PER_FIELD), ['', $tokens[0], '']),
        ]);
        $this->assertSame(FieldsFilterService::MAX_VALUES_PER_FIELD, \count($boundary->getSelections()[7]));
        $this->assertSame([], $boundary->getIssues());

        $overPerField = $model->prepareFields(['customfield_7' => $tokens]);
        $this->assertSame([], $overPerField->getSelections());
        $this->assertSame('too_many_values', $overPerField->getIssues()[0]['code']);

        $fieldCountFields  = [];
        $fieldCountFilters = [];

        for ($fieldId = 1; $fieldId <= FieldsFilterService::MAX_FIELDS + 1; $fieldId++) {
            $fieldCountFields[]                            = $this->fieldFixture($fieldId, [['value' => 'v', 'text' => 'Value']]);
            $fieldCountFilters['customfield_' . $fieldId]  = ['v'];
        }

        $model         = $this->createModel(new Input(), $session, true, fields: $fieldCountFields);
        $fieldBoundary = $model->prepareFields(\array_slice(
            $fieldCountFilters,
            0,
            FieldsFilterService::MAX_FIELDS,
            true,
        ));
        $this->assertCount(FieldsFilterService::MAX_FIELDS, $fieldBoundary->getSelections());
        $this->assertSame([], $fieldBoundary->getIssues());

        $overFields = $model->prepareFields($fieldCountFilters);
        $this->assertFalse($overFields->isActive());
        $this->assertSame('too_many_fields', $overFields->getIssues()[0]['code']);
        $explicitOverFields = $model->prepareFields($fieldCountFilters, true);
        $this->assertTrue($explicitOverFields->isRejected());
        $this->assertFalse($explicitOverFields->isActive());

        $hundredOptions = $values(100);
        $fiftySeven     = $values(57);
        $model          = $this->createModel(new Input(), $session, true, fields: [
            $this->fieldFixture(101, $hundredOptions),
            $this->fieldFixture(102, $hundredOptions),
            $this->fieldFixture(103, $fiftySeven),
        ]);
        $totalFilters = [
            'customfield_101' => array_column($hundredOptions, 'value'),
            'customfield_102' => array_column($hundredOptions, 'value'),
            'customfield_103' => array_column($fiftySeven, 'value'),
        ];
        $totalBoundary = $totalFilters;
        array_pop($totalBoundary['customfield_103']);
        $this->assertSame([], $model->prepareFields($totalBoundary)->getIssues());

        $overValues = $model->prepareFields($totalFilters);
        $this->assertFalse($overValues->isActive());
        $this->assertSame('request_too_large', $overValues->getIssues()[0]['code']);

        $longOptions = $values(65, FieldsFilterService::MAX_TOKEN_BYTES);
        $model       = $this->createModel(new Input(), $session, true, fields: [$this->fieldFixture(8, $longOptions)]);
        $longTokens  = array_column($longOptions, 'value');
        $byteLimit   = $model->prepareFields(['customfield_8' => \array_slice($longTokens, 0, 64)]);
        $this->assertSame([], $byteLimit->getIssues());

        $overBytes = $model->prepareFields(['customfield_8' => $longTokens]);
        $this->assertSame([], $overBytes->getSelections());
        $this->assertSame('request_too_large', $overBytes->getIssues()[0]['code']);

        $oversizedToken = str_repeat('z', FieldsFilterService::MAX_TOKEN_BYTES + 1);
        $model          = $this->createModel(new Input(), $session, true, fields: [
            $this->fieldFixture(9, [['value' => 'valid', 'text' => 'Valid']]),
        ]);
        $this->assertSame('invalid_value', $model->prepareFields(['customfield_9' => [$oversizedToken]])->getIssues()[0]['code']);
    }

    public function testDisabledCustomFieldsCleanRememberedSelectionsAndPreserveNativeState(): void
    {
        $session  = ['com_content.articles.filter' => ['customfield_7' => ['01'], 'state' => '1']];
        $messages = [];

        $model = $this->createModel(new Input(), $session, false, $messages, false);

        $this->assertSame(['state' => '1'], $model->getActiveFilters());
        $this->assertSame(['state' => '1'], $session['com_content.articles.filter']);
        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0][1]);
    }

    public function testDisabledCustomFieldsRejectExplicitSelectionsButAcceptAnEmptySelection(): void
    {
        $session  = ['com_content.articles.filter' => ['state' => '1']];
        $messages = [];
        $model    = $this->createModel(
            new Input(['filter' => ['customfield_7' => ['01'], 'state' => '0']]),
            $session,
            false,
            $messages,
            false,
        );

        $model->getActiveFilters();
        $this->assertSame(['state' => '1'], $session['com_content.articles.filter']);
        $this->assertSame('error', $messages[0][1]);

        $session  = ['com_content.articles.filter' => ['state' => '1']];
        $messages = [];
        $model    = $this->createModel(
            new Input(['filter' => ['customfield_7' => [''], 'state' => '0']]),
            $session,
            false,
            $messages,
            false,
        );

        $this->assertSame(['state' => '0'], $model->getActiveFilters());
        $this->assertSame(['state' => '0'], $session['com_content.articles.filter']);
        $this->assertSame([], $messages);

        $session = [];
        $model   = $this->createModel(new Input(), $session, true, $messages, false);
        $model->setState('filter.customfield_7', ['01']);
        $this->expectException(\InvalidArgumentException::class);
        $model->getActiveFilters();
    }

    public function testRememberedNestedArraySelectionIsRecoveredWithoutStringCoercion(): void
    {
        $session  = ['com_content.articles.filter' => ['customfield_7' => ['north', ['nested']], 'state' => '1']];
        $messages = [];
        $model    = $this->createModel(new Input(), $session, false, $messages);

        // The malformed member is rejected instead of becoming an "Array" token, and the
        // native state survives the correction.
        $this->assertSame(['state' => '1'], $model->getActiveFilters());
        $this->assertSame(['state' => '1'], $session['com_content.articles.filter']);
        $this->assertArrayNotHasKey('customfield_7', $session['com_content.articles.filter']);
        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0][1]);

        // The corrected state is persisted, so the recovery warning is not repeated.
        $this->createModel(new Input(), $session, false, $messages)->getActiveFilters();
        $this->assertCount(1, $messages);
    }

    public function testRememberedNonStringableObjectSelectionIsRecoveredWithoutStringCoercion(): void
    {
        $session  = ['com_content.articles.filter' => ['customfield_7' => ['north', new \stdClass()], 'state' => '1']];
        $messages = [];
        $model    = $this->createModel(new Input(), $session, false, $messages);

        $this->assertSame(['state' => '1'], $model->getActiveFilters());
        $this->assertSame(['state' => '1'], $session['com_content.articles.filter']);
        $this->assertArrayNotHasKey('customfield_7', $session['com_content.articles.filter']);
        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0][1]);

        $this->createModel(new Input(), $session, false, $messages)->getActiveFilters();
        $this->assertCount(1, $messages);
    }

    private function createModel(
        Input $input,
        array &$session,
        bool $ignoreRequest = false,
        ?array &$messages = null,
        ?bool $customFieldsEnabled = null,
        ?array $fields = null,
        ?object $discoveryCounter = null,
    ): TestArticlesModel {
        $messages ??= [];
        $application = $this->createMock(CMSApplication::class);
        $application->method('getInput')->willReturn($input);
        $application->method('getIdentity')->willReturn($this->userFixture());
        $application->method('getLanguage')->willReturn(new Language('en-GB'));
        $fieldsComponent = new class () implements ComponentInterface, FieldsServiceInterface {
            public function getDispatcher(CMSApplicationInterface $application): ComponentDispatcherInterface
            {
                throw new \LogicException('Not used by this test.');
            }

            public function validateSection($section, $item = null)
            {
                return $section === 'article' ? $section : null;
            }

            public function getContexts(): array
            {
                return ['com_content.article' => 'Article'];
            }
        };
        $application->method('bootComponent')->with('com_content')->willReturn($fieldsComponent);
        $application->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'list_limit' ? 20 : $default);
        $application->method('getUserState')->willReturnCallback(static function ($key, $default = null) use (&$session) {
            return $session[$key] ?? $default;
        });
        $application->method('setUserState')->willReturnCallback(static function ($key, $value) use (&$session) {
            $previous      = $session[$key] ?? null;
            $session[$key] = $value;

            return $previous;
        });
        $application->method('enqueueMessage')->willReturnCallback(static function ($message, $type = 'message') use (&$messages) {
            $messages[] = [$message, $type];
        });
        $application->method('getUserStateFromRequest')->willReturnCallback(
            static function ($key, $request, $default = null, $type = 'none') use (&$session, $input) {
                $value = $input->get($request, null, $type);

                if ($value !== null) {
                    $session[$key] = $value;

                    return $value;
                }

                return $session[$key] ?? $default;
            }
        );
        Factory::$application = $application;

        $component = new ComponentRecord(['option' => 'com_content', 'enabled' => 1]);
        $component->setParams(new Registry($customFieldsEnabled === null ? [] : ['custom_fields_enable' => (int) $customFieldsEnabled]));
        $components = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setValue(null, ['com_content' => $component]);

        $fields           ??= [$this->fieldFixture(7)];
        $discoveryCounter ??= (object) ['value' => 0];
        $fieldsModel = new class ($fields, $discoveryCounter) implements ModelInterface {
            private ?User $user = null;

            public function __construct(private array $fields, private object $discoveryCounter)
            {
            }

            public function getName()
            {
                return 'Fields';
            }

            public function setCurrentUser(User $user): void
            {
                $this->user = $user;
            }

            public function setState($key, $value): void
            {
            }

            public function getItems(): array
            {
                $this->discoveryCounter->value++;

                return array_values(array_filter(
                    $this->fields,
                    fn (object $field): bool => !isset($field->allowedUserId) || (int) $field->allowedUserId === (int) $this->user?->id,
                ));
            }
        };
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($fieldsModel);
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber(new ThirdPartyOptionFieldSubscriber());
        $service = new FieldsFilterService($factory, $this->createMock(DatabaseInterface::class), $dispatcher);

        return new TestArticlesModel($service, [
            'name'           => 'Articles',
            'ignore_request' => $ignoreRequest,
            'dbo'            => $this->createMock(DatabaseInterface::class),
        ], $this->createMock(MVCFactoryInterface::class));
    }

    private function fieldFixture(int $id, ?array $options = null): object
    {
        $field = (object) [
            'id'          => $id,
            'type'        => 'fixture-option',
            'label'       => 'Code ' . $id,
            'title'       => 'Code ' . $id,
            'description' => '',
            'params'      => new Registry(['show_in_admin_list_filter' => 1]),
        ];

        if ($options !== null) {
            $field->filterOptions = $options;
        }

        return $field;
    }

    private function userFixture(int $id = 0, array $viewLevels = [1], bool $superUser = false): User
    {
        $user     = $this->createMock(User::class);
        $user->id = $id;
        $user->method('getAuthorisedViewLevels')->willReturn($viewLevels);
        $user->method('authorise')->willReturn($superUser);

        return $user;
    }
}
