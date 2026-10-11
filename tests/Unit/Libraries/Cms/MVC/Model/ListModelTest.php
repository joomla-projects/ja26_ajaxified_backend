<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  MVC
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\MVC\Model;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Tests pagination when list filters change.
 *
 * @since  __DEPLOY_VERSION__
 */
class ListModelTest extends UnitTestCase
{
    /**
     * @dataProvider provideFilterRequests
     */
    public function testFilterRequestsResetPaginationOnlyWhenTheyChange(array $remembered, ?array $submitted, int $expectedStart): void
    {
        $request = ['list' => ['limit' => 20], 'limitstart' => 40];

        if ($submitted !== null) {
            $request['filter'] = $submitted;
        }

        $input       = new Input($request);
        $session     = ['com_content.articles.filter' => $remembered];
        $application = $this->createMock(CMSApplication::class);
        $application->method('getInput')->willReturn($input);
        $application->method('getUserState')->willReturnCallback(static function ($key, $default = null) use (&$session) {
            return $session[$key] ?? $default;
        });
        $application->method('setUserState')->willReturnCallback(static function ($key, $value) use (&$session) {
            $session[$key] = $value;

            return $value;
        });
        $application->method('getUserStateFromRequest')->willReturnCallback(static function ($key, $request, $default = null, $type = 'none') use ($input) {
            return $input->get($request, $default, $type);
        });
        $oldApplication       = Factory::$application;
        Factory::$application = $application;

        try {
            $model = new class (['name' => 'Articles', 'option' => 'com_content', 'ignore_request' => true], $this->createStub(MVCFactoryInterface::class)) extends ListModel {
                protected $context = 'com_content.articles';

                public function populateState($ordering = null, $direction = null)
                {
                    parent::populateState($ordering, $direction);
                }
            };
            $model->populateState();

            $this->assertSame($expectedStart, (int) $model->getState('list.start'));
            $this->assertSame($expectedStart, $input->getInt('limitstart'));
            $this->assertSame($submitted ?? $remembered, $session['com_content.articles.filter']);

            foreach ($submitted ?? $remembered as $name => $value) {
                $this->assertSame($value, $model->getState('filter.' . $name));
            }
        } finally {
            Factory::$application = $oldApplication;
        }
    }

    public function provideFilterRequests(): array
    {
        return [
            'custom selection changed' => [['customfield_7' => ['red']], ['customfield_7' => ['blue']], 0],
            'native selection changed' => [['search' => 'old'], ['search' => 'new'], 0],
            'selection unchanged'      => [['customfield_7' => ['red']], ['customfield_7' => ['red']], 40],
            'filters cleared'          => [['customfield_7' => ['red']], [], 0],
            'no filters submitted'     => [['customfield_7' => ['red']], null, 40],
        ];
    }
}
