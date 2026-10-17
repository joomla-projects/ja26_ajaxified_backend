<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Component\Scheduler\Administrator\Traits;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

/** The shared task trait uses the plugin's injected application and existing form data. */
class TaskPluginTraitTest extends UnitTestCase
{
    /** @dataProvider routineData */
    public function testRoutineIdentityPrefersPreparedDataBeforeTheInjectedApplication(
        mixed $data,
        ?string $bound,
        string $expected
    ): void {
        $previous = Factory::$application;
        $global   = $this->createMock(CMSApplication::class);
        $global->expects($this->never())->method('getInput');
        Factory::$application = $global;

        try {
            $application = $this->createMock(CMSApplication::class);
            $application->expects($expected === 'injected' ? $this->once() : $this->never())->method('getInput')
                ->willReturn(new Input(['jform' => ['type' => 'injected']]));
            $plugin = new class ([]) extends CMSPlugin {
                use TaskPluginTrait;

                public function routine(Form $form, mixed $data): string
                {
                    return $this->getRoutineId($form, $data);
                }
            };
            $plugin->setApplication($application);
            $form = new Form('com_scheduler.task');
            $form->load('<form><field name="type" type="text"/></form>');
            if ($bound !== null) {
                $form->setValue('type', null, $bound);
            }

            $this->assertSame($expected, $plugin->routine($form, $data));
        } finally {
            Factory::$application = $previous;
        }
    }

    public static function routineData(): array
    {
        return [
            'object routine'       => [(object) ['taskOption' => (object) ['id' => 'object']], 'bound', 'object'],
            'object type'          => [(object) ['type' => 'object-type'], 'bound', 'object-type'],
            'array type'           => [['type' => 'array'], 'bound', 'array'],
            'bound form'           => [[], 'bound', 'bound'],
            'injected application' => [[], null, 'injected'],
        ];
    }
}
