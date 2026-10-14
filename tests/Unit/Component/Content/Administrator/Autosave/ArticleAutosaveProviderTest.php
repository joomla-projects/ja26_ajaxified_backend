<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Content\Administrator\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\DraftProjection;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Administrator\Autosave\ArticleAutosaveProvider;
use Joomla\Component\Content\Administrator\Model\ArticleModel;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Prepared native Article form and native controller policy, without canonical draft writes.
 */
class ArticleAutosaveProviderTest extends UnitTestCase
{
    private function form(): Form
    {
        $form = new Form('com_content.article', ['control' => 'jform']);
        $form->setCurrentUser($this->user());
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_content/forms/article.xml');
        $form->bind(['title' => 'validation error value', 'catid' => '2']);

        return $form;
    }

    private function item(): object
    {
        return (object) [
            'id'    => 42, 'title' => 'saved', 'alias' => 'saved-alias', 'articletext' => 'native read-more body',
            'catid' => 2, 'version' => 5, 'created_by' => 7, 'checked_out' => 0,
        ];
    }

    private function user(array $denied = []): User
    {
        $user        = $this->createMock(User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturnCallback(
            static fn (string $action, ?string $asset = null): bool => !\in_array($action . ':' . $asset, $denied, true)
        );
        $user->method('getAuthorisedCategories')->willReturn([]);

        return $user;
    }

    public function testRenderRevisionUsesCanonicalSelectedValuesNotErrorFormData(): void
    {
        $provider = new ArticleAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $item     = $this->item();
        $form     = $this->form();
        $original = $provider->describe($form, $item, $this->user());
        $this->assertSame(['title', 'alias', 'articletext', 'catid'], array_keys($original['fields']));
        $this->assertSame('saved', $original['values']['title']);
        $form->bind(['title' => 'different unsaved value']);
        $this->assertSame($original['revision'], $provider->describe($form, $item, $this->user())['revision']);

        foreach (['title', 'alias', 'articletext', 'catid', 'version'] as $key) {
            $changed       = clone $item;
            $changed->$key = \is_int($changed->$key) ? $changed->$key + 1 : $changed->$key . '-changed';
            $this->assertNotSame($original['revision'], $provider->describe($form, $changed, $this->user())['revision']);
        }
    }

    public function testReadReusesNativeModelAndPreparedFormWithoutSaving(): void
    {
        $model = $this->createMock(ArticleModel::class);
        $model->expects($this->never())->method('save');
        $model->expects($this->once())->method('getItem')->with(42)->willReturn($this->item());
        $model->expects($this->once())->method('getForm')->with($this->isType('array'), false)->willReturn($this->form());
        $factory = $this->createMock(MVCFactoryInterface::class);
        $factory->method('createModel')->with('Article', 'Administrator', ['ignore_request' => true])->willReturn($model);
        $descriptor = (new ArticleAutosaveProvider($factory))->read(
            ['context' => 'com_content.article', 'target_id' => '42', 'scope' => []],
            $this->user()
        );
        $this->assertSame('42', $descriptor['target']);
        $this->assertSame('native read-more body', $descriptor['values']['articletext']);
    }

    public function testIncompleteDraftAndExcludedValues(): void
    {
        $provider   = new ArticleAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $descriptor = $provider->describe($this->form(), $this->item(), $this->user());
        $this->assertSame(['title' => ''], DraftProjection::normalize(['title' => ''], $descriptor['fields']));
        $this->expectException(AutosaveException::class);
        DraftProjection::normalize(['title' => '', 'state' => '1', 'password' => 'secret'], $descriptor['fields']);
    }

    public function testReadonlyOrUnclassifiedPreparedControlIsDisclosed(): void
    {
        $form = $this->form();
        $form->setFieldAttribute('articletext', 'readonly', 'true');
        $form->setFieldAttribute('alias', 'type', 'password');
        $descriptor = (new ArticleAutosaveProvider($this->createMock(MVCFactoryInterface::class)))->describe(
            $form,
            $this->item(),
            $this->user()
        );
        $this->assertSame(['title', 'catid'], array_keys($descriptor['fields']));
        $this->assertSame(['alias', 'articletext'], $descriptor['excluded']);
    }

    /** @dataProvider deniedCases */
    public function testNativeAccessAndCheckoutAreRechecked(array $denied, int $owner, int $checkout, string $code): void
    {
        $item              = $this->item();
        $item->created_by  = $owner;
        $item->checked_out = $checkout;
        try {
            (new ArticleAutosaveProvider($this->createMock(MVCFactoryInterface::class)))->describe($this->form(), $item, $this->user($denied));
            $this->fail('Expected native access denial');
        } catch (AutosaveException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
        }
    }

    public static function deniedCases(): array
    {
        return [
            [['core.manage:com_content'], 7, 0, 'forbidden'],
            [['core.edit:com_content.article.42', 'core.edit.own:com_content.article.42'], 7, 0, 'forbidden'],
            [['core.edit:com_content.article.42'], 8, 0, 'forbidden'],
            [[], 7, 8, 'checkout_conflict'],
        ];
    }

    public function testNativeEditOwnAndCurrentCreationCategory(): void
    {
        $provider   = new ArticleAutosaveProvider($this->createMock(MVCFactoryInterface::class));
        $descriptor = $provider->describe($this->form(), $this->item(), $this->user(['core.edit:com_content.article.42']));
        $this->assertSame('42', $descriptor['target']);
        $new     = clone $this->item();
        $new->id = 0;
        $form    = $this->form();
        $this->assertNull($provider->describe($form, $new, $this->user())['revision']);
        $this->expectException(AutosaveException::class);
        $provider->describe($form, $new, $this->user(['core.create:com_content.category.2']));
    }
    public function testReadLoadsNativeArticleFormOutsideContentRequest(): void
    {
        $paths         = new \ReflectionProperty(FormHelper::class, 'paths');
        $originalPaths = $paths->getValue();
        $paths->setValue(null, ['form' => [JPATH_ADMINISTRATOR . '/components/com_autosave/forms']]);

        try {
            $user  = $this->user();
            $model = $this->createMock(ArticleModel::class);
            $model->expects($this->never())->method('save');
            $model->expects($this->once())->method('getItem')->with(42)->willReturn($this->item());
            $model->expects($this->once())->method('getForm')->with($this->isType('array'), false)
                ->willReturnCallback(static function () use ($user): Form {
                    $form = new Form('com_content.article', ['control' => 'jform']);
                    $form->setCurrentUser($user);
                    $form->loadFile('article');

                    return $form;
                });
            $factory = $this->createMock(MVCFactoryInterface::class);
            $factory->method('createModel')->willReturn($model);
            $descriptor = (new ArticleAutosaveProvider($factory))->read(
                ['context' => 'com_content.article', 'target_id' => '42', 'scope' => []],
                $user
            );
            $this->assertSame(['title', 'alias', 'articletext', 'catid'], array_keys($descriptor['fields']));
            $this->assertSame('42', $descriptor['target']);
        } finally {
            $paths->setValue(null, $originalPaths);
        }
    }
}
