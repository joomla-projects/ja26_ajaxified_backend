<?php

/**
 * @package   Joomla.UnitTest
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\HTMLDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Toolbar\ContainerAwareToolbarFactory;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\Component\Content\Administrator\View\Article\HtmlView;
use Joomla\DI\Container;
use Joomla\Input\Input;

trait DraftPresentationFixture
{
    use NativeReadFixture;

    private mixed $previousApplication;

    private mixed $previousLanguage;

    private mixed $previousDocument;

    private array $previousServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installNativeReadFixture();
        $this->previousServer      = $_SERVER;
        $_SERVER['HTTP_HOST']      = 'localhost';
        $_SERVER['REQUEST_URI']    = '/administrator/index.php';
        $_SERVER['SCRIPT_NAME']    = '/administrator/index.php';
        $this->previousApplication = Factory::$application;
        $this->previousLanguage    = Factory::$language;
        $this->previousDocument    = Factory::$document;
        Factory::$document         = null;
        $language                  = new Language('en-GB', false);
        $language->load('plg_system_autosave', JPATH_PLUGINS . '/system/autosave');
        Factory::$language = $language;
        $app               = $this->createMock(CMSApplication::class);
        $app->method('getName')->willReturn('draftpresentationtest');
        $app->method('isClient')->willReturnCallback(static fn (string $client): bool => $client === 'administrator');
        $app->method('getClientId')->willReturn(1);
        $app->method('getLanguage')->willReturn($language);
        $app->method('getInput')->willReturn(new Input());
        Factory::$application = $app;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->previousServer;
        \Joomla\CMS\Uri\Uri::reset();
        Factory::$application = $this->previousApplication;
        Factory::$language    = $this->previousLanguage;
        Factory::$document    = $this->previousDocument;
        $this->restoreNativeReadFixture();
        parent::tearDown();
    }

    private function output(string $part): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(LayoutHelper::render('joomla.autosave.draft', ['part' => $part], JPATH_ROOT . '/layouts'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new \DOMXPath($document);
    }

    private static function registerSourceAssetManifests(WebAssetRegistry $registry, array $names): void
    {
        foreach ($names as $name) {
            $registry->addRegistryFile('media_source/' . $name . '/joomla.asset.json');
        }
    }

    private static function primeAutosaveAssetRegistry(WebAssetRegistry $registry): void
    {
        $registry->addRegistryFile('media/com_autosave/joomla.asset.json');
        self::registerSourceAssetManifests($registry, ['com_autosave']);
        $registry->get('script', 'com_autosave.draft-form');
    }

    private function document(): HTMLDocument
    {
        $registry = new WebAssetRegistry();
        $registry->addRegistryFile('media/com_autosave/joomla.asset.json');

        self::registerSourceAssetManifests($registry, ['system', 'com_autosave']);

        return new HTMLDocument(['webAssetManager' => new WebAssetManager($registry)]);
    }

    private function draftView(): \Joomla\CMS\Autosave\DraftView
    {
        $resolver = new \Joomla\CMS\Autosave\AutosaveContextResolver(Factory::getApplication());
        return new \Joomla\CMS\Autosave\DraftView(
            new \Joomla\CMS\Autosave\DraftService($resolver, new \Joomla\CMS\Autosave\DraftStore($this->createMock(\Joomla\Database\DatabaseInterface::class))),
            $resolver
        );
    }

    private function toolbarView(): array
    {
        $factory = new ContainerAwareToolbarFactory();
        $factory->setContainer(new Container());
        $toolbar  = new Toolbar('toolbar', $factory);
        $document = $this->document();
        $document->setToolbar('toolbar', $toolbar);
        $view = (new \ReflectionClass(HtmlView::class))->newInstanceWithoutConstructor();
        $view->setDocument($document);
        $view->autosaveEnabled  = true;
        $view->autosaveStatusId = 'article-autosave-status';

        return [$view, $toolbar];
    }
}
