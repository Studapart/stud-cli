<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Enum\ResponseMessageLevel;
use App\Service\Prompt\PromptInterface;
use App\Service\WorkflowOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\TableSeparator;

final class WorkflowOutputTest extends TestCase
{
    public function testRecordsEntriesDiagnosticsAndBuildsResponse(): void
    {
        $output = new WorkflowOutput($this->createMock(PromptInterface::class));

        $output->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('error.key'));
        $output->addErrorWithDetails(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('error.details'), 'details');
        $output->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('warning.key'));
        $output->addWarning(WorkflowOutput::VERBOSITY_NORMAL, ['warning line one', 'warning line two']);
        $output->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('note.key'));
        $output->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('success.key'));
        $output->addText(WorkflowOutput::VERBOSITY_NORMAL, [MessageRef::key('line.one'), 'line two']);
        $output->addRawValue('raw');
        $output->addLine(WorkflowOutput::VERBOSITY_NORMAL, 'line');
        $output->addJiraLine(WorkflowOutput::VERBOSITY_NORMAL, 'jira');
        $output->addGitLine(WorkflowOutput::VERBOSITY_NORMAL, 'git');
        $output->addJiraText(WorkflowOutput::VERBOSITY_NORMAL, ['jira text']);
        $output->addGitText(WorkflowOutput::VERBOSITY_NORMAL, ['git text']);
        $output->addSection(WorkflowOutput::VERBOSITY_NORMAL, 'section');
        $output->addTitle(WorkflowOutput::VERBOSITY_NORMAL, 'title');
        $output->addListing(WorkflowOutput::VERBOSITY_NORMAL, ['item']);
        $output->addComment(WorkflowOutput::VERBOSITY_NORMAL, ['comment']);
        $output->addInfo(WorkflowOutput::VERBOSITY_NORMAL, ['info']);
        $output->addCaution(WorkflowOutput::VERBOSITY_NORMAL, ['caution']);
        $output->addTable(WorkflowOutput::VERBOSITY_NORMAL, ['h'], [['v']]);
        $output->addHorizontalTable(WorkflowOutput::VERBOSITY_NORMAL, ['h'], [['v']]);
        $output->addDefinitionList(WorkflowOutput::VERBOSITY_NORMAL, 'definition', new TableSeparator());
        $output->addNewLine(WorkflowOutput::VERBOSITY_NORMAL, 2);

        self::assertCount(23, $output->getEntries());
        self::assertCount(5, $output->getMessages());
        self::assertSame(ResponseMessageLevel::Error, $output->getMessages()[0]->level);
        self::assertSame('listing', $output->getEntries()[15]->type);

        $response = $output->toResponse(1);
        self::assertFalse($response->isSuccess());
        self::assertSame(1, $response->exitCode);
        self::assertSame(MessageRef::key('error.key')->key, $response->getErrorMessage()?->key);
    }

    public function testDelegatesPrompts(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturn('answer');
        $prompt->method('askHidden')->willReturn('secret');
        $prompt->method('confirm')->willReturn(true);
        $prompt->method('choice')->willReturn('choice');

        $output = new WorkflowOutput($prompt);

        self::assertSame('answer', $output->ask('question'));
        self::assertSame('secret', $output->askHidden('secret'));
        self::assertTrue($output->confirm('confirm'));
        self::assertSame('choice', $output->choice('choice', ['choice']));
    }
}
