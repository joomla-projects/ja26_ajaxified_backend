<?php

/**
 * @package   Joomla.IntegrationTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Component\Autosave\Administrator;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Router\Router;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Input\Input;
use Joomla\Session\SessionInterface;

trait NativeControllerFixture
{
    use \Joomla\Tests\Unit\Libraries\Cms\Autosave\NativeReadFixture;
    use \Joomla\Tests\Integration\Libraries\Cms\Autosave\DraftDatabaseFixture;

    private DatabaseInterface $db;
    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousApplication = Factory::$application;
        $this->db                  = $this->getDBDriver();
        $this->initializeStore();
        // Native success uses the real clock; these bindings must not age out in future CI runs.
        $this->now = new \Joomla\CMS\Date\Date('now', 'UTC');
    }

    protected function tearDown(): void
    {
        Factory::$application = $this->previousApplication;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    private function controllerApplication(Input $input, bool $allowed = true, array &$states = []): array
    {
        $user        = $this->createMock(User::class);
        $user->id    = 7;
        $user->guest = false;
        $user->method('authorise')->willReturn($allowed);
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'user' ? $user : $default);
        $session->method('getToken')->willReturn('draft-test-token');
        $session->method('isNew')->willReturn(false);
        $app = $this->createMock(CMSApplication::class);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getInput')->willReturn($input);
        $app->method('getSession')->willReturn($session);
        $app->method('get')->willReturnCallback(static fn ($key, $default = null) => $key === 'secret' ? 'test-secret' : $default);
        $app->method('getName')->willReturn('drafttest');
        $app->method('getLanguage')->willReturn(new Language('en-GB', false));
        $app->method('getUserState')->willReturnCallback(
            static function ($key, $default = null) use (&$states) {
                return $states[$key] ?? $default;
            }
        );
        $app->method('setUserState')->willReturnCallback(
            static function ($key, $value) use (&$states) {
                $states[$key] = $value;
                return null;
            }
        );
        $app->method('getUserStateFromRequest')->willReturnCallback(static fn ($key, $request, $default = null, $type = 'none') => $input->get($request, $default, $type));
        Factory::$application = $app;
        Factory::getContainer()->set('DrafttestRouter', new Router());
        $input->server->set('HTTP_X_CSRF_TOKEN', Session::getFormToken());

        return [$app, $user];
    }
}
