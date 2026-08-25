<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  com_banners
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Administrator\Components\Banners\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\User\User;
use Joomla\Component\Banners\Administrator\Autosave\BannerAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class BannerAutosaveProviderTest extends UnitTestCase
{
    public function testOwnsExactContractAndCanonicalExistingIdentity(): void
    {
        $provider = new BannerAutosaveProvider($this->databaseReturning($this->banner()));

        $this->assertSame('com_banners.banner', $provider->getContext());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
        $this->assertSame('2147483647', $provider->canonicalizeTargetId('2147483647'));
        $this->assertTrue($provider->targetExists('42'));

        foreach (['', '0', '01', '-1', ' 1', '1 ', '1.0', '2147483648'] as $invalid) {
            $this->assertSame('invalid_target', $this->failure(fn () => $provider->canonicalizeTargetId($invalid))->getErrorCode());
        }

        $this->assertFalse((new BannerAutosaveProvider($this->databaseReturning()))->targetExists('42'));
        $this->assertFalse((new BannerAutosaveProvider($this->databaseReturning($this->banner(['category_extension' => 'com_content']))))->targetExists('42'));
    }

    public function testNormalizesExactPayloadAndPreservesIncompleteDates(): void
    {
        $provider = new BannerAutosaveProvider($this->databaseReturning());
        $payload  = $this->payload();

        $this->assertSame($payload, $provider->normalizePayload($payload, 1));
        $dates = $provider->normalizePayload([
            ...$payload,
            'publish_up'       => '',
            'publish_up_alt'   => '',
            'publish_down'     => 'Tomorrow',
            'publish_down_alt' => '',
        ], 1);
        $this->assertSame('', $dates['publish_up']);
        $this->assertSame('', $dates['publish_up_alt']);
        $this->assertSame('Tomorrow', $dates['publish_down']);
        $this->assertSame('', $dates['publish_down_alt']);
    }

    public function testRejectsShapeTypesUtf8LimitsEnumsAndDimensions(): void
    {
        $payload  = $this->payload();
        $provider = new BannerAutosaveProvider($this->databaseReturning());
        $boundary = $provider->normalizePayload([
            ...$payload,
            'name'       => str_repeat('é', 255),
            'publish_up' => str_repeat('x', 255),
            'imageurl'   => str_repeat('a', 2048),
        ], 1);
        $this->assertSame(str_repeat('é', 255), $boundary['name']);

        $invalid = [
            array_diff_key($payload, ['alias' => true]),
            [...$payload, 'state' => 1],
            [...$payload, 'id' => 42],
            [...$payload, 'name' => ['Banner']],
            [...$payload, 'name' => "\xB1\x31"],
            [...$payload, 'name' => str_repeat('x', 256)],
            [...$payload, 'publish_down' => str_repeat('x', 256)],
            [...$payload, 'imageurl' => str_repeat('a', 2049)],
            [...$payload, 'type' => 2],
            [...$payload, 'own_prefix' => '1'],
            [...$payload, 'width' => '-1'],
            [...$payload, 'height' => '2147483648'],
        ];

        foreach ($invalid as $candidate) {
            $this->assertSame('invalid_payload', $this->failure(fn () => $provider->normalizePayload($candidate, 1))->getErrorCode());
        }
    }

    public function testAcceptsOnlyStableMediaReferences(): void
    {
        $provider = new BannerAutosaveProvider($this->databaseReturning());

        foreach (['', 'images/banner.jpg', 'images/banner.jpg#joomlaImage://local-images/banner.jpg', 'https://cdn.example.test/banner.jpg'] as $reference) {
            $this->assertSame($reference, $provider->normalizePayload([...$this->payload(), 'imageurl' => $reference], 1)['imageurl']);
        }

        foreach (['/images/banner.jpg', '//example.test/banner.jpg', 'blob:abc', 'data:image/png;base64,AA', 'file:///tmp/a', 'https://user@example.test/a', 'images\\a.jpg'] as $reference) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload([...$this->payload(), 'imageurl' => $reference], 1))->getErrorCode()
            );
        }
    }

    public function testAuthorizationUsesCanonicalCategoryAndCheckout(): void
    {
        $user     = $this->createMock(User::class);
        $user->id = 7;
        $user->method('authorise')->with('core.edit', 'com_banners.category.3')->willReturn(true);
        (new BannerAutosaveProvider($this->databaseReturning($this->banner(['checked_out' => 7]))))
            ->authorize($user, '42', AutosaveOperation::Preserve);

        $denied     = $this->createMock(User::class);
        $denied->id = 7;
        $denied->method('authorise')->willReturn(false);
        $this->assertSame('forbidden', $this->failure(fn () => (new BannerAutosaveProvider($this->databaseReturning($this->banner())))->authorize($denied, '42', AutosaveOperation::Read))->getErrorCode());
        $this->assertSame('checked_out', $this->failure(fn () => (new BannerAutosaveProvider($this->databaseReturning($this->banner(['checked_out' => 99]))))->authorize($user, '42', AutosaveOperation::Read))->getErrorCode());
    }

    public function testBaseRevisionIgnoresCheckoutButIncludesCanonicalContentAndCategory(): void
    {
        $base     = new BannerAutosaveProvider($this->databaseReturning($this->banner()));
        $checkout = new BannerAutosaveProvider($this->databaseReturning($this->banner(['checked_out' => 99])));
        $content  = new BannerAutosaveProvider($this->databaseReturning($this->banner(['name' => 'Changed'])));
        $category = new BannerAutosaveProvider($this->databaseReturning($this->banner(['catid' => 4, 'category_id' => 4])));

        $this->assertSame($base->getBaseRevision('42'), $checkout->getBaseRevision('42'));
        $this->assertNotSame($base->getBaseRevision('42'), $content->getBaseRevision('42'));
        $this->assertNotSame($base->getBaseRevision('42'), $category->getBaseRevision('42'));
    }

    private function payload(): array
    {
        return [
            'name'             => '', 'alias' => '', 'description' => '', 'custombannercode' => '', 'clickurl' => '',
            'version_note'     => '', 'publish_up' => '', 'publish_up_alt' => '', 'publish_down' => '',
            'publish_down_alt' => '', 'imageurl' => '', 'width' => '', 'height' => '', 'alt' => '',
            'metakey'          => '', 'metakey_prefix' => '', 'type' => 0, 'own_prefix' => 0,
        ];
    }

    private function banner(array $replace = []): object
    {
        return (object) array_replace([
            'id'                => 42, 'cid' => 2, 'type' => 0, 'name' => 'Banner', 'alias' => 'banner', 'imptotal' => 0,
            'impmade'           => 0, 'clicks' => 0, 'clickurl' => '', 'state' => 1, 'catid' => 3, 'description' => '',
            'custombannercode'  => '', 'sticky' => 0, 'ordering' => 1, 'metakey' => '', 'params' => '{}',
            'own_prefix'        => 0, 'metakey_prefix' => '', 'purchase_type' => -1, 'track_clicks' => -1,
            'track_impressions' => -1, 'publish_up' => null, 'publish_down' => null, 'reset' => null,
            'created'           => '2026-01-01 00:00:00', 'language' => '*', 'created_by' => 1, 'created_by_alias' => '',
            'modified'          => null, 'modified_by' => 0, 'version' => 1, 'checked_out' => 0,
            'category_id'       => 3, 'category_extension' => 'com_banners',
        ], $replace);
    }

    private function databaseReturning(?object $banner = null): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($banner);

        return $db;
    }

    private function failure(callable $callback): AutosaveException
    {
        try {
            $callback();
        } catch (AutosaveException $exception) {
            return $exception;
        }

        $this->fail('Expected AutosaveException was not thrown.');
    }
}
