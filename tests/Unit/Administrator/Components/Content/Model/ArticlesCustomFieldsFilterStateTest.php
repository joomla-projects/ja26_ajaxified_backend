<?php

namespace Joomla\Tests\Unit\Administrator\Components\Content\Model;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Factory::$application;
        $plugins                   = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $plugins->setAccessible(true);
        $this->previousPlugins = $plugins->getValue();
        $plugins->setValue(null, []);
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $plugins              = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $plugins->setAccessible(true);
        $plugins->setValue(null, $this->previousPlugins);
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

    private function createModel(
        Input $input,
        array &$session,
        bool $ignoreRequest = false,
        ?array &$messages = null,
    ): TestArticlesModel {
        $messages ??= [];
        $application = $this->createMock(CMSApplication::class);
        $application->method('getInput')->willReturn($input);
        $application->method('getIdentity')->willReturn(new User());
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

        $field = (object) [
            'id'          => 7,
            'type'        => 'fixture-option',
            'label'       => 'Code',
            'title'       => 'Code',
            'description' => '',
            'params'      => new Registry(['show_in_admin_list_filter' => 1]),
        ];
        $fieldsModel = new class ($field) implements ModelInterface {
            public function __construct(private object $field)
            {
            }

            public function getName()
            {
                return 'Fields';
            }

            public function setCurrentUser(User $user): void
            {
            }

            public function setState($key, $value): void
            {
            }

            public function getItems(): array
            {
                return [$this->field];
            }
        };
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($fieldsModel);
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber(new ThirdPartyOptionFieldSubscriber());
        $service = new FieldsFilterService($factory, $this->createMock(DatabaseInterface::class), $dispatcher);

        self::assertArrayHasKey(7, $service->prepare('com_content.article', [], new User(), false)->getControls());

        return new TestArticlesModel($service, [
            'name'           => 'Articles',
            'ignore_request' => $ignoreRequest,
            'dbo'            => $this->createMock(DatabaseInterface::class),
        ], $this->createMock(MVCFactoryInterface::class));
    }
}
