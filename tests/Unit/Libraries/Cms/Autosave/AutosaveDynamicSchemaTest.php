<?php

namespace Joomla\Tests\Unit\Libraries\Cms\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\Tests\Unit\UnitTestCase;

class AutosaveDynamicSchemaTest extends UnitTestCase
{
    public function testSchemaIsDeterministicAndValidatesExactPayload(): void
    {
        $first = new AutosaveDynamicSchema([
            ['path' => ['fieldparams', 'mode'], 'id' => 'mode', 'kind' => 'enum', 'values' => ['', '0', '1']],
            ['path' => ['fieldparams', 'width'], 'id' => 'width', 'kind' => 'string', 'maxLength' => 32],
        ]);
        $second = new AutosaveDynamicSchema(array_reverse($first->fields()));
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertSame(['fieldparams' => ['mode' => '1', 'width' => '100%']], $first->normalizePayload(['fieldparams' => ['width' => '100%', 'mode' => '1']]));
    }

    /** @dataProvider invalidSchemaProvider */
    public function testSchemaRejectsUnsafeOrUnboundedDefinitions(array $fields): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AutosaveDynamicSchema($fields);
    }

    public static function invalidSchemaProvider(): iterable
    {
        yield 'prototype' => [[['path' => ['fieldparams', '__proto__'], 'id' => 'x', 'kind' => 'string', 'maxLength' => 1]]];
        yield 'duplicate' => [[['path' => ['fieldparams', 'x'], 'id' => 'x', 'kind' => 'boolean'], ['path' => ['fieldparams', 'x'], 'id' => 'y', 'kind' => 'boolean']]];
        yield 'unbounded' => [[['path' => ['fieldparams', 'x'], 'id' => 'x', 'kind' => 'string', 'maxLength' => 4097]]];
        yield 'unsupported' => [[['path' => ['fieldparams', 'x'], 'id' => 'x', 'kind' => 'object']]];
        yield 'too many' => [[...array_map(static fn ($i) => ['path' => ['fieldparams', 'x' . $i], 'id' => 'x' . $i, 'kind' => 'boolean'], range(1, 33))]];
        yield 'zero collection bound' => [[['path' => ['fieldparams', 'x'], 'id' => 'x', 'kind' => 'strings', 'values' => ['a'], 'maxItems' => 0]]];
        yield 'oversized collection bound' => [[['path' => ['fieldparams', 'x'], 'id' => 'x', 'kind' => 'rows', 'columns' => ['value' => 32], 'maxItems' => 51]]];
        yield 'non-integer collection bound' => [[['path' => ['fieldparams', 'x'], 'id' => 'x', 'kind' => 'strings', 'values' => ['a'], 'maxItems' => '2']]];
        yield 'malformed enum value' => [[['path' => ['fieldparams', 'x'], 'id' => 'x', 'kind' => 'enum', 'values' => ["\xC3\x28"]]]];
    }

    public function testPayloadRejectsUnknownAndOversizedValues(): void
    {
        $schema = new AutosaveDynamicSchema([['path' => ['fieldparams', 'value'], 'id' => 'x', 'kind' => 'string', 'maxLength' => 3]]);
        foreach ([['fieldparams' => ['value' => 'long']], ['fieldparams' => ['value' => 'ok', 'extra' => 'x']], ['__proto__' => []]] as $payload) {
            try {
                $schema->normalizePayload($payload);
                $this->fail('Expected invalid dynamic payload.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSupportStatusDistinguishesParameterlessPartialAndUnsupportedSchemas(): void
    {
        $parameterless = new AutosaveDynamicSchema([], AutosaveDynamicSchema::SUPPORT_SUPPORTED, ['parameterless'], true);
        $partial       = new AutosaveDynamicSchema(
            [['path' => ['params', 'title'], 'id' => 'title', 'kind' => 'string', 'maxLength' => 255]],
            AutosaveDynamicSchema::SUPPORT_PARTIAL,
            ['unsupported_control']
        );
        $unsupported = new AutosaveDynamicSchema([], AutosaveDynamicSchema::SUPPORT_UNSUPPORTED, ['schema_too_large']);

        $this->assertSame(['status' => 'supported', 'reasons' => ['parameterless'], 'parameterless' => true], $parameterless->support());
        $this->assertSame(['status' => 'partial', 'reasons' => ['unsupported_control'], 'parameterless' => false], $partial->support());
        $this->assertSame(['status' => 'unsupported', 'reasons' => ['schema_too_large'], 'parameterless' => false], $unsupported->support());
        $this->assertSame($parameterless->fingerprint(), $unsupported->fingerprint());
    }

    public function testSupportStatusRejectsContradictoryOrUnsafeMetadata(): void
    {
        foreach (
            [
            [[], 'unknown', [], false],
            [[], AutosaveDynamicSchema::SUPPORT_PARTIAL, ['Unsafe Detail'], false],
            [[['path' => ['params', 'x'], 'id' => 'x', 'kind' => 'boolean']], AutosaveDynamicSchema::SUPPORT_SUPPORTED, ['parameterless'], true],
            ] as $arguments
        ) {
            try {
                new AutosaveDynamicSchema(...$arguments);
                $this->fail('Expected invalid support metadata.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
