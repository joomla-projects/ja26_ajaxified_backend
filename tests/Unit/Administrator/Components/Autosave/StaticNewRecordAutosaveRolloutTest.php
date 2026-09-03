<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Autosave
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Autosave;

use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the component-local PR32 static new-record rollout.
 *
 * @since  __DEPLOY_VERSION__
 */
class StaticNewRecordAutosaveRolloutTest extends UnitTestCase
{
    /**
     * @dataProvider contextProvider
     */
    public function testProviderExposesBoundedCreateContract(
        string $providerClass,
        string $contractVersion,
        string $component,
        string $view,
        string $itemProperty
    ): void {
        $provider = (new \ReflectionClass($providerClass))->newInstanceWithoutConstructor();
        $user     = $this->createMock(User::class);
        $user->method('authorise')->willReturn(true);
        $user->method('getAuthorisedCategories')->willReturn([]);

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertSame($contractVersion, $provider->getCreateContractVersion());
        $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null);

        $source = file_get_contents(
            JPATH_ADMINISTRATOR . '/components/' . $component . '/src/View/' . $view . '/HtmlView.php'
        );

        $this->assertStringContainsString('AutosaveCreateProviderInterface', $source);
        $this->assertStringContainsString('AutosaveOperation::InitializeCreate', $source);
        $this->assertMatchesRegularExpression('/\$target(?:Id)?\s*=\s*null/', $source);
        $this->assertStringContainsString('$this->item->' . $itemProperty, $source);
    }

    /**
     * @dataProvider contextProvider
     */
    public function testCreateAuthorizationFailsClosed(
        string $providerClass,
        string $contractVersion,
        string $component,
        string $view,
        string $itemProperty
    ): void {
        $provider = (new \ReflectionClass($providerClass))->newInstanceWithoutConstructor();
        $user     = $this->createMock(User::class);
        $user->method('authorise')->willReturn(false);
        $user->method('getAuthorisedCategories')->willReturn([]);

        try {
            $provider->authorizeCreate($user, AutosaveOperation::InitializeCreate, null);
            $this->fail('Denied create authorization must fail closed.');
        } catch (AutosaveException $exception) {
            $this->assertSame('forbidden', $exception->getErrorCode());
        }
    }

    public static function contextProvider(): array
    {
        return [
            'redirect link' => [
                \Joomla\Component\Redirect\Administrator\Autosave\LinkAutosaveProvider::class,
                'redirect-link-create-v1', 'com_redirect', 'Link', 'id',
            ],
            'banner client' => [
                \Joomla\Component\Banners\Administrator\Autosave\ClientAutosaveProvider::class,
                'banner-client-create-v1', 'com_banners', 'Client', 'id',
            ],
            'guided tour' => [
                \Joomla\Component\Guidedtours\Administrator\Autosave\TourAutosaveProvider::class,
                'guided-tour-create-v1', 'com_guidedtours', 'Tour', 'id',
            ],
            'language' => [
                \Joomla\Component\Languages\Administrator\Autosave\LanguageAutosaveProvider::class,
                'language-create-v1', 'com_languages', 'Language', 'lang_id',
            ],
            'banner' => [
                \Joomla\Component\Banners\Administrator\Autosave\BannerAutosaveProvider::class,
                'banner-create-v1', 'com_banners', 'Banner', 'id',
            ],
            'tag' => [
                \Joomla\Component\Tags\Administrator\Autosave\TagAutosaveProvider::class,
                'tag-create-v1', 'com_tags', 'Tag', 'id',
            ],
            'newsfeed' => [
                \Joomla\Component\Newsfeeds\Administrator\Autosave\NewsfeedAutosaveProvider::class,
                'newsfeed-create-v1', 'com_newsfeeds', 'Newsfeed', 'id',
            ],
            'finder filter' => [
                \Joomla\Component\Finder\Administrator\Autosave\FilterAutosaveProvider::class,
                'finder-filter-create-v1', 'com_finder', 'Filter', 'filter_id',
            ],
        ];
    }
}
