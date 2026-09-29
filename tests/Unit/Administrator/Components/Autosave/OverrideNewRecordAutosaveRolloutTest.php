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
use Joomla\CMS\Autosave\AutosaveStaticScopeProviderInterface;
use Joomla\CMS\Autosave\AutosaveTargetIdentity;
use Joomla\CMS\User\User;
use Joomla\Component\Languages\Administrator\Autosave\OverrideAutosaveProvider;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Guards the PR36 new-record rollout for com_languages.override.
 *
 * @since  __DEPLOY_VERSION__
 */
class OverrideNewRecordAutosaveRolloutTest extends UnitTestCase
{
    public function testOverrideProviderExposesTheCreateAndScopeCapabilities(): void
    {
        $provider = new OverrideAutosaveProvider($this->reader());

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertInstanceOf(AutosaveStaticScopeProviderInterface::class, $provider);
        $this->assertSame('com_languages.override', $provider->getContext());
        $this->assertSame('override-create-v1', $provider->getCreateContractVersion());
        $this->assertSame('override-scope-v1', $provider->getStaticScopeContractVersion());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    public function testOverrideCanonicalizesOnlyBoundedClientLanguageScopes(): void
    {
        $provider = new OverrideAutosaveProvider($this->reader());

        $this->assertSame('site|en-GB', $provider->canonicalizeStaticCreateScope('site|en-GB'));
        $this->assertSame('administrator|fr-FR', $provider->canonicalizeStaticCreateScope('administrator|fr-FR'));
        $this->assertSame('site|en-GB', $provider->canonicalizeStaticCreateScope('site|en-GB'));

        foreach (['', 'site', 'site|', '|en-GB', 'api|en-GB', 'site|en_GB', 'site|en gb', 'site|' . str_repeat('x', 33), 42, null, ['site|en-GB'], 'site|en-GB|extra'] as $invalid) {
            $this->assertSame(
                'invalid_scope',
                $this->failure(fn () => $provider->canonicalizeStaticCreateScope($invalid))->getErrorCode()
            );
        }
    }

    public function testOverrideCreateAuthorizationMirrorsNativeCreateAcl(): void
    {
        $provider = new OverrideAutosaveProvider($this->reader());

        $allowed = $this->createMock(User::class);
        $allowed->method('authorise')->willReturn(true);
        $provider->authorizeCreate($allowed, AutosaveOperation::InitializeCreate, null);

        $denied = $this->createMock(User::class);
        $denied->method('authorise')->willReturn(false);

        foreach (AutosaveOperation::cases() as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeCreate($denied, $operation, null))->getErrorCode()
            );
        }

        // The anchored scope is re-authorized on every operation and a malformed
        // stored scope fails before any authorization is granted.
        foreach (AutosaveOperation::cases() as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeStaticCreateScope($denied, 'site|en-GB', $operation, null))->getErrorCode()
            );
        }

        $provider->authorizeStaticCreateScope($allowed, 'site|en-GB', AutosaveOperation::Preserve, null);

        $this->assertSame(
            'invalid_scope',
            $this->failure(fn () => $provider->authorizeStaticCreateScope($allowed, 'api|en-GB', AutosaveOperation::Preserve, null))->getErrorCode()
        );
    }

    public function testOverrideFinalTargetIsVerifiedAgainstTheAnchoredScope(): void
    {
        $key      = 'COM_TEST_OVERRIDE';
        $provider = new OverrideAutosaveProvider($this->reader());

        $provider->verifyFinalTargetStaticScope(OverrideAutosaveProvider::target('site', 'en-GB', $key), 'site|en-GB');

        // The key is mutable authored state: any key inside the anchored scope passes.
        $provider->verifyFinalTargetStaticScope(OverrideAutosaveProvider::target('site', 'en-GB', 'COM_OTHER_KEY'), 'site|en-GB');

        $wrongClient = new OverrideAutosaveProvider($this->reader());
        $this->assertSame(
            'scope_mismatch',
            $this->failure(fn () => $wrongClient->verifyFinalTargetStaticScope(OverrideAutosaveProvider::target('administrator', 'en-GB', $key), 'site|en-GB'))->getErrorCode()
        );

        $wrongLanguage = new OverrideAutosaveProvider($this->reader());
        $this->assertSame(
            'scope_mismatch',
            $this->failure(fn () => $wrongLanguage->verifyFinalTargetStaticScope(OverrideAutosaveProvider::target('site', 'fr-FR', $key), 'site|en-GB'))->getErrorCode()
        );

        $malformed = new OverrideAutosaveProvider($this->reader());
        $foreign   = AutosaveTargetIdentity::composite('other.override', ['site', 'en-GB', 'COM_TEST_KEY']);
        $this->assertSame(
            'invalid_target',
            $this->failure(fn () => $malformed->verifyFinalTargetStaticScope($foreign, 'site|en-GB'))->getErrorCode()
        );
    }

    public function testOverridePayloadStaysTheExactKeyValueBothContract(): void
    {
        $provider = new OverrideAutosaveProvider($this->reader());

        $valid = ['key' => 'COM_TEST_OVERRIDE', 'override' => 'Value', 'both' => true];
        $this->assertSame($valid, $provider->normalizePayload($valid, 1));
        $this->assertSame(['key' => '', 'override' => '', 'both' => false], $provider->normalizePayload(['key' => '', 'override' => '', 'both' => false], 1));

        foreach (
            [
            ['key' => 'COM_TEST', 'override' => 'Value'],
            ['key' => 'COM_TEST', 'override' => 'Value', 'both' => true, 'client' => 'site'],
            ['key' => 'COM_TEST', 'override' => 'Value', 'both' => 1],
            ['key' => 'COM_TEST', 'override' => 'Value', 'both' => null],
            ['key' => str_repeat('x', 111), 'override' => '', 'both' => false],
            ['key' => '', 'override' => str_repeat('x', 65536), 'both' => false],
            ['key' => ['COM_TEST'], 'override' => '', 'both' => false],
            ] as $invalid
        ) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizePayload($invalid, 1))->getErrorCode()
            );
        }
    }

    public function testOverrideExistingRecordBehaviorIsUnchanged(): void
    {
        $key      = 'COM_TEST_OVERRIDE';
        $reader   = $this->reader([
            'site'          => ['en-GB' => [$key => 'Primary']],
            'administrator' => ['en-GB' => [$key => 'Mirror']],
        ]);
        $provider = new OverrideAutosaveProvider($reader);
        $target   = OverrideAutosaveProvider::target('site', 'en-GB', $key);

        $this->assertTrue($provider->targetExists($target));
        $this->assertSame($target, $provider->canonicalizeTargetId($target));

        $editor = $this->createMock(User::class);
        $editor->method('authorise')->willReturn(true);
        $provider->authorize($editor, $target, AutosaveOperation::Read);
        $this->assertStringStartsWith('autosave:com_languages.override:entry-revision:v1:', $provider->getBaseRevision($target));

        // A key present only in the primary file still resolves (existing edit),
        // and its revision reflects the missing opposite entry.
        $solo = new OverrideAutosaveProvider($this->reader([
            'site'          => ['en-GB' => [$key => 'Primary']],
            'administrator' => ['en-GB' => []],
        ]));
        $this->assertTrue($solo->targetExists($target));
        $this->assertStringStartsWith('autosave:com_languages.override:entry-revision:v1:', $solo->getBaseRevision($target));
    }

    private function reader(array $files = []): callable
    {
        return static function (string $client, string $language) use ($files): array {
            return $files[$client][$language] ?? [];
        };
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
