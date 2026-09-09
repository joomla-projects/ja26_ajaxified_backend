<?php

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Service;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\Dispatcher\DispatcherInterface as ComponentDispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Fields\FieldsServiceInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ModelInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\Administrator\Components\Fields\Fixtures\ThirdPartyOptionFieldSubscriber;
use Joomla\Tests\Unit\UnitTestCase;

class FieldsFilterEnablementTest extends UnitTestCase
{
    private mixed $previousApplication;
    private mixed $previousComponents;
    private mixed $previousPlugins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Factory::$application;
        $components                = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setAccessible(true);
        $this->previousComponents = $components->getValue();
        $plugins                  = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $plugins->setAccessible(true);
        $this->previousPlugins = $plugins->getValue();
        $plugins->setValue(null, []);
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $components           = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setAccessible(true);
        $components->setValue(null, $this->previousComponents);
        $plugins = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $plugins->setAccessible(true);
        $plugins->setValue(null, $this->previousPlugins);
        parent::tearDown();
    }

    public function testEnabledAndMissingParametersDiscoverThirdPartyFieldOptions(): void
    {
        foreach ([1, null] as $enabled) {
            [$service, $discoveryCount] = $this->createService($enabled);
            $prepared                   = $service->prepare('com_fixture.record', [], new User(), false);

            $this->assertArrayHasKey(7, $prepared->getControls());
            $this->assertSame(1, $discoveryCount());
        }
    }

    public function testDisabledComponentSkipsDiscoveryAndLeavesEmptySelectionsInactive(): void
    {
        [$service, $discoveryCount] = $this->createService(0);
        $prepared                   = $service->prepare('com_fixture.record', ['customfield_7' => ['']], new User(), true);

        $this->assertSame([], $prepared->getControls());
        $this->assertSame([], $prepared->getSelections());
        $this->assertSame([], $prepared->getIssues());
        $this->assertFalse($prepared->isRejected());
        $this->assertSame(0, $discoveryCount());
    }

    public function testDisabledComponentRejectsExplicitValuesAndFailsClosed(): void
    {
        [$service, $discoveryCount] = $this->createService(0);
        $prepared                   = $service->prepare('com_fixture.record', ['customfield_7' => ['01']], new User(), true);

        $this->assertTrue($prepared->isRejected());
        $this->assertSame([['code' => 'ineligible_field', 'field_id' => 7]], $prepared->getIssues());
        $this->assertSame(0, $discoveryCount());

        $database = $this->createMock(DatabaseInterface::class);
        $query    = new class ($database) extends \Joomla\Database\DatabaseQuery {
            public function groupConcat($expression, $separator = ',')
            {
                return '';
            }

            public function processLimit($query, $limit, $offset = 0)
            {
                return $query;
            }
        };
        $service->applyToQuery($query->select('*')->from('items'), $prepared, 'a.id');
        $this->assertStringContainsString('1 = 0', (string) $query);
    }

    public function testDisabledRememberedAndProgrammaticSelectionsExposeIssuesWithoutExplicitRejection(): void
    {
        [$service] = $this->createService(0);
        $prepared  = $service->prepare('com_fixture.record', ['customfield_7' => ['01']], new User(), false);

        $this->assertFalse($prepared->isRejected());
        $this->assertNotSame([], $prepared->getIssues());
        $this->assertSame([], $prepared->getSelections());
    }

    private function createService(?int $enabled): array
    {
        $component = new ComponentRecord(['option' => 'com_fixture', 'enabled' => 1]);
        $component->setParams(new Registry($enabled === null ? [] : ['custom_fields_enable' => $enabled]));
        $components = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setAccessible(true);
        $components->setValue(null, ['com_fixture' => $component]);

        $fieldsComponent = new class () implements ComponentInterface, FieldsServiceInterface {
            public function getDispatcher(CMSApplicationInterface $application): ComponentDispatcherInterface
            {
                throw new \LogicException('Not used by this test.');
            }

            public function validateSection($section, $item = null)
            {
                return $section === 'record' ? $section : null;
            }

            public function getContexts(): array
            {
                return ['com_fixture.record' => 'Record'];
            }
        };
        $application = $this->createMock(CMSApplication::class);
        $application->method('bootComponent')->with('com_fixture')->willReturn($fieldsComponent);
        Factory::$application = $application;

        $field = (object) [
            'id'          => 7,
            'type'        => 'fixture-option',
            'label'       => 'Code',
            'title'       => 'Code',
            'description' => '',
            'params'      => new Registry(['show_in_admin_list_filter' => 1]),
        ];
        $discoveryCount = (object) ['value' => 0];
        $fieldsModel    = new class ($field, $discoveryCount) implements ModelInterface {
            public function __construct(private object $field, private object $discoveryCount)
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
                $this->discoveryCount->value++;

                return [$this->field];
            }
        };
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($fieldsModel);
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber(new ThirdPartyOptionFieldSubscriber());

        return [
            new FieldsFilterService($factory, $this->createMock(DatabaseInterface::class), $dispatcher),
            static fn () => $discoveryCount->value,
        ];
    }
}
