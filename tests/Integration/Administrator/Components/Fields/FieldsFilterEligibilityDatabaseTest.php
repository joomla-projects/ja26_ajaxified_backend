<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Fields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Administrator\Components\Fields;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\ComponentRecord;
use Joomla\CMS\Dispatcher\DispatcherInterface as ComponentDispatcherInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Fields\FieldsServiceInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Model\FieldsModel;
use Joomla\Component\Fields\Administrator\Service\FieldsFilterService;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;
use Joomla\Tests\Unit\Administrator\Components\Fields\Fixtures\ThirdPartyOptionFieldSubscriber;

/**
 * Verifies that Custom Field filter eligibility is delegated to the real administrator FieldsModel.
 *
 * The real model still runs real SQL against real fixture rows. It is only the connection that is
 * test-local: Joomla's integration DBTestHelper shares a single connection for the whole PHPUnit
 * process and fixtures are not rolled back, so this test installs its intentionally minimal schema
 * under a dedicated prefix instead of the normal integration prefix.
 *
 * @since  __DEPLOY_VERSION__
 */
class FieldsFilterEligibilityDatabaseTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    private const ELIGIBLE_FIELD_ID     = 910001;
    private const UNPUBLISHED_FIELD_ID  = 910002;
    private const FIELD_ACCESS_FIELD_ID = 910004;
    private const GROUP_ACCESS_FIELD_ID = 910005;

    /**
     * Appended to the shared integration prefix to namespace this test's tables.
     *
     * @var string
     */
    private const ISOLATED_PREFIX_SUFFIX = 'cffelig_';

    /**
     * Physical objects owned by this test; they are dropped again in tearDown.
     *
     * @var array
     */
    private const ISOLATED_TABLES = [
        'fields',
        'fields_groups',
        'fields_categories',
        'viewlevels',
        'languages',
        'users',
    ];

    private mixed $previousApplication;
    private mixed $previousComponents;
    private mixed $previousPlugins;

    /**
     * Test-local connection carrying the isolated prefix. Never the shared integration connection.
     *
     * @var DatabaseInterface|null
     */
    private ?DatabaseInterface $isolatedDriver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApplication = Factory::$application;

        $plugins                  = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $this->previousPlugins    = $plugins->getValue();
        $components               = new \ReflectionProperty(ComponentHelper::class, 'components');
        $this->previousComponents = $components->getValue();

        try {
            $plugins->setValue(null, []);
            $this->loadIsolatedSchema();
        } catch (\Throwable $exception) {
            try {
                $this->dropIsolatedSchema();
            } catch (\Throwable) {
                // Preserve the setup failure; cleanup has already attempted every owned resource.
            }

            $this->restoreJoomlaState();

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->dropIsolatedSchema();
        } finally {
            $this->restoreJoomlaState();
            parent::tearDown();
        }
    }

    /**
     * The eligibility schema is installed through the isolated connection in loadIsolatedSchema(),
     * so the shared integration connection must load nothing for this class.
     *
     * @return array
     */
    public function getSchemasToLoad(): array
    {
        return [];
    }

    public function testRealFieldsModelPublishesOnlyTheEligibleControl(): void
    {
        $prepared = $this->service()->prepare('com_content.article', [], $this->user([1]), false);

        // The fixture installs 910001 (published, public, global, language '*', not
        // subform-only) plus 910002 unpublished field, 910003 unpublished group,
        // 910004 field access, 910005 group access, 910006 category restriction,
        // 910007 language specific and 910008 subform only. Only the control row
        // survives the eligibility rules of the real administrator FieldsModel.
        $this->assertSame([self::ELIGIBLE_FIELD_ID], array_keys($prepared->getControls()));
        $this->assertSame([], $prepared->getSelections());
        $this->assertSame([], $prepared->getIssues());
    }

    public function testDiscoverableControlAcceptsDeclaredTokens(): void
    {
        $prepared = $this->service()->prepare(
            'com_content.article',
            ['customfield_' . self::ELIGIBLE_FIELD_ID => ['01', 'north']],
            $this->user([1]),
            false,
        );

        $this->assertSame([self::ELIGIBLE_FIELD_ID => ['01', 'north']], $prepared->getSelections());
        $this->assertSame([], $prepared->getIssues());
    }

    public function testIneligibleRememberedFieldIsReportedWithoutSelection(): void
    {
        $prepared = $this->service()->prepare(
            'com_content.article',
            ['customfield_' . self::UNPUBLISHED_FIELD_ID => ['north']],
            $this->user([1]),
            false,
        );

        $this->assertSame([self::ELIGIBLE_FIELD_ID], array_keys($prepared->getControls()));
        $this->assertSame([], $prepared->getSelections());
        $this->assertSame([['code' => 'ineligible_field']], $prepared->getIssues());
        $this->assertFalse($prepared->isRejected());
    }

    public function testChangingAuthorisedViewLevelsChangesEligibility(): void
    {
        $restricted = array_keys($this->service()->prepare('com_content.article', [], $this->user([1]), false)->getControls());
        $widened    = array_keys($this->service()->prepare('com_content.article', [], $this->user([1, 99]), false)->getControls());

        $this->assertSame([self::ELIGIBLE_FIELD_ID], $restricted);
        $this->assertSame(
            [self::ELIGIBLE_FIELD_ID, self::FIELD_ACCESS_FIELD_ID, self::GROUP_ACCESS_FIELD_ID],
            $widened,
        );
    }

    private function user(array $viewLevels, bool $superUser = false): User
    {
        $user = $this->createMock(User::class);
        $user->method('getAuthorisedViewLevels')->willReturn($viewLevels);
        $user->method('authorise')->willReturn($superUser);

        return $user;
    }

    private function service(): FieldsFilterService
    {
        $db = $this->isolatedDriver();

        $component = new ComponentRecord(['option' => 'com_content', 'enabled' => 1]);
        $component->setParams(new Registry([]));

        $components = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setValue(null, ['com_content' => $component]);

        $contentComponent = new class () implements ComponentInterface, FieldsServiceInterface {
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

        $application = $this->createMock(CMSApplication::class);
        $application->method('isClient')->willReturn(true);
        $application->method('getInput')->willReturn(new Input(['option' => 'com_content', 'view' => 'articles']));
        $application->method('bootComponent')->willReturn($contentComponent);
        Factory::$application = $application;

        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturnCallback(
            static fn ($name, $prefix = '', array $config = []): FieldsModel => new FieldsModel(
                array_merge($config, ['dbo' => $db]),
                null,
            )
        );

        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber(new ThirdPartyOptionFieldSubscriber());

        return new FieldsFilterService($factory, $db, $dispatcher);
    }

    /**
     * Open a test-local connection to the same database under an isolated prefix and install the
     * eligibility fixture through it. Joomla's integration DBTestHelper connection is shared for
     * the whole PHPUnit process, so installing the reduced schema under its normal prefix would
     * leave the shared Joomla core tables replaced for later tests.
     *
     * @return void
     */
    private function loadIsolatedSchema(): void
    {
        $shared = $this->getDBDriver();
        $engine = getenv('JTEST_DB_ENGINE') ?: JTEST_DB_ENGINE;

        $driver = (new DatabaseFactory())->getDriver(
            $engine,
            [
                'database' => getenv('JTEST_DB_NAME') ?: JTEST_DB_NAME,
                'host'     => getenv('JTEST_DB_HOST') ?: JTEST_DB_HOST,
                'user'     => getenv('JTEST_DB_USER') ?: JTEST_DB_USER,
                'password' => getenv('JTEST_DB_PASSWORD') ?: JTEST_DB_PASSWORD,
                'prefix'   => $shared->getPrefix() . self::ISOLATED_PREFIX_SUFFIX,
            ]
        );

        $this->isolatedDriver = $driver;

        $fixture = file_get_contents(
            JPATH_ROOT . '/tests/Integration/datasets/' . strtolower($engine) . '/fieldseligibility.sql'
        );

        if ($fixture === false) {
            throw new \RuntimeException('Unable to read the fieldseligibility.sql fixture.');
        }

        // The fixture keeps ordinary #__ tokens; this connection resolves them to the isolated
        // prefix, so the DROP/CREATE statements inside it can only touch test-owned tables.
        foreach (DatabaseDriver::splitSql($fixture) as $query) {
            $driver->setQuery($query)->execute();
        }
    }

    /**
     * Drop the isolated objects and close the test-local connection. Identifiers keep the #__
     * token so prefix replacement can only ever resolve to the isolated prefix, never to the
     * normal integration tables.
     *
     * @return void
     */
    private function dropIsolatedSchema(): void
    {
        if ($this->isolatedDriver === null) {
            return;
        }

        $failure = null;

        try {
            foreach (self::ISOLATED_TABLES as $table) {
                try {
                    $this->isolatedDriver->dropTable('#__' . $table);
                } catch (\Throwable $exception) {
                    $failure ??= $exception;
                }
            }
        } finally {
            try {
                $this->isolatedDriver->disconnect();
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            } finally {
                $this->isolatedDriver = null;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function restoreJoomlaState(): void
    {
        Factory::$application = $this->previousApplication;

        $plugins = new \ReflectionProperty(PluginHelper::class, 'plugins');
        $plugins->setValue(null, $this->previousPlugins);

        $components = new \ReflectionProperty(ComponentHelper::class, 'components');
        $components->setValue(null, $this->previousComponents);
    }

    private function isolatedDriver(): DatabaseInterface
    {
        if ($this->isolatedDriver === null) {
            throw new \RuntimeException('The isolated eligibility database connection is not available.');
        }

        return $this->isolatedDriver;
    }
}
