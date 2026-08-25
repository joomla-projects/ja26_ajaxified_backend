<?php

namespace Joomla\Tests\Unit\Administrator\Components\Workflow\Autosave;

use Joomla\CMS\Autosave\AutosaveException;
use Joomla\Component\Workflow\Administrator\Autosave\StageAutosaveProvider;
use Joomla\Component\Workflow\Administrator\Autosave\WorkflowAutosaveProvider;
use Joomla\Database\DatabaseInterface;
use Joomla\Tests\Unit\UnitTestCase;

class WorkflowAutosaveProvidersTest extends UnitTestCase
{
    public function testContextsIdentitiesAndExactPayloads(): void
    {
        $workflow = new WorkflowAutosaveProvider($this->database());
        $stage    = new StageAutosaveProvider($this->database());

        $this->assertSame('com_workflow.workflow', $workflow->getContext());
        $this->assertSame('com_workflow.stage', $stage->getContext());
        $this->assertSame(['title' => '', 'description' => 'draft'], $workflow->normalizePayload(['description' => 'draft', 'title' => ''], 1));
        $this->assertSame(['title' => 'Stage', 'description' => ''], $stage->normalizePayload(['title' => 'Stage', 'description' => ''], 1));

        foreach (['', '0', '-1', '01', ' 1', '1 ', '1.0', '2147483648'] as $invalid) {
            $this->assertFailure('invalid_target', fn () => $workflow->canonicalizeTargetId($invalid));
            $this->assertFailure('invalid_target', fn () => $stage->canonicalizeTargetId($invalid));
        }

        foreach ([['title' => 'x'], ['title' => 'x', 'description' => '', 'extension' => 'com_content'], ['title' => [], 'description' => ''], ['title' => "\xC3\x28", 'description' => '']] as $invalid) {
            $this->assertFailure('invalid_payload', fn () => $workflow->normalizePayload($invalid, 1));
            $this->assertFailure('invalid_payload', fn () => $stage->normalizePayload($invalid, 1));
        }
    }

    private function database(): DatabaseInterface
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(fn () => $this->getQueryStub($db));
        $db->method('quoteName')->willReturnCallback(static fn ($name) => $name);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadObject')->willReturn(null);
        return $db;
    }

    private function assertFailure(string $code, callable $callback): void
    {
        try {
            $callback();
        } catch (AutosaveException $e) {
            $this->assertSame($code, $e->getErrorCode());
            return;
        }
        $this->fail('Expected AutosaveException.');
    }
}
