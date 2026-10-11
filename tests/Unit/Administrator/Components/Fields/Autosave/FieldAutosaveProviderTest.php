<?php

namespace Joomla\Tests\Unit\Administrator\Components\Fields\Autosave;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Autosave\AutosaveDynamicSchema;
use Joomla\CMS\Autosave\AutosaveException;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Autosave\FieldAutosaveProvider;
use Joomla\Component\Fields\Administrator\Autosave\FieldAutosaveSchemaFactory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

class FieldAutosaveProviderTest extends UnitTestCase
{
    public function testRealBuiltInPluginFormsProduceTypeSpecificSchemas(): void
    {
        $previous            = Factory::$application;
        $application         = $this->createMock(CMSApplication::class);
        $application->method('getIdentity')->willReturn($this->createMock(User::class));
        $application->method('getConfig')->willReturn(new Registry());
        Factory::$application = $application;

        try {
            $factory  = new FieldAutosaveSchemaFactory();
            $text     = $factory->forType('text');
            $calendar = $factory->forType('calendar');
            $list     = $factory->forType('list');

            $renderedForm = new Form('com_fields.field', ['control' => 'jform']);
            $renderedForm->load(file_get_contents(JPATH_PLUGINS . '/fields/text/params/text.xml'), true, '/form/*');

            $this->assertSame(['filter', 'maxlength'], array_map(static fn (array $field): string => $field['path'][1], $text->fields()));
            $this->assertSame($factory->fromForm($renderedForm, 'text')->fingerprint(), $text->fingerprint());
            $this->assertNotSame($text->fingerprint(), $calendar->fingerprint());
            $this->assertContains('rows', array_column($list->fields(), 'kind'));
            $this->assertSame([], $factory->forType('sql')->fields());
        } finally {
            Factory::$application = $previous;
        }
    }

    public function testExactTargetAwareContractRejectsStaleAndUnknownValues(): void
    {
        $schema   = new AutosaveDynamicSchema([['path' => ['fieldparams', 'maxlength'], 'id' => 'maxlength', 'kind' => 'string', 'maxLength' => 4]]);
        $provider = new FieldAutosaveProvider($this->databaseReturning($this->record()), static fn () => $schema);
        $payload  = ['title' => '', 'name' => '', 'label' => '', 'description' => '', 'default_value' => '', 'note' => '', 'required' => false, 'only_use_in_subform' => false, 'schemaFingerprint' => $schema->fingerprint(), 'fieldparams' => ['maxlength' => '100']];
        $this->assertSame('com_fields.field', $provider->getContext());
        $this->assertSame($payload, $provider->normalizePayloadForTarget('42', $payload, 1));
        $this->assertFailure(fn () => $provider->normalizePayloadForTarget('42', array_replace($payload, ['schemaFingerprint' => str_repeat('0', 64)]), 1));
        $this->assertFailure(fn () => $provider->normalizePayloadForTarget('42', array_replace($payload, ['fieldparams' => ['maxlength' => '100', 'query' => 'DROP']]), 1));
    }

    public function testIdentityAndRevisionAreCanonical(): void
    {
        $provider = new FieldAutosaveProvider($this->databaseReturning($this->record()), static fn () => new AutosaveDynamicSchema([]));
        $this->assertSame('42', $provider->canonicalizeTargetId('42'));
        $this->assertStringStartsWith('autosave:com_fields.field:base-revision:v1:', $provider->getBaseRevision('42'));
        foreach (['0', '-1', '01', ' 42', '2147483648'] as $target) {
            $this->assertFailure(fn () => $provider->canonicalizeTargetId($target), 'invalid_target');
        }
    }

    private function record(): object
    {
        return (object) ['id' => 42, 'context' => 'com_content.article', 'type' => 'text', 'created_user_id' => 7, 'checked_out' => 0, 'title' => 'Field'];
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
