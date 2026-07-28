<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  MVC
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\MVC\Controller;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Event\Controller\FormTaskSuccessEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Table\Table;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for \Joomla\CMS\MVC\Controller\FormController.
 *
 * @package     Joomla.UnitTest
 * @subpackage  MVC
 *
 * @testdox     The FormController
 *
 * @since       __DEPLOY_VERSION__
 */
// phpcs:disable PSR1.Classes.ClassDeclaration
class FormControllerTest extends UnitTestCase
{
    /**
     * @testdox  dispatches one confirmed event for successful save tasks
     *
     * @param   string   $task          The Joomla task.
     * @param   integer  $originalId    The submitted record identity.
     * @param   integer  $resultingId   The resulting record identity.
     * @param   string   $redirect      The expected prepared redirect.
     *
     * @return  void
     *
     * @dataProvider successfulSaveTaskProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSuccessfulSaveTasksDispatchOnce(
        string $task,
        int $originalId,
        int $resultingId,
        string $redirect
    ): void {
        [$controller, , $dispatcher] = $this->createController($originalId, true, $resultingId);
        $events                      = $this->collectSuccessEvents($dispatcher);

        $this->assertTrue($controller->execute($task));
        $this->assertCount(1, $events);
        $this->assertSame('com_test.item', $events[0]->getContext());
        $this->assertSame($task, $events[0]->getTask());
        $this->assertSame($originalId, $events[0]->getOriginalId());
        $this->assertSame($resultingId, $events[0]->getResultingId());
        $this->assertSame($redirect, $controller->getPreparedRedirect());
    }

    /**
     * Successful save task cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function successfulSaveTaskProvider(): array
    {
        return [
            'apply'        => ['apply', 42, 42, 'item:42'],
            'save'         => ['save', 42, 42, 'list'],
            'save and new' => ['save2new', 42, 42, 'item:new'],
            'save as copy' => ['save2copy', 42, 99, 'item:99'],
        ];
    }

    /**
     * @testdox  reports the generated identity when a record is created
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCreationReportsGeneratedIdentity(): void
    {
        [$controller, , $dispatcher] = $this->createController(0, true, 73);
        $events                      = $this->collectSuccessEvents($dispatcher);

        $this->assertTrue($controller->execute('apply'));
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->getOriginalId());
        $this->assertSame(73, $events[0]->getResultingId());
        $this->assertSame('item:73', $controller->getPreparedRedirect());
    }

    /**
     * @testdox  dispatches apply only after continuation state is prepared
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testApplyDispatchesAfterContinuationPreparation(): void
    {
        [$controller, , $dispatcher, $sequence] = $this->createController(42, true, 42);
        $redirectAtDispatch                     = null;

        $dispatcher->addListener(
            'onControllerFormTaskSuccess',
            static function () use ($controller, $sequence, &$redirectAtDispatch): void {
                $sequence->add('event');
                $redirectAtDispatch = $controller->getPreparedRedirect();
            }
        );

        $this->assertTrue($controller->execute('apply'));
        $this->assertSame('item:42', $redirectAtDispatch);
        $this->assertLessThan(
            array_search('event', $sequence->entries, true),
            array_search('postSaveHook', $sequence->entries, true)
        );
        $this->assertLessThan(
            array_search('event', $sequence->entries, true),
            array_search('redirect:item:42', $sequence->entries, true)
        );
    }

    /**
     * @testdox  dispatches cancellation after check-in and redirect preparation
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSuccessfulCancelDispatchesOnce(): void
    {
        [$controller, , $dispatcher, $sequence] = $this->createController(42);
        $events                                 = [];
        $redirectAtDispatch                     = null;

        $dispatcher->addListener(
            'onControllerFormTaskSuccess',
            static function (FormTaskSuccessEvent $event) use ($controller, $sequence, &$events, &$redirectAtDispatch): void {
                $sequence->add('event');
                $events[]           = $event;
                $redirectAtDispatch = $controller->getPreparedRedirect();
            }
        );

        $this->assertTrue($controller->execute('cancel'));
        $this->assertCount(1, $events);
        $this->assertSame('cancel', $events[0]->getTask());
        $this->assertSame(42, $events[0]->getOriginalId());
        $this->assertNull($events[0]->getResultingId());
        $this->assertSame('list', $redirectAtDispatch);
        $this->assertLessThan(
            array_search('event', $sequence->entries, true),
            array_search('checkin:42', $sequence->entries, true)
        );
        $this->assertLessThan(
            array_search('event', $sequence->entries, true),
            array_search('redirect:list', $sequence->entries, true)
        );
    }

    /**
     * @testdox  does not dispatch for an unsuccessful save
     *
     * @param   string  $failure  The failure point to configure.
     *
     * @return  void
     *
     * @dataProvider unsuccessfulSaveProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testUnsuccessfulSaveDispatchesNothing(string $failure): void
    {
        [$controller, $model, $dispatcher] = $this->createController(42);

        switch ($failure) {
            case 'form':
                $model->formAvailable = false;
                break;
            case 'authorization':
                $controller->allowSaveResult = false;
                break;
            case 'validation':
                $model->validationResult = false;
                break;
            case 'model':
                $model->saveResult = false;
                break;
        }

        $this->assertNoSuccessEvent($dispatcher);
        $this->assertFalse($controller->execute('save'));
    }

    /**
     * Unsuccessful save cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function unsuccessfulSaveProvider(): array
    {
        return [
            'form unavailable'     => ['form'],
            'authorization denied' => ['authorization'],
            'validation failure'   => ['validation'],
            'model failure'        => ['model'],
        ];
    }

    /**
     * @testdox  does not dispatch when $failure throws
     *
     * @param   string  $failure  The failure point to configure.
     * @param   string  $message  The expected exception message.
     *
     * @return  void
     *
     * @dataProvider exceptionalSaveProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testExceptionalSaveDispatchesNothing(string $failure, string $message): void
    {
        [$controller, , $dispatcher] = $this->createController(42, true, 42);

        if ($failure === 'token validation') {
            $controller->throwToken = true;
        } else {
            $controller->throwPostSave = true;
        }

        $this->assertNoSuccessEvent($dispatcher);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $controller->execute('save');
    }

    /**
     * Exceptional save cases.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function exceptionalSaveProvider(): array
    {
        return [
            'token validation' => ['token validation', 'Token failure'],
            'post-save hook'   => ['post-save hook', 'Post-save failure'],
        ];
    }

    /**
     * @testdox  preserves $task failure semantics when required check-in fails
     *
     * @param   string  $task  The Joomla task.
     *
     * @return  void
     *
     * @dataProvider requiredCheckinFailureProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRequiredCheckinFailureDispatchesNothing(string $task): void
    {
        [$controller, $model, $dispatcher] = $this->createController(42, true, 42);
        $model->checkinResult              = false;

        $this->assertNoSuccessEvent($dispatcher);
        $this->assertFalse($controller->execute($task));
        $this->assertSame('item:42', $controller->getPreparedRedirect());
    }

    /**
     * Tasks whose existing semantics require successful check-in.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function requiredCheckinFailureProvider(): array
    {
        return [
            'save'   => ['save'],
            'cancel' => ['cancel'],
        ];
    }

    /**
     * @testdox  preserves the existing non-fatal apply checkout result
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testApplyCheckoutFailureRetainsExistingSuccessSemantics(): void
    {
        [$controller, $model, $dispatcher] = $this->createController(42, true, 42);
        $model->checkoutResult             = false;
        $events                            = $this->collectSuccessEvents($dispatcher);

        $this->assertTrue($controller->execute('apply'));
        $this->assertCount(1, $events);
    }

    /**
     * @testdox  contains listener failures without changing the prepared result
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testListenerExceptionIsNonFatal(): void
    {
        [$controller, , $dispatcher] = $this->createController(42, true, 42);
        $logger                      = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('onControllerFormTaskSuccess'),
                ['category' => 'controller']
            );

        $controller->setLogger($logger);
        $dispatcher->addListener(
            'onControllerFormTaskSuccess',
            static function (): void {
                throw new \RuntimeException('Listener failure with private data');
            }
        );

        $this->assertTrue($controller->execute('save'));
        $this->assertSame('list', $controller->getPreparedRedirect());
    }

    /**
     * @testdox  leaves successful controller behavior unchanged without listeners
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNoListenerLeavesControllerBehaviorUnchanged(): void
    {
        [$controller] = $this->createController(42, true, 42);

        $this->assertTrue($controller->execute('save'));
        $this->assertSame('list', $controller->getPreparedRedirect());
    }

    /**
     * @testdox  exposes only immutable operation identity facts
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testEventPayloadAndImmutability(): void
    {
        [$controller, , $dispatcher] = $this->createController(42, true, 99);
        $events                      = $this->collectSuccessEvents($dispatcher);

        $this->assertTrue($controller->execute('save2copy'));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(FormTaskSuccessEvent::class, $event);
        $this->assertSame('onControllerFormTaskSuccess', $event->getName());
        $this->assertSame('com_test.item', $event->getArgument('context'));
        $this->assertSame('save2copy', $event->getArgument('task'));
        $this->assertSame(42, $event->getArgument('originalId'));
        $this->assertSame(99, $event->getArgument('resultingId'));
        $this->assertNull($event->getArgument('subject'));
        $this->assertNull($event->getArgument('jform'));
        $this->assertNull($event->getArgument('token'));

        $this->expectException(\BadMethodCallException::class);
        $event['context'] = 'com_changed.item';
    }

    /**
     * Create a controller and its test collaborators.
     *
     * @param   integer   $recordId      The submitted record identity.
     * @param   boolean   $withCheckin   Whether the table supports check-in.
     * @param   ?integer  $resultingId   The resulting model identity.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function createController(int $recordId, bool $withCheckin = true, ?int $resultingId = null): array
    {
        $sequence = new FormControllerSequence();
        $table    = $this->createStub(Table::class);
        $form     = $this->createStub(Form::class);
        $input    = new FormControllerInput(
            ['id' => $recordId],
            ['jform' => ['id' => $recordId, 'title' => 'Unit test']]
        );

        $table->method('getKeyName')->willReturn('id');
        $table->method('hasField')->willReturnCallback(
            static fn (string $field): bool => $withCheckin
                && \in_array($field, ['checked_out', 'checked_out_time'], true)
        );

        $model              = new FormControllerModel($table, $form, $sequence);
        $model->resultingId = $resultingId ?? $recordId;

        $controller = new TestFormController(
            $model,
            $sequence,
            ['name' => 'item', 'base_path' => __DIR__],
            $this->createStub(MVCFactoryInterface::class),
            $this->createStub(CMSWebApplicationInterface::class),
            $input,
            $this->createStub(FormFactoryInterface::class)
        );
        $dispatcher = new Dispatcher();
        $controller->setDispatcher($dispatcher);

        return [$controller, $model, $dispatcher, $sequence];
    }

    /**
     * Collect confirmed success events.
     *
     * @param   Dispatcher  $dispatcher  The event dispatcher.
     *
     * @return  \ArrayObject
     *
     * @since   __DEPLOY_VERSION__
     */
    private function collectSuccessEvents(Dispatcher $dispatcher): \ArrayObject
    {
        $events = new \ArrayObject();

        $dispatcher->addListener(
            'onControllerFormTaskSuccess',
            static function (FormTaskSuccessEvent $event) use ($events): void {
                $events->append($event);
            }
        );

        return $events;
    }

    /**
     * Register a listener which fails the test if a success event is dispatched.
     *
     * @param   Dispatcher  $dispatcher  The event dispatcher.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function assertNoSuccessEvent(Dispatcher $dispatcher): void
    {
        $dispatcher->addListener(
            'onControllerFormTaskSuccess',
            function (): void {
                $this->fail('A confirmed success event was dispatched for an unsuccessful task.');
            }
        );
    }
}

/**
 * Shared operation sequence recorder.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FormControllerSequence
{
    /**
     * Recorded operations.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    public array $entries = [];

    /**
     * Record an operation.
     *
     * @param   string  $entry  The operation label.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

/**
 * Input with an isolated POST source.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FormControllerInput extends Input
{
    /**
     * POST input.
     *
     * @var    Input
     * @since  __DEPLOY_VERSION__
     */
    private Input $postInput;

    /**
     * Constructor.
     *
     * @param   array  $data      Request data.
     * @param   array  $postData  POST data.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $data, array $postData)
    {
        parent::__construct($data);

        $this->postInput = new Input($postData);
    }

    /**
     * Get an input source.
     *
     * @param   string  $name  The source name.
     *
     * @return  Input
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __get($name)
    {
        return $name === 'post' ? $this->postInput : parent::__get($name);
    }
}

/**
 * Minimal form model test double.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FormControllerModel extends AdminModel
{
    /**
     * Test table.
     *
     * @var    Table
     * @since  __DEPLOY_VERSION__
     */
    private Table $testTable;

    /**
     * Test form.
     *
     * @var    Form
     * @since  __DEPLOY_VERSION__
     */
    private Form $form;

    /**
     * Operation recorder.
     *
     * @var    FormControllerSequence
     * @since  __DEPLOY_VERSION__
     */
    private FormControllerSequence $sequence;

    /**
     * Whether the form is available.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $formAvailable = true;

    /**
     * Validation result, or null to return the submitted data.
     *
     * @var    array|boolean|null
     * @since  __DEPLOY_VERSION__
     */
    public array|bool|null $validationResult = null;

    /**
     * Model save result.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $saveResult = true;

    /**
     * Check-in result.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $checkinResult = true;

    /**
     * Checkout result.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $checkoutResult = true;

    /**
     * Resulting model identity.
     *
     * @var    ?integer
     * @since  __DEPLOY_VERSION__
     */
    public ?int $resultingId = null;

    /**
     * Constructor.
     *
     * @param   Table                   $table     The test table.
     * @param   Form                    $form      The test form.
     * @param   FormControllerSequence  $sequence  The operation recorder.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(Table $table, Form $form, FormControllerSequence $sequence)
    {
        $this->testTable   = $table;
        $this->form        = $form;
        $this->sequence    = $sequence;
        $this->name        = 'item';
        $this->__state_set = true;
    }

    /**
     * Get the test table.
     *
     * @param   string  $name     The table name.
     * @param   string  $prefix   The table prefix.
     * @param   array   $options  Table options.
     *
     * @return  Table
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getTable($name = '', $prefix = '', $options = [])
    {
        return $this->testTable;
    }

    /**
     * Get the test form.
     *
     * @param   array    $data      Form data.
     * @param   boolean  $loadData  Whether to load model data.
     *
     * @return  Form|boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getForm($data = [], $loadData = true)
    {
        return $this->formAvailable ? $this->form : false;
    }

    /**
     * Validate submitted data.
     *
     * @param   Form     $form   The form.
     * @param   array    $data   Submitted data.
     * @param   ?string  $group  The field group.
     *
     * @return  array|boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function validate($form, $data, $group = null)
    {
        return $this->validationResult ?? $data;
    }

    /**
     * Save submitted data.
     *
     * @param   array  $data  Validated data.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function save($data)
    {
        $this->sequence->add('save');

        if (!$this->saveResult) {
            return false;
        }

        $this->setState('item.id', $this->resultingId);

        return true;
    }

    /**
     * Check in a record.
     *
     * @param   ?integer  $pk  The record identity.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function checkin($pk = null)
    {
        $this->sequence->add('checkin:' . $pk);

        return $this->checkinResult;
    }

    /**
     * Check out a record.
     *
     * @param   ?integer  $pk  The record identity.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function checkout($pk = null)
    {
        $this->sequence->add('checkout:' . $pk);

        return $this->checkoutResult;
    }
}

/**
 * FormController test double exposing prepared controller state.
 *
 * @since  __DEPLOY_VERSION__
 */
final class TestFormController extends FormController
{
    /**
     * Test model.
     *
     * @var    FormControllerModel
     * @since  __DEPLOY_VERSION__
     */
    private FormControllerModel $testModel;

    /**
     * Operation recorder.
     *
     * @var    FormControllerSequence
     * @since  __DEPLOY_VERSION__
     */
    private FormControllerSequence $sequence;

    /**
     * Whether post-save processing should throw.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $throwPostSave = false;

    /**
     * Whether token validation should throw.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $throwToken = false;

    /**
     * Save authorization result.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    public bool $allowSaveResult = true;

    /**
     * Constructor.
     *
     * @param   FormControllerModel       $model        The test model.
     * @param   FormControllerSequence    $sequence     The operation recorder.
     * @param   array                     $config       Controller configuration.
     * @param   MVCFactoryInterface       $factory      The MVC factory.
     * @param   CMSWebApplicationInterface $app         The web application.
     * @param   Input                     $input        Request input.
     * @param   FormFactoryInterface      $formFactory  The form factory.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(
        FormControllerModel $model,
        FormControllerSequence $sequence,
        array $config,
        MVCFactoryInterface $factory,
        CMSWebApplicationInterface $app,
        Input $input,
        FormFactoryInterface $formFactory
    ) {
        $this->option    = 'com_test';
        $this->context   = 'item';
        $this->view_item = 'item';
        $this->view_list = 'items';
        $this->testModel = $model;
        $this->sequence  = $sequence;

        parent::__construct($config, $factory, $app, $input, $formFactory);
    }

    /**
     * Bypass token validation in the controller test double.
     *
     * @param   string   $method    The request method.
     * @param   boolean  $redirect  Whether to redirect on failure.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function checkToken($method = 'post', $redirect = true)
    {
        $this->sequence->add('token');

        if ($this->throwToken) {
            throw new \RuntimeException('Token failure');
        }

        return true;
    }

    /**
     * Get the configured model.
     *
     * @param   string  $name    The model name.
     * @param   string  $prefix  The model prefix.
     * @param   array   $config  Model configuration.
     *
     * @return  BaseDatabaseModel
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getModel($name = '', $prefix = '', $config = ['ignore_request' => true])
    {
        return $this->testModel;
    }

    /**
     * Get the prepared redirect.
     *
     * @return  ?string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getPreparedRedirect(): ?string
    {
        return $this->redirect;
    }

    /**
     * Record redirect preparation.
     *
     * @param   string   $url   The redirect URL.
     * @param   ?string  $msg   The redirect message.
     * @param   ?string  $type  The message type.
     *
     * @return  static
     *
     * @since   __DEPLOY_VERSION__
     */
    public function setRedirect($url, $msg = null, $type = null)
    {
        $this->sequence->add('redirect:' . $url);

        return parent::setRedirect($url, $msg, $type);
    }

    /**
     * Permit all test saves.
     *
     * @param   array   $data  Submitted data.
     * @param   string  $key   The primary key.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function allowSave($data, $key = 'id')
    {
        return $this->allowSaveResult;
    }

    /**
     * Return the test item redirect.
     *
     * @param   ?integer  $recordId  The record identity.
     * @param   string    $urlVar    The identity variable.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function getRedirectUrlToItem($recordId = null, $urlVar = 'id'): string
    {
        return 'item:' . ($recordId ?: 'new');
    }

    /**
     * Return the test list redirect.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function getRedirectUrlToList(): string
    {
        return 'list';
    }

    /**
     * Keep calendar filtering outside these tests.
     *
     * @param   Form   $form  The form.
     * @param   array  $data  Submitted data.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function applyFilterForCalendarFieldsToRequestData(Form $form, array $data): array
    {
        return $data;
    }

    /**
     * Keep success-message translation outside these tests.
     *
     * @param   integer  $recordId  The submitted identity.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function setSaveSuccessMessage($recordId): void
    {
    }

    /**
     * Run component post-save behavior.
     *
     * @param   BaseDatabaseModel  $model      The saved model.
     * @param   array              $validData  Validated data.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function postSaveHook(BaseDatabaseModel $model, $validData = [])
    {
        $this->sequence->add('postSaveHook');

        if ($this->throwPostSave) {
            throw new \RuntimeException('Post-save failure');
        }
    }
}
