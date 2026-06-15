<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Enum\ResponseMessageLevel;
use App\Service\Prompt\PromptInterface;
use App\Service\WorkflowOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\TableSeparator;

final class WorkflowRecorderTest extends TestCase
{
    public function testRecordsEntriesDiagnosticsAndBuildsResponse(): void
    {
        $recorder = new WorkflowRecorder();

        $recorder->addError(WorkflowRecorder::VERBOSITY_NORMAL, MessageRef::key('error.key'));
        $recorder->addWarning(WorkflowRecorder::VERBOSITY_NORMAL, MessageRef::key('warning.key'));
        $recorder->addNote(WorkflowRecorder::VERBOSITY_NORMAL, MessageRef::key('note.key'));
        $recorder->addSuccess(WorkflowRecorder::VERBOSITY_NORMAL, MessageRef::key('success.key'));
        $recorder->addSection(WorkflowRecorder::VERBOSITY_NORMAL, 'section');

        self::assertCount(5, $recorder->getEntries());
        self::assertCount(3, $recorder->getMessages());
        self::assertSame(ResponseMessageLevel::Error, $recorder->getMessages()[0]->level);

        $response = $recorder->toResponse(1);
        self::assertFalse($response->isSuccess());
        self::assertSame(1, $response->exitCode);
    }

    public function testAbsorbsWorkflowResponseEntriesAndMessages(): void
    {
        $base = \App\Response\WorkflowResponse::fromExitCode(
            0,
            [new \App\DTO\WorkflowOutputEntry('text', WorkflowRecorder::VERBOSITY_NORMAL, 'progress')],
            [\App\DTO\ResponseMessage::error('err')],
        );

        $recorder = new WorkflowRecorder();
        $recorder->absorbResponse($base);
        $recorder->addNote(WorkflowRecorder::VERBOSITY_NORMAL, MessageRef::key('note.key'));

        self::assertCount(2, $recorder->getEntries());
        self::assertCount(2, $recorder->getMessages());
    }

    public function testAbsorbsWorkflowOutputEntriesAndMessages(): void
    {
        $output = new WorkflowOutput($this->createMock(PromptInterface::class));
        $output->addText(WorkflowOutput::VERBOSITY_NORMAL, 'progress');
        $output->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('error.key'));
        $output->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('warning.key'));
        $output->addDefinitionList(WorkflowOutput::VERBOSITY_NORMAL, 'definition', new TableSeparator());

        $recorder = new WorkflowRecorder();
        $recorder->absorb($output);

        self::assertCount(4, $recorder->getEntries());
        self::assertCount(2, $recorder->getMessages());
        self::assertTrue($recorder->toResponse(0)->isSuccess());
    }

    public function testAbsorbMergesWorkflowOutputSnapshot(): void
    {
        $output = $this->createMock(WorkflowOutput::class);
        $output->method('getEntries')->willReturn([
            new \App\DTO\WorkflowOutputEntry('text', WorkflowRecorder::VERBOSITY_NORMAL, 'absorbed'),
        ]);
        $output->method('getMessages')->willReturn([
            \App\DTO\ResponseMessage::notice(MessageRef::key('note.key')),
        ]);

        $recorder = new WorkflowRecorder();
        $recorder->absorb($output);

        self::assertCount(1, $recorder->getEntries());
        self::assertCount(1, $recorder->getMessages());
    }
}
