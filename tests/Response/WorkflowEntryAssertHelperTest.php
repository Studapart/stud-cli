<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\AssertionFailedError;

final class WorkflowEntryAssertHelperTest extends CommandTestCase
{
    public function testReturnsMatchingEntryAndParameters(): void
    {
        $recorder = new WorkflowRecorder();
        $recorder->addSuccess(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('x.ok', ['a' => 'b']),
        );

        $ref = $this->assertWorkflowEntry($recorder->toResponse(0), 'x.ok', ['a' => 'b']);

        $this->assertSame('x.ok', $ref->key);
    }

    public function testMissingKeyFails(): void
    {
        $recorder = new WorkflowRecorder();
        $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('other'));

        $this->expectException(AssertionFailedError::class);

        $this->assertWorkflowEntry($recorder->toResponse(0), 'x.ok');
    }

    public function testStringEntryIsIgnored(): void
    {
        $recorder = new WorkflowRecorder();
        $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, 'plain');
        $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('x.ok'));

        $ref = $this->assertWorkflowEntry($recorder->toResponse(0), 'x.ok');

        $this->assertSame('x.ok', $ref->key);
    }
}
