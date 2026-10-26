<?php

/**
 * @package   Joomla.IntegrationTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Component\Content\Administrator\Controller;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Table\Table;
use Joomla\Component\Content\Administrator\Controller\ArticleController;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Tests\Integration\IntegrationTestCase;
use Psr\Log\LoggerInterface;

/**
 * Public native controller tasks and real optional reconciliation; no private trait reflection.
 */
class ArticleControllerAutosaveTest extends IntegrationTestCase implements \Joomla\Tests\Integration\DBTestInterface
{
    use \Joomla\Tests\Integration\Component\Autosave\Administrator\NativeControllerFixture;

    /**
     * @dataProvider nativeOutcomes
     */
    public function testNativeTaskKeepsItsResultAndOnlySuccessfulBoundMetadataRetires(
        string $task,
        string $outcome,
        ?array $metadata,
        int $source,
        int $result,
        bool $loggerFails = false
    ): void {
        $input = new Input(['option' => 'com_content', 'id' => $source]);
        $input->post->set('jform', ['title' => 'Native title', 'alias' => '', 'catid' => '2', 'articletext' => 'Native body']);
        $states = [];
        [$app, $user] = $this->controllerApplication($input, true, $states);
        $logger = $this->createMock(LoggerInterface::class);
        $warning = $logger->expects($outcome === 'unavailable' ? $this->once() : $this->never())->method('warning')->with(
            'Optional draft reconciliation failed.',
            ['exception_class' => \RuntimeException::class]
        );
        if ($loggerFails) {
            $warning->willThrowException(new \RuntimeException('Private logger detail'));
        }
        $app->method('getLogger')->willReturn($logger);
        $descriptor = [
            'context' => 'com_content.article', 'target' => $source === 0 ? null : (string) $source,
            'scope'   => [], 'version' => 1, 'revision' => $source === 0 ? null : 'rendered',
            'fields'  => ['title' => ['control' => 'text']],
        ];
        $draft = $this->store->open(7, $descriptor, $this->now);
        $this->store->preserve(7, $draft['draft_id'], 1, ['title' => 'recoverable'], $this->now, static fn ($row, $payload) => $payload);
        if ($metadata !== null) {
            $metadata['draft_id'] = $metadata['draft_id'] === 'own' ? $draft['draft_id'] : str_repeat('b', 32);
            $input->post->set('autosave', $metadata);
        }
        $resolver  = new AutosaveContextResolver($app, static fn (): bool => true);
        $service   = new DraftService($resolver, $this->store);

        $this->registerDraftService($service, $outcome !== 'unavailable');

        $table = $this->createMock(Table::class);
        $table->method('getKeyName')->willReturn('id');
        $table->method('hasField')->willReturn(\in_array($outcome, ['checkin', 'checkout'], true));
        $model = $this->createMock(\Joomla\Component\Content\Administrator\Model\ArticleModel::class);
        $model->method('getTable')->willReturn($table);
        $model->method('getName')->willReturn('article');
        $model->method('getState')->willReturnCallback(static fn ($key, $default = null) => $key === 'article.id' ? $result : $default);
        $form = new Form('native.article.save', ['control' => 'jform']);
        $form->setCurrentUser($user);
        $sourceXml = simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_content/forms/article.xml');
        $xml       = new \SimpleXMLElement('<form><fieldset/></form>');
        foreach ($sourceXml->xpath('//field[@name="title" or @name="alias" or @name="articletext" or @name="catid" or @name="id"]') as $field) {
            $imported = dom_import_simplexml($field);
            $target   = dom_import_simplexml($xml->fieldset);
            $target->appendChild($target->ownerDocument->importNode($imported, true));
        }
        $form->load($xml);
        $model->method('getForm')->willReturn($form);
        $model->method('validate')->willReturnCallback(static fn ($form, $data) => $outcome === 'validation' ? false : $data);
        $model->method('getErrors')->willReturn([new \RuntimeException('Native validation failure')]);
        $model->expects($outcome === 'validation' ? $this->never() : $this->once())->method('save')
            ->willReturn($outcome !== 'model');
        $model->method('getItem')->willReturn((object) ['id' => $result, 'title' => 'Native title']);
        $model->method('checkin')->willReturn($outcome !== 'checkin');
        $model->method('checkout')->willReturn($outcome !== 'checkout');
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->willReturn($model);
        $controller = new ArticleController(
            ['base_path' => JPATH_ADMINISTRATOR . '/components/com_content'],
            $factory,
            $app,
            $input
        );
        $controller->setDispatcher(new Dispatcher());
        $success = $controller->execute($task);
        $this->assertSame(!\in_array($outcome, ['validation', 'model', 'checkin'], true), $success);
        $row     = $this->store->read(7, $draft['draft_id'], $this->now);
        $retires = $result > 0 && \in_array($outcome, ['success', 'checkout'], true) && $metadata !== null && $metadata['draft_id'] === $draft['draft_id']
            && ($metadata['revision'] ?? null) === '1';
        $this->assertSame($retires ? null : ['title' => 'recoverable'], $row['payload']);
        if ($retires && $source === 0) {
            $this->assertSame((string) $result, $row['target_id']);
        }
        if ($task === 'save2menu' && $success) {
            $this->assertSame($result, $states['com_menus.edit.item']['data']['request']['id']);
        }
        if (!$success) {
            $this->assertArrayHasKey('com_content.edit.article.data', $states);
        }
    }

    /**
     * @dataProvider preparationFailures
     */
    public function testFailedOptionalModelPreparationCannotChangeNativeStateOrExposeValues(string $failure, bool $loggerFails): void
    {
        $input = new Input(['id' => 42]);
        $input->post->set('autosave', ['draft_id' => str_repeat('a', 32), 'revision' => '1']);
        $input->post->set('jform', ['title' => 'Private local form value']);
        $logger = $this->createMock(LoggerInterface::class);
        $warning = $logger->expects($failure === 'domain' ? $this->never() : $this->once())->method('warning')->with(
            'Optional draft save preparation failed.',
            ['exception_class' => \RuntimeException::class]
        );
        if ($loggerFails) {
            $warning->willThrowException(new \RuntimeException('Private logger detail'));
        }
        $app = $this->createMock(CMSApplication::class);
        $app->method('getLogger')->willReturn($logger);
        $app->expects($this->never())->method('enqueueMessage');
        $app->expects($this->never())->method('redirect');
        $app->expects($this->never())->method('bootComponent');
        $model = $this->createMock(\Joomla\Component\Content\Administrator\Model\ArticleModel::class);
        $model->method('getName')->willReturn('article');
        $model->method('getState')->with('article.id')->willReturn(42);
        $model->method('getItem')->willThrowException(
            $failure === 'domain'
            ? new AutosaveException('unsupported_context', 'Private domain detail')
            : new \RuntimeException('Private SQL, request and credentials')
        );
        $model->expects($this->never())->method('save');
        DraftService::reconcileNativeModelSave($app, $input, $model, 'com_content.article', 'save');
        $this->assertSame(42, $model->getState('article.id'));
        $this->assertSame(['title' => 'Private local form value'], $input->post->get('jform', [], 'array'));
    }

    public static function preparationFailures(): array
    {
        return [['unexpected', false], ['unexpected', true], ['domain', false]];
    }

    public static function nativeOutcomes(): array
    {
        $own = ['draft_id' => 'own', 'revision' => '1'];
        return [
            ['apply', 'success', $own, 42, 42],
            ['save', 'success', null, 42, 42],
            ['save', 'success', ['draft_id' => 'own', 'revision' => 'invalid'], 42, 42],
            ['save', 'success', ['draft_id' => 'foreign', 'revision' => '1'], 42, 42],
            ['save', 'unavailable', $own, 42, 42],
            ['apply', 'unavailable', $own, 42, 42, true],
            ['apply', 'validation', $own, 42, 42],
            ['apply', 'model', $own, 42, 42],
            ['save', 'checkin', $own, 42, 42],
            ['apply', 'checkout', $own, 42, 42],
            ['apply', 'success', $own, 0, 84],
            ['apply', 'success', $own, 0, 0],
            ['save2new', 'success', $own, 42, 42],
            ['save2copy', 'success', $own, 42, 84],
            ['save2menu', 'success', $own, 42, 42],
            ['editAssociations', 'success', $own, 42, 42],
            ['editAssociations', 'validation', $own, 42, 42],
            ['editAssociations', 'model', $own, 42, 42],
        ];
    }
}
