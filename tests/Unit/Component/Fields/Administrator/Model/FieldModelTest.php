<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Fields\Administrator\Model;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Model\FieldModel;
use Joomla\Input\Input;
use Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;
use Joomla\Tests\Unit\UnitTestCase;

/** Native getForm honors explicit loading without changing its normal default. */
class FieldModelTest extends UnitTestCase
{
    use NativeReadFixture;

    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousApplication = Factory::$application;
        $application               = $this->createMock(CMSApplication::class);
        $application->method('getInput')->willReturn(new Input());
        $application->method('getDispatcher')->willReturn(new \Joomla\Event\Dispatcher());
        $application->method('getLanguage')->willReturn(new \Joomla\CMS\Language\Language('en-GB', false));
        $user = $this->createMock(User::class);
        $user->method('getAuthorisedViewLevels')->willReturn([1]);
        $session = $this->createMock(\Joomla\Session\SessionInterface::class);
        $session->method('get')->willReturn($user);
        $application->method('getSession')->willReturn($session);
        Factory::$application = $application;
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    /** @dataProvider loading */
    public function testNativeFormReceivesTheRequestedLoadDataContract(?bool $loadData, bool $expected): void
    {
        $user = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);
        $model = $this->getMockBuilder(FieldModel::class)->disableOriginalConstructor()->onlyMethods(['loadForm', 'getCurrentUser'])->getMock();
        $model->setState('field.context', 'com_content.article');
        $model->setState('field.component', 'com_content');
        $model->method('getCurrentUser')->willReturn($user);
        $form = new Form('native.field.loading', ['control' => 'jform']);
        $form->load('<form><field name="title" type="text"/></form>');
        $data = ['context' => 'com_content.article', 'type' => 'text'];
        $model->expects($this->once())->method('loadForm')->with('com_fields.field.com_content.article', 'field', ['control' => 'jform', 'load_data' => $expected, 'data' => $data])->willReturn($form);
        $this->assertSame($form, $loadData === null ? $model->getForm($data) : $model->getForm($data, $loadData));
        $this->assertSame('text', $model->getState('field.type'));
    }

    public static function loading(): array
    {
        return [[false, false], [true, true], [null, true]];
    }

    /**
     * @dataProvider pluginPreparation
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRealNativePreparationRetainsTypeParametersAndDataAuthority(bool $loadData): void
    {
        if (!\defined('JPATH_COMPONENT')) {
            \define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_fields');
        }
        require_once JPATH_PLUGINS . '/fields/text/src/Extension/Text.php';
        $component = $this->createMock(\Joomla\CMS\Extension\ComponentInterface::class);
        Factory::$application->method('bootComponent')->willReturn($component);
        $dispatcher = Factory::$application->getDispatcher();
        $plugin     = new \Joomla\Plugin\Fields\Text\Extension\Text(['name' => 'text', 'type' => 'fields', 'params' => '{}']);
        $dispatcher->addSubscriber($plugin);
        $user   = $this->createMock(User::class);
        $assets = [];
        $user->method('authorise')->willReturnCallback(static function ($action, $asset = null) use (&$assets): bool {
            $assets[] = $asset;

            return true;
        });
        $model = $this->getMockBuilder(FieldModel::class)->disableOriginalConstructor()->onlyMethods(['getCurrentUser', 'bootComponent', 'loadFormData'])->getMock();
        $model->method('getCurrentUser')->willReturn($user);
        $model->method('bootComponent')->willReturn($component);
        $canonical = ['id' => 42, 'context' => 'com_content.article', 'type' => 'text', 'title' => 'Canonical field', 'fieldparams' => ['maxlength' => 20]];
        $errorData = array_replace($canonical, ['title' => 'Native failed-validation value', 'fieldparams' => ['maxlength' => 9]]);
        $model->expects($loadData ? $this->once() : $this->never())->method('loadFormData')->willReturn($errorData);
        foreach (['id' => 42, 'context' => 'com_content.article', 'component' => 'com_content', 'section' => 'article'] as $key => $value) {
            $model->setState('field.' . $key, $value);
        }
        $factory = new \Joomla\CMS\Form\FormFactory();
        $factory->setDatabase(Factory::$database);
        $model->setFormFactory($factory);
        $model->setDispatcher($dispatcher);
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_fields/forms');
        $form = $model->getForm($canonical, $loadData);
        $this->assertSame('number', $form->getFieldAttribute('maxlength', 'type', null, 'fieldparams'), 'The real Text plugin must prepare its parameter XML.');
        $this->assertSame($loadData ? 9 : 20, $form->getValue('maxlength', 'fieldparams'));
        $this->assertSame($loadData ? $errorData['title'] : $canonical['title'], $form->getValue('title'));
        $this->assertSame('true', $form->getFieldAttribute('type', 'readonly'));
        $this->assertEmpty($form->getFieldAttribute('only_use_in_subform', 'showon'));
        $this->assertContains('com_content.field.42', $assets, 'State-bound identity must control native edit-state authority.');
    }

    public static function pluginPreparation(): array
    {
        return [[false], [true]];
    }
}
