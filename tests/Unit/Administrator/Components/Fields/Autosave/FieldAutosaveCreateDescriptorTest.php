<?php

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Autosave;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveCreateProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicCreateDescriptorProviderInterface;
use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Autosave\AutosaveOperation;
use Joomla\CMS\Autosave\AutosaveStaticScopeProviderInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Autosave\FieldAutosaveProvider;
use Joomla\Component\Fields\Administrator\Autosave\FieldAutosaveSchemaFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class FieldAutosaveCreateDescriptorTest extends UnitTestCase
{
    private const CONTEXT = 'com_content.article';
    private const TYPE    = 'text';
    private const PREFIX  = 'fd1';

    private function textSchema(): AutosaveDynamicSchema
    {
        return new AutosaveDynamicSchema([['path' => ['fieldparams', 'maxlength'], 'id' => 'maxlength', 'kind' => 'string', 'maxLength' => 4]]);
    }

    private function listSchema(): AutosaveDynamicSchema
    {
        return new AutosaveDynamicSchema([
            ['path' => ['fieldparams', 'multiple'], 'id' => 'multiple', 'kind' => 'enum', 'values' => ['', '1', '0']],
            ['path' => ['fieldparams', 'options'], 'id' => 'options', 'kind' => 'rows', 'columns' => ['name' => 255, 'value' => 255]],
        ]);
    }

    public function testProviderExposesTheDynamicCreateDescriptorCapability(): void
    {
        $provider = new FieldAutosaveProvider($this->database());

        $this->assertInstanceOf(AutosaveCreateProviderInterface::class, $provider);
        $this->assertInstanceOf(AutosaveStaticScopeProviderInterface::class, $provider);
        $this->assertInstanceOf(AutosaveDynamicCreateDescriptorProviderInterface::class, $provider);
        $this->assertSame('com_fields.field', $provider->getContext());
        $this->assertSame('field-create-v1', $provider->getCreateContractVersion());
        $this->assertSame('field-descriptor-v1', $provider->getStaticScopeContractVersion());
        $this->assertSame(1, $provider->getPayloadSchemaVersion());
    }

    public function testCanonicalizeAcceptsRawPairAndCanonicalTokenIdempotently(): void
    {
        $provider = $this->providerWithResolvers(['text'], [self::CONTEXT]);
        $token    = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|' . self::TYPE);

        $this->assertMatchesRegularExpression('/^fd1:com_content\.article:text:[a-f0-9]{64}$/D', $token);
        // Re-canonicalizing the canonical token is idempotent.
        $this->assertSame($token, $provider->canonicalizeStaticCreateScope($token));
    }

    public function testCanonicalizeRejectsInvalidDescriptors(): void
    {
        $provider = $this->providerWithResolvers(['text'], [self::CONTEXT]);
        $token    = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|text');
        $validFp  = explode(':', $token)[3];

        foreach (
            [
            '',
            'text',
            'com_content.article',
            self::CONTEXT . '|unknown',
            self::CONTEXT . '|text|extra',
            'com_fields.article|text',
            'content.article|text',
            'com_content.|text',
            'com_content.article|TeXt',
            'fd1:com_content.article:text:' . str_repeat('0', 64),
            'fd2:' . self::CONTEXT . ':text:' . $validFp,
            'fd1:' . self::CONTEXT . ':unknown:' . $validFp,
            'fd1:com_banners.banner:text:' . $validFp,
            ['com_content.article', 'text'],
            42,
            null,
            ] as $invalid
        ) {
            $this->assertSame(
                'invalid_scope',
                $this->failure(fn () => $provider->canonicalizeStaticCreateScope($invalid))->getErrorCode(),
                'candidate: ' . var_export($invalid, true)
            );
        }

        // A stale or fabricated fingerprint never anchors.
        $stale = 'fd1:' . self::CONTEXT . ':text:' . $validFp;
        $this->assertSame('invalid_scope', $this->failure(fn () => $provider->canonicalizeStaticCreateScope(substr_replace($stale, 'a', -1)))->getErrorCode());
    }

    public function testCanonicalizeRejectsUnknownOrDisabledTypeAndUnsupportedContext(): void
    {
        // Unknown type: not offered by any enabled field plugin.
        $provider = $this->providerWithResolvers(['text'], [self::CONTEXT]);
        $this->assertSame(
            'invalid_scope',
            $this->failure(fn () => $provider->canonicalizeStaticCreateScope('com_content.article|sql'))->getErrorCode()
        );

        // Disabled plugin: type disappears mid-session exactly like a revoked type.
        $typeResolver = new \ArrayObject(['text' => true, 'list' => true]);
        $schemas      = ['text' => $this->textSchema(), 'list' => $this->listSchema()];
        $provider     = new FieldAutosaveProvider(
            $this->database(),
            static fn (string $type): AutosaveDynamicSchema => $schemas[$type] ?? new AutosaveDynamicSchema([]),
            static fn (string $type): bool => isset($typeResolver[$type]),
            static fn (string $context): bool => $context === self::CONTEXT
        );
        $provider->canonicalizeStaticCreateScope('com_content.article|list');
        unset($typeResolver['list']);
        $this->assertSame(
            'invalid_scope',
            $this->failure(fn () => $provider->canonicalizeStaticCreateScope('com_content.article|list'))->getErrorCode()
        );

        // Unsupported context: not a registered Custom Field context.
        $this->assertSame(
            'invalid_scope',
            $this->failure(fn () => $provider->canonicalizeStaticCreateScope('com_content.bogus|text'))->getErrorCode()
        );
        $this->assertSame(
            'invalid_scope',
            $this->failure(fn () => $provider->canonicalizeStaticCreateScope('com_unknown.record|text'))->getErrorCode()
        );
    }

    public function testAuthorizeStaticCreateScopeMirrorsNativeAllowAdd(): void
    {
        $provider = $this->providerWithResolvers(['text'], [self::CONTEXT]);
        $token    = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|text');
        $allowed  = $this->user(true);
        $denied   = $this->user(false);

        $provider->authorizeStaticCreateScope($allowed, $token, AutosaveOperation::Preserve, null);

        foreach (AutosaveOperation::cases() as $operation) {
            $this->assertSame(
                'forbidden',
                $this->failure(fn () => $provider->authorizeStaticCreateScope($denied, $token, $operation, null))->getErrorCode()
            );
        }
    }

    public function testAuthorizeStaticCreateScopeFailsClosedOnPluginOrContextRevocation(): void
    {
        $typeResolver = new \ArrayObject(['text' => true]);
        $contextSet   = new \ArrayObject([self::CONTEXT => true]);
        $schemas      = ['text' => $this->textSchema()];
        $provider     = new FieldAutosaveProvider(
            $this->database(),
            static fn (string $type): AutosaveDynamicSchema => $schemas[$type] ?? new AutosaveDynamicSchema([]),
            static fn (string $type): bool => isset($typeResolver[$type]),
            static fn (string $context): bool => isset($contextSet[$context])
        );
        $allowed = $this->user(true);
        $token   = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|text');

        // Permission revocation fails closed on every operation.
        $denied = $this->user(false);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $provider->authorizeStaticCreateScope($denied, $token, AutosaveOperation::Preserve, null))->getErrorCode()
        );

        // Plugin disablement mid-lineage fails closed.
        unset($typeResolver['text']);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $provider->authorizeStaticCreateScope($allowed, $token, AutosaveOperation::Preserve, null))->getErrorCode()
        );

        // Context revocation fails closed.
        $typeResolver['text'] = true;
        unset($contextSet[self::CONTEXT]);
        $this->assertSame(
            'forbidden',
            $this->failure(fn () => $provider->authorizeStaticCreateScope($allowed, $token, AutosaveOperation::Preserve, null))->getErrorCode()
        );
    }

    public function testAuthorizeCreateRequiresAnAnchoredDescriptor(): void
    {
        $provider = new FieldAutosaveProvider($this->database());

        $this->assertSame(
            'scope_required',
            $this->failure(fn () => $provider->authorizeCreate($this->user(true), AutosaveOperation::InitializeCreate, null))->getErrorCode()
        );
    }

    public function testVerifyFinalTargetMatchesTheAnchoredDescriptor(): void
    {
        $matching = new FieldAutosaveProvider($this->database(['context' => self::CONTEXT, 'type' => 'text']));
        $matching->verifyFinalTargetStaticScope('42', $this->tokenFor('text'));

        $wrongContext = new FieldAutosaveProvider($this->database(['context' => 'com_contact.contact', 'type' => 'text']));
        $this->assertSame(
            'scope_mismatch',
            $this->failure(fn () => $wrongContext->verifyFinalTargetStaticScope('42', $this->tokenFor('text')))->getErrorCode()
        );

        $wrongType = new FieldAutosaveProvider($this->database(['context' => self::CONTEXT, 'type' => 'list']));
        $this->assertSame(
            'scope_mismatch',
            $this->failure(fn () => $wrongType->verifyFinalTargetStaticScope('42', $this->tokenFor('text')))->getErrorCode()
        );

        $missing = new FieldAutosaveProvider($this->database(['row' => null]));
        $this->assertSame(
            'scope_mismatch',
            $this->failure(fn () => $missing->verifyFinalTargetStaticScope('42', $this->tokenFor('text')))->getErrorCode()
        );
    }

    public function testCreatePayloadCannotSmuggleTypeOrContextIntoTheDraft(): void
    {
        $provider = $this->providerWithResolvers(['text'], [self::CONTEXT]);
        $token    = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|text');
        $payload  = $this->payload($this->tokenSchemaFingerprint($token), ['maxlength' => '100']);

        // Descriptor state is immutable scope: a browser that smuggles type/context
        // values into the mutable payload is rejected by the exact key contract.
        foreach (
            [
            [...$payload, 'type' => 'list'],
            [...$payload, 'context' => 'com_contact.contact'],
            [...$payload, 'group_id' => 3],
            [...$payload, 'state' => 1],
            array_diff_key($payload, ['fieldparams' => true]),
            ] as $smuggled
        ) {
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizeCreatePayload($token, $smuggled, 1))->getErrorCode()
            );
        }
    }

    public function testRealPluginSchemasIsolateFieldparamsPerDescriptor(): void
    {
        // Real server schema generation for two materially different types.
        $previous    = Factory::$application;
        $application = $this->createMock(CMSApplication::class);
        $application->method('getIdentity')->willReturn($this->createMock(User::class));
        $application->method('getConfig')->willReturn(new \Joomla\Registry\Registry());
        Factory::$application = $application;

        try {
            $textSchema = (new FieldAutosaveSchemaFactory())->forType('text');
            $listSchema = (new FieldAutosaveSchemaFactory())->forType('list');

            $this->assertSame(['filter', 'maxlength'], array_map(static fn (array $field): string => $field['path'][1], $textSchema->fields()));
            $this->assertNotSame($textSchema->fingerprint(), $listSchema->fingerprint());

            $provider = new FieldAutosaveProvider(
                $this->database(),
                static fn (string $type): AutosaveDynamicSchema => (new FieldAutosaveSchemaFactory())->forType($type),
                static fn (string $type): bool => \in_array($type, ['text', 'list'], true),
                static fn (string $context): bool => $context === self::CONTEXT
            );
            $textToken = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|text');
            $listToken = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|list');

            $textPayload = $this->payload($this->tokenSchemaFingerprint($textToken), ['filter' => 'raw', 'maxlength' => '100']);
            $listPayload = $this->payload($this->tokenSchemaFingerprint($listToken), ['header' => 'Choose one', 'multiple' => '1', 'options' => [['name' => 'Option A', 'value' => 'a']]]);

            // Type A payload accepted under descriptor A, type B payload under B.
            $this->assertSame($textPayload, $provider->normalizeCreatePayload($textToken, $textPayload, 1));
            $this->assertSame($listPayload, $provider->normalizeCreatePayload($listToken, $listPayload, 1));

            // Text-only params can never ride a List descriptor.
            $listWithTextOnly = $this->payload($this->tokenSchemaFingerprint($listToken), ['filter' => 'raw', 'maxlength' => '100']);
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizeCreatePayload($listToken, $listWithTextOnly, 1))->getErrorCode()
            );

            // List-only params can never ride a Text descriptor.
            $textWithListOnly = $this->payload($this->tokenSchemaFingerprint($textToken), ['multiple' => '1', 'options' => [['name' => 'A', 'value' => 'a']]]);
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizeCreatePayload($textToken, $textWithListOnly, 1))->getErrorCode()
            );

            // Malformed dynamic values for the descriptor schema are rejected.
            $badListValue = $this->payload($this->tokenSchemaFingerprint($listToken), ['multiple' => '9', 'options' => []]);
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizeCreatePayload($listToken, $badListValue, 1))->getErrorCode()
            );

            $badTextLength = $this->payload($this->tokenSchemaFingerprint($textToken), ['filter' => 'raw', 'maxlength' => str_repeat('1', 4097)]);
            $this->assertSame(
                'invalid_payload',
                $this->failure(fn () => $provider->normalizeCreatePayload($textToken, $badTextLength, 1))->getErrorCode()
            );
        } finally {
            Factory::$application = $previous;
        }
    }

    public function testDescriptorSchemaDriftFailsClosed(): void
    {
        $schemaA  = new AutosaveDynamicSchema([['path' => ['fieldparams', 'maxlength'], 'id' => 'maxlength', 'kind' => 'string', 'maxLength' => 4]]);
        $schemaB  = new AutosaveDynamicSchema([['path' => ['fieldparams', 'filter'], 'id' => 'filter', 'kind' => 'string', 'maxLength' => 64]]);
        $current  = $schemaA;
        $provider = new FieldAutosaveProvider(
            $this->database(),
            static function (string $type) use (&$current): AutosaveDynamicSchema {
                return $current;
            },
            static fn (string $type): bool => $type === 'text',
            static fn (string $context): bool => $context === self::CONTEXT
        );

        // Anchor under schema A, then evolve the plugin schema to B.
        $token   = $provider->canonicalizeStaticCreateScope(self::CONTEXT . '|text');
        $payload = $this->payload($schemaA->fingerprint(), ['maxlength' => '100']);
        $this->assertSame($payload, $provider->normalizeCreatePayload($token, $payload, 1));

        $current = $schemaB;
        $this->assertSame(
            'descriptor_stale',
            $this->failure(fn () => $provider->normalizeCreatePayload($token, $payload, 1))->getErrorCode()
        );

        // A draft built against a different fingerprint than the server schema is
        // stale even when the anchored descriptor itself is unchanged.
        $current = $schemaA;
        $this->assertSame(
            'invalid_payload',
            $this->failure(fn () => $provider->normalizeCreatePayload($token, $this->payload(str_repeat('0', 64), ['maxlength' => '100']), 1))->getErrorCode()
        );
    }

    private function providerWithResolvers(array $types, array $contexts): FieldAutosaveProvider
    {
        $schemas = ['text' => $this->textSchema(), 'list' => $this->listSchema()];

        return new FieldAutosaveProvider(
            $this->database(),
            static fn (string $type): AutosaveDynamicSchema => $schemas[$type] ?? new AutosaveDynamicSchema([]),
            static fn (string $type): bool => \in_array($type, $types, true),
            static fn (string $context): bool => \in_array($context, $contexts, true)
        );
    }

    private function tokenFor(string $type): string
    {
        return self::PREFIX . ':' . self::CONTEXT . ':' . $type . ':' . str_repeat('a', 64);
    }

    private function tokenSchemaFingerprint(string $token): string
    {
        return explode(':', $token, 4)[3];
    }

    private function payload(string $fingerprint, array $fieldparams): array
    {
        return [
            'title'               => 'Field',
            'name'                => 'field',
            'label'               => 'Field label',
            'description'         => '',
            'default_value'       => '',
            'note'                => '',
            'required'            => false,
            'only_use_in_subform' => false,
            'schemaFingerprint'   => $fingerprint,
            'fieldparams'         => $fieldparams,
        ];
    }

    private function user(bool $allowed): User
    {
        $user = $this->createMock(User::class);
        $user->method('authorise')->willReturn($allowed);

        return $user;
    }

    private function database(array $options = []): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(
            \array_key_exists('row', $options) ? $options['row'] : (object) [
                'id'              => 42,
                'context'         => $options['context'] ?? self::CONTEXT,
                'type'            => $options['type'] ?? 'text',
                'created_user_id' => 7,
                'checked_out'     => 0,
            ]
        );

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
