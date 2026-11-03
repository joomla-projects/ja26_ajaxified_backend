<?php

/**
 * @package     Joomla.UnitTest
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Autosave\AutosaveContextResolver;
use Joomla\CMS\Autosave\DraftService;
use Joomla\CMS\Autosave\DraftView;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\Tests\Unit\UnitTestCase;

/** Lazy shared composition at the native administrator application boundary. */
class DraftServicesTest extends UnitTestCase
{
    public function testRegistrationIsLazyAndViewUsesTheSameServiceAndResolver(): void
    {
        $container   = new Container();
        $created     = 0;
        $application = $this->createMock(AdministratorApplication::class);
        $application->expects($this->never())->method('bootComponent');
        $container->alias(AdministratorApplication::class, 'JApplicationAdministrator');
        $container->share('JApplicationAdministrator', static function () use ($application, &$created): AdministratorApplication {
            $created++;
            return $application;
        });
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects($this->never())->method('createQuery');
        $database->expects($this->never())->method('getTableList');
        $container->set(DatabaseInterface::class, $database);
        $container->registerServiceProvider(new \Joomla\CMS\Service\Provider\Autosave());
        $this->assertSame(0, $created);
        $view = $container->get(DraftView::class);
        $this->assertSame(1, $created);
        $resolver = $container->get(AutosaveContextResolver::class);
        $service  = $container->get(DraftService::class);
        $this->assertSame($view, $container->get(DraftView::class));
        $this->assertSame($resolver, (new \ReflectionProperty(DraftView::class, 'resolver'))->getValue($view));
        $this->assertSame($resolver, (new \ReflectionProperty(DraftService::class, 'resolver'))->getValue($service));
        $this->assertSame($service, (new \ReflectionProperty(DraftView::class, 'drafts'))->getValue($view));
    }
}
