<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\GenerationState;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for \Joomla\CMS\Autosave\GenerationState.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @testdox     The Autosave generation state
 *
 * @since       __DEPLOY_VERSION__
 */
class GenerationStateTest extends UnitTestCase
{
    /**
     * @testdox  validates the complete lifecycle transition graph
     *
     * @param   GenerationState  $source    The source state.
     * @param   GenerationState  $target    The target state.
     * @param   boolean          $expected  Whether the transition is allowed.
     *
     * @return  void
     *
     * @dataProvider transitionProvider
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCanTransitionTo(
        GenerationState $source,
        GenerationState $target,
        bool $expected
    ): void {
        $this->assertSame($expected, $source->canTransitionTo($target));
    }

    /**
     * All source and target state pairs.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function transitionProvider(): array
    {
        return [
            'active to active'       => [GenerationState::Active, GenerationState::Active, false],
            'active to retired'      => [GenerationState::Active, GenerationState::Retired, true],
            'active to discarded'    => [GenerationState::Active, GenerationState::Discarded, true],
            'active to expired'      => [GenerationState::Active, GenerationState::Expired, true],
            'retired to active'      => [GenerationState::Retired, GenerationState::Active, false],
            'retired to retired'     => [GenerationState::Retired, GenerationState::Retired, false],
            'retired to discarded'   => [GenerationState::Retired, GenerationState::Discarded, false],
            'retired to expired'     => [GenerationState::Retired, GenerationState::Expired, false],
            'discarded to active'    => [GenerationState::Discarded, GenerationState::Active, false],
            'discarded to retired'   => [GenerationState::Discarded, GenerationState::Retired, false],
            'discarded to discarded' => [GenerationState::Discarded, GenerationState::Discarded, false],
            'discarded to expired'   => [GenerationState::Discarded, GenerationState::Expired, false],
            'expired to active'      => [GenerationState::Expired, GenerationState::Active, false],
            'expired to retired'     => [GenerationState::Expired, GenerationState::Retired, false],
            'expired to discarded'   => [GenerationState::Expired, GenerationState::Discarded, false],
            'expired to expired'     => [GenerationState::Expired, GenerationState::Expired, false],
        ];
    }
}
