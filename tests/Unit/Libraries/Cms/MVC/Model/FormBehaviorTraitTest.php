<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\MVC\Model;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactory;
use Joomla\CMS\Form\FormFactoryAwareTrait;
use Joomla\CMS\MVC\Model\FormBehaviorTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

/** Ordinary form preparation with explicit data, without draft services or a database. */
class FormBehaviorTraitTest extends UnitTestCase
{
    private function model(): object
    {
        if (!\defined('JPATH_COMPONENT')) {
            \define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_fields');
        }

        $model = new class () {
            use FormBehaviorTrait {
                loadForm as public;
            }
            use FormFactoryAwareTrait;

            public int $loads      = 0;
            public array $prepared = [];
            public bool $fail      = false;

            protected function loadFormData()
            {
                $this->loads++;

                return ['title' => 'Failed validation', 'limit' => 9];
            }

            protected function preprocessForm(Form $form, $data, $group = 'content')
            {
                if ($this->fail) {
                    throw new \RuntimeException('Preparation failed');
                }

                $this->prepared[] = $data;
                $form->load('<form><field name="limit" type="number"/></form>');
            }
        };
        $factory = new FormFactory();
        $factory->setDatabase($this->createMock(DatabaseInterface::class));
        $model->setFormFactory($factory);

        return $model;
    }

    /** @dataProvider loading */
    public function testPreparationAndBindingUseTheSameData(array $options, array $expected, int $loads): void
    {
        $model = $this->model();
        $form  = $model->loadForm('ordinary.form', '<form><field name="title" type="text"/></form>', $options);

        $this->assertSame([$expected], $model->prepared);
        $this->assertSame($loads, $model->loads);
        $this->assertSame($expected['title'] ?? null, $form->getValue('title'));
        $this->assertSame($expected['limit'] ?? null, $form->getValue('limit'));
    }

    public static function loading(): array
    {
        $explicit = ['title' => 'Saved item', 'limit' => 20];

        return [
            'explicit without session' => [['load_data' => false, 'data' => $explicit], $explicit, 0],
            'ordinary error data'      => [
                ['load_data' => true, 'data' => $explicit],
                ['title' => 'Failed validation', 'limit' => 9],
                1,
            ],
            'no options'       => [[], [], 0],
            'no explicit data' => [['load_data' => false], [], 0],
        ];
    }

    public function testDifferentExplicitDataDoesNotReuseAnotherItemsPreparedForm(): void
    {
        $model  = $this->model();
        $source = '<form><field name="title" type="text"/></form>';
        $first  = $model->loadForm('ordinary.form', $source, ['data' => ['title' => 'First']]);
        $second = $model->loadForm('ordinary.form', $source, ['data' => ['title' => 'Second']]);

        $this->assertNotSame($first, $second);
        $this->assertSame('First', $first->getValue('title'));
        $this->assertSame('Second', $second->getValue('title'));
        $this->assertSame($first, $model->loadForm('ordinary.form', $source, ['data' => ['title' => 'First']]));
    }

    public function testFailedPreparationIsNotCachedAsASuccessfulForm(): void
    {
        $model       = $this->model();
        $model->fail = true;
        $source      = '<form><field name="title" type="text"/></form>';

        try {
            $model->loadForm('ordinary.form', $source, ['data' => ['title' => 'Retry']]);
            $this->fail('Preparation should fail');
        } catch (\RuntimeException $error) {
            $this->assertSame('Preparation failed', $error->getMessage());
        }

        $model->fail = false;
        $form        = $model->loadForm('ordinary.form', $source, ['data' => ['title' => 'Retry']]);
        $this->assertSame('Retry', $form->getValue('title'));
        $this->assertCount(1, $model->prepared);
    }
}
