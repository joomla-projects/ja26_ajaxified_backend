<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Autosave\Administrator;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Categories\Administrator\Model\CategoryModel;
use Joomla\Component\Guidedtours\Administrator\Model\StepModel;
use Joomla\Component\Guidedtours\Administrator\Model\TourModel;
use Joomla\Component\Workflow\Administrator\Model\StageModel;
use Joomla\Component\Workflow\Administrator\Model\TransitionModel;
use Joomla\Component\Workflow\Administrator\Model\WorkflowModel;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;
use Joomla\Tests\Unit\UnitTestCase;

/** Native form state must remain authoritative even when the endpoint request disagrees. */
class NativeFormStateTest extends UnitTestCase
{
    use NativeReadFixture;

    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousApplication = Factory::$application;
        $application               = $this->createMock(CMSApplication::class);
        $application->method('getInput')->willReturn(new Input(['extension' => 'com_users', 'tour_id' => 99]));
        $application->method('getDispatcher')->willReturn(new Dispatcher());
        $application->method('bootComponent')->willReturn($this->createMock(ComponentInterface::class));
        Factory::$application = $application;
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    /** @dataProvider workflowModels */
    public function testWorkflowRulesUseBoundExtension(string $class): void
    {
        $model = new $class(['ignore_request' => true], $this->createMock(MVCFactoryInterface::class));
        $model->setState('filter.extension', 'com_content.article');
        $model->setDispatcher(new Dispatcher());
        $form = new Form('ordinary.workflow');
        $form->load('<form><field name="rules" type="rules"/></form>');
        (new \ReflectionMethod($class, 'preprocessForm'))->invoke($model, $form, []);

        $this->assertSame('com_content', $form->getFieldAttribute('rules', 'component'));
    }

    public static function workflowModels(): array
    {
        return [[WorkflowModel::class], [StageModel::class], [TransitionModel::class]];
    }

    /** @dataProvider formPermissions */
    public function testOrdinaryFormsRetainEditStateRestrictions(string $class, bool $allowed): void
    {
        $form = new Form('ordinary.permissions');
        $form->load('<form><field name="id" type="hidden"/><field name="published" type="list"/>'
            . '<field name="default" type="radio"/><field name="created" type="calendar"/>'
            . '<field name="modified" type="calendar"/><field name="language" type="text"/>'
            . '<field name="from_stage_id" type="sql"/><field name="to_stage_id" type="sql"/></form>');
        $form->setValue('id', null, 99);
        $model = $this->getMockBuilder($class)
            ->setConstructorArgs([['ignore_request' => true], $this->createMock(MVCFactoryInterface::class)])
            ->onlyMethods(['loadForm', 'getItem', 'canEditState'])->getMock();
        $model->method('loadForm')->willReturn($form);
        $item = (object) ['id' => 42, 'workflow_id' => 7];
        $model->expects($this->once())->method('getItem')->with(42)->willReturn($item);
        $model->expects($this->once())->method('canEditState')
            ->with($this->callback(static fn ($item) => $item->id === 42))->willReturn($allowed);
        $model->setDatabase(Factory::$database);

        $this->assertSame($form, $model->getForm(['id' => 42], false));
        $this->assertSame($allowed ? null : 'true', $form->getFieldAttribute('published', 'disabled'));
        $this->assertSame($allowed ? null : 'unset', $form->getFieldAttribute('published', 'filter'));
        if ($class === TransitionModel::class) {
            $where = $form->getFieldAttribute('from_stage_id', 'sql_where');
            $this->assertStringContainsString('workflow_id = 7', $where);
        }
    }

    public static function formPermissions(): array
    {
        $cases = [];
        foreach ([WorkflowModel::class, StageModel::class, TransitionModel::class, StepModel::class] as $class) {
            foreach ([true, false] as $allowed) {
                $cases[] = [$class, $allowed];
            }
        }

        return $cases;
    }

    public function testCategoryPreparationUsesBoundComponentAndSection(): void
    {
        $model = $this->getMockBuilder(CategoryModel::class)
            ->setConstructorArgs([['ignore_request' => true], $this->createMock(MVCFactoryInterface::class)])
            ->onlyMethods(['getAssoc'])->getMock();
        $model->method('getAssoc')->willReturn(false);
        $model->setState('category.extension', 'com_content.article');
        $model->setState('category.component', 'com_content');
        $model->setState('category.section', 'article');
        $model->setDispatcher(new Dispatcher());
        $form = new Form('ordinary.category');
        $form->load('<form><field name="rules" type="rules"/></form>');
        (new \ReflectionMethod(CategoryModel::class, 'preprocessForm'))->invoke($model, $form, []);

        $this->assertSame('com_content', $form->getFieldAttribute('rules', 'component'));
        $this->assertSame('category.article', $form->getFieldAttribute('rules', 'section'));
    }

    public function testNewStepInheritsTheBoundTourInsteadOfAnotherRequestsTour(): void
    {
        $factory = $this->createMock(MVCFactoryInterface::class);
        $tour    = $this->createMock(TourModel::class);
        $tour->expects($this->once())->method('getItem')->with(7)->willReturn((object) ['language' => 'fr-FR']);
        $factory->method('createModel')->with('Tour', 'Administrator', ['ignore_request' => true])->willReturn($tour);
        $component = $this->createMock(\Joomla\CMS\Extension\MVCComponent::class);
        $component->method('getMVCFactory')->willReturn($factory);
        $application = $this->createMock(CMSApplication::class);
        $application->method('bootComponent')->with('com_guidedtours')->willReturn($component);
        $application->method('getUserState')->with('com_guidedtours.tour_id')->willReturn(99);
        Factory::$application = $application;
        $model                = $this->getMockBuilder(StepModel::class)
            ->setConstructorArgs([['ignore_request' => true], $this->createMock(MVCFactoryInterface::class)])
            ->onlyMethods(['getTable', 'getName'])->getMock();
        $model->method('getName')->willReturn('step');
        $model->method('getTable')->willReturn((object) ['id' => 0]);
        $model->setState('step.id', 0);
        $model->setState('step.tour_id', 7);

        $item = $model->getItem();
        $this->assertSame(7, $item->tour_id);
        $this->assertSame('fr-FR', $item->language);
    }
}
