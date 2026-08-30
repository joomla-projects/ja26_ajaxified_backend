<?php

namespace Joomla\Tests\Unit\Administrator\Components\Menus\Autosave;

use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\Component\Menus\Administrator\Autosave\ItemAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class ItemAutosaveProviderTest extends UnitTestCase
{
    public function testTargetAwarePayloadUsesOnlyCanonicalRouteSchema(): void
    {
        $schema   = new AutosaveDynamicSchema([['path' => ['params', 'page_title'], 'id' => 'jform_params_page_title', 'kind' => 'string', 'maxLength' => 255]]);
        $provider = new ItemAutosaveProvider($this->databaseReturning($this->record()), static fn () => $schema);
        $payload  = ['title' => '', 'alias' => '', 'note' => '', 'browserNav' => '0', 'schemaFingerprint' => $schema->fingerprint(), 'params' => ['page_title' => 'Draft']];

        $this->assertSame('com_menus.item', $provider->getContext());
        $this->assertSame($payload, $provider->normalizePayloadForTarget('42', $payload, 1));
        $this->assertFailure(fn () => $provider->normalizePayloadForTarget('42', array_replace($payload, ['params' => ['contact_id' => '7']]), 1));
        $this->assertFailure(fn () => $provider->normalizePayloadForTarget('42', array_replace($payload, ['schemaFingerprint' => str_repeat('0', 64)]), 1));
    }

    public function testIdentityAndRevisionAreCanonical(): void
    {
        $provider = new ItemAutosaveProvider($this->databaseReturning($this->record()), static fn () => new AutosaveDynamicSchema([]));
        $this->assertSame('42', $provider->canonicalizeTargetId('42'));
        $this->assertStringStartsWith('autosave:com_menus.item:base-revision:v1:', $provider->getBaseRevision('42'));

        foreach (['0', '-1', '01', ' 42', '2147483648'] as $target) {
            $this->assertFailure(fn () => $provider->canonicalizeTargetId($target), 'invalid_target');
        }
    }

    public function testSchemaResolverReceivesTheAuthoritativeStoredRoute(): void
    {
        $record   = $this->record();
        $seen     = null;
        $schema   = new AutosaveDynamicSchema([]);
        $provider = new ItemAutosaveProvider(
            $this->databaseReturning($record),
            static function (object $resolved) use (&$seen, $schema): AutosaveDynamicSchema {
                $seen = $resolved;

                return $schema;
            }
        );

        $provider->getDynamicSchema('42');

        $this->assertSame($record, $seen);
    }

    private function record(): object
    {
        return (object) ['id' => 42, 'menu_type_id' => 3, 'menutype' => 'mainmenu', 'title' => 'Item', 'alias' => 'item', 'note' => '', 'link' => 'index.php?option=com_content&view=article&id=1', 'type' => 'component', 'component_id' => 22, 'browserNav' => 0, 'params' => '{}', 'checked_out' => 0, 'published' => 1, 'parent_id' => 1, 'access' => 1, 'language' => '*', 'home' => 0, 'client_id' => 0];
    }

    private function databaseReturning(object $record): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn($record);

        return $db;
    }

    private function assertFailure(callable $callback, string $code = 'invalid_payload'): void
    {
        try {
            $callback();
        } catch (AutosaveException $exception) {
            $this->assertSame($code, $exception->getErrorCode());

            return;
        }

        $this->fail('Expected AutosaveException.');
    }
}
