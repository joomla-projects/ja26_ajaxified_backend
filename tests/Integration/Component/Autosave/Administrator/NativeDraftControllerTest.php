<?php

/**
 * @package   Joomla.IntegrationTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Component\Autosave\Administrator;

use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Table\Table;
use Joomla\Component\Categories\Administrator\Controller\CategoryController;
use Joomla\Component\Categories\Administrator\Model\CategoryModel;
use Joomla\Component\Languages\Administrator\Controller\OverrideController;
use Joomla\Component\Languages\Administrator\Model\OverrideModel;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Tests\Integration\IntegrationTestCase;
use Psr\Log\NullLogger;

/**
 * Execute public native tasks and real optional draft reconciliation.
 */
class NativeDraftControllerTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use \Joomla\Tests\Integration\Component\Autosave\Administrator\NativeControllerFixture;

    /**
     * @dataProvider outcomes
     */
    public function testNativeResultsAndFailuresKeepTheirExistingContract(
        string $kind,
        string $task,
        string $outcome,
        ?array $metadata,
        string $source,
        string $result
    ): void {
        $componentName = $kind === 'category' ? 'com_categories' : 'com_languages';
        $scope         = $kind === 'category' ? ['extension' => 'com_content'] : ['client' => 'site', 'language' => 'en-GB'];
        $input         = new Input(['option' => $componentName, 'extension' => 'com_content', 'id' => $source]);
        $data          = $kind === 'category'
            ? ['title' => 'Native title', 'parent_id' => '1', 'extension' => 'com_content']
            : ['key' => $result, 'override' => 'Native value', 'both' => '1'];
        $input->post->set('jform', $data);
        $states       = [];
        [$app, $user] = $this->controllerApplication($input, $outcome !== 'denied', $states);
        $app->method('getLogger')->willReturn(new NullLogger());

        $context = $componentName . '.' . $kind;
        $draft   = $this->store->open(
            7,
            [
            'context' => $context, 'target' => $source === '' ? null : $source,
            'scope'   => $scope, 'version' => 1, 'revision' => $source === '' ? null : 'rendered',
            'fields'  => [($kind === 'category' ? 'title' : 'override') => ['control' => 'text']],
            ],
            $this->now
        );
        $payload = [($kind === 'category' ? 'title' : 'override') => 'recoverable'];
        $this->store->preserve(7, $draft['draft_id'], 1, $payload, $this->now, static fn ($row, $values) => $values);
        if ($metadata !== null) {
            $metadata['draft_id'] = $metadata['draft_id'] === 'own' ? $draft['draft_id'] : str_repeat('b', 32);
            $input->post->set('autosave', $metadata);
        }
        $resolver  = new AutosaveContextResolver($app, static fn (): bool => true);
        $service   = new DraftService($resolver, $this->store);

        $this->registerDraftService($service, $outcome !== 'unavailable');

        $model = $this->createMock($kind === 'category' ? CategoryModel::class : OverrideModel::class);
        $model->method('getName')->willReturn($kind);
        $model->method('getState')->willReturnCallback(
            static fn ($key, $default = null) => match ($key) {
                'category.id'     => (int) $result,
                'filter.client'   => 'site',
                'filter.language' => 'en-GB',
                default           => $default,
            }
        );
        $form = new Form('native.' . $kind . '.save', ['control' => 'jform']);
        $form->setCurrentUser($user);
        $form->load('<form><fieldset><field name="title" type="text"/><field name="key" type="text"/></fieldset></form>');
        $model->method('getForm')->willReturn($form);
        $model->method('validate')->willReturnCallback(static fn ($form, $values) => $outcome === 'validation' ? false : $values);
        $model->method('getErrors')->willReturn([new \RuntimeException('Native validation failed')]);
        $model->expects(\in_array($outcome, ['denied', 'validation'], true) ? $this->never() : $this->once())->method('save')->willReturn($outcome !== 'model');
        $model->method('getItem')->willReturn(
            (object) ['id' => (int) $result, 'title' => 'Native title',
            'extension'    => 'com_content', 'params' => [], 'metadata' => [], 'key' => $result]
        );
        if ($kind === 'category') {
            $table = $this->createMock(Table::class);
            $table->method('getKeyName')->willReturn('id');
            $table->method('hasField')->willReturn(false);
            $model->method('getTable')->willReturn($table);
            $model->method('checkout')->willReturn(true);
        }
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $class      = $kind === 'category' ? CategoryController::class : OverrideController::class;
        $controller = new $class(['base_path' => JPATH_ADMINISTRATOR . '/components/' . $componentName], $factory, $app, $input);
        $controller->setDispatcher(new Dispatcher());
        $success = $controller->execute($task);
        $this->assertSame($kind === 'override' ? null : !\in_array($outcome, ['denied', 'validation', 'model'], true), $success);
        $row     = $this->store->read(7, $draft['draft_id'], $this->now);
        $retires = $outcome === 'success' && $metadata !== null && $metadata['draft_id'] === $draft['draft_id']
            && ($metadata['revision'] ?? null) === '1';
        $this->assertSame($retires ? null : $payload, $row['payload']);
        if ($retires && $task !== 'save2copy') {
            $this->assertSame($result, $row['target_id']);
        }
        if (\in_array($task, ['save2menulist', 'save2menublog'], true) && $retires) {
            $this->assertSame((int) $result, $states['com_menus.edit.item']['data']['request']['id']);
        }
    }

    public static function outcomes(): array
    {
        $own   = ['draft_id' => 'own', 'revision' => '1'];
        $cases = [];
        foreach (['apply', 'save', 'save2new', 'save2copy', 'save2menulist', 'save2menublog', 'editAssociations'] as $task) {
            $cases[] = ['category', $task, 'success', $own, '42', $task === 'save2copy' ? '84' : '42'];
        }
        $cases[] = ['category', 'apply', 'success', $own, '', '84'];
        foreach (['validation', 'model', 'denied', 'unavailable'] as $outcome) {
            $cases[] = ['category', 'apply', $outcome, $own, '42', '42'];
        }
        foreach (['apply', 'save', 'save2new'] as $task) {
            $cases[] = ['override', $task, 'success', $own, 'COM_OLD', 'COM_RENAMED'];
        }
        $cases[] = ['override', 'apply', 'success', $own, '', 'COM_CREATED'];
        foreach (['validation', 'model', 'denied', 'unavailable'] as $outcome) {
            $cases[] = ['override', 'apply', $outcome, $own, 'COM_OLD', 'COM_RENAMED'];
        }
        $cases[] = ['override', 'apply', 'success', null, 'COM_OLD', 'COM_RENAMED'];
        $cases[] = ['override', 'apply', 'success', ['draft_id' => 'foreign', 'revision' => '1'], 'COM_OLD', 'COM_RENAMED'];
        $cases[] = ['override', 'apply', 'success', ['draft_id' => 'own', 'revision' => 'bad'], 'COM_OLD', 'COM_RENAMED'];
        return $cases;
    }
}
