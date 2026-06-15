<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Enum\ResponseMessageLevel;
use App\Enum\WorkflowChannel;
use App\Service\CommandOutputBuffer;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

final class CommandOutputBufferTest extends TestCase
{
    public function testCollectsDiagnosticsAndDelegatesRendering(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('errorWithDetails');
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->once())->method('note');
        $prompt = $this->createMock(PromptInterface::class);
        $buffer = new CommandOutputBuffer($logger, $prompt);

        $buffer->errorWithDetails(CommandOutputBuffer::VERBOSITY_NORMAL, 'Failed', 'details');
        $buffer->warning(CommandOutputBuffer::VERBOSITY_NORMAL, 'Careful');
        $buffer->note(CommandOutputBuffer::VERBOSITY_NORMAL, 'FYI');

        $messages = $buffer->getMessages();
        $this->assertSame(ResponseMessageLevel::Error, $messages[0]->level);
        $this->assertSame('details', $messages[0]->technicalDetails);
        $this->assertSame(ResponseMessageLevel::Warning, $messages[1]->level);
        $this->assertSame(ResponseMessageLevel::Notice, $messages[2]->level);
    }

    public function testDelegatesPrompts(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('ask')->willReturn('answer');
        $prompt->expects($this->once())->method('askHidden')->willReturn('secret');
        $prompt->expects($this->once())->method('confirm')->willReturn(true);
        $prompt->expects($this->once())->method('choice')->willReturn('one');

        $buffer = new CommandOutputBuffer(null, $prompt);

        $this->assertSame('answer', $buffer->ask('Question?'));
        $this->assertSame('secret', $buffer->askHidden('Secret?'));
        $this->assertTrue($buffer->confirm('Continue?'));
        $this->assertSame('one', $buffer->choice('Pick', ['one']));
    }

    public function testDelegatesLoggerRenderingMethods(): void
    {
        $logger = $this->createMock(Logger::class);
        foreach ([
            'success',
            'text',
            'rawValue',
            'writeln',
            'jiraWriteln',
            'gitWriteln',
            'jiraText',
            'gitText',
            'section',
            'title',
            'listing',
            'comment',
            'info',
            'caution',
            'table',
            'horizontalTable',
            'definitionList',
            'newLine',
        ] as $method) {
            $logger->expects($this->once())->method($method);
        }

        $buffer = new CommandOutputBuffer($logger, $this->createMock(PromptInterface::class));
        $buffer->success(CommandOutputBuffer::VERBOSITY_NORMAL, 'Done');
        $buffer->text(CommandOutputBuffer::VERBOSITY_NORMAL, 'Text');
        $buffer->rawValue('raw');
        $buffer->writeln(CommandOutputBuffer::VERBOSITY_NORMAL, 'Line');
        $buffer->writeln(CommandOutputBuffer::VERBOSITY_NORMAL, 'Jira', WorkflowChannel::Jira);
        $buffer->writeln(CommandOutputBuffer::VERBOSITY_NORMAL, 'Git', WorkflowChannel::Git);
        $buffer->text(CommandOutputBuffer::VERBOSITY_NORMAL, ['Jira'], WorkflowChannel::Jira);
        $buffer->text(CommandOutputBuffer::VERBOSITY_NORMAL, ['Git'], WorkflowChannel::Git);
        $buffer->section(CommandOutputBuffer::VERBOSITY_NORMAL, 'Section');
        $buffer->title(CommandOutputBuffer::VERBOSITY_NORMAL, 'Title');
        $buffer->listing(CommandOutputBuffer::VERBOSITY_NORMAL, ['item']);
        $buffer->comment(CommandOutputBuffer::VERBOSITY_NORMAL, 'Comment');
        $buffer->info(CommandOutputBuffer::VERBOSITY_NORMAL, 'Info');
        $buffer->caution(CommandOutputBuffer::VERBOSITY_NORMAL, 'Caution');
        $buffer->table(CommandOutputBuffer::VERBOSITY_NORMAL, ['A'], [['B']]);
        $buffer->horizontalTable(CommandOutputBuffer::VERBOSITY_NORMAL, ['A'], [['B']]);
        $buffer->definitionList(CommandOutputBuffer::VERBOSITY_NORMAL, ['A' => 'B']);
        $buffer->newLine(CommandOutputBuffer::VERBOSITY_NORMAL);
    }

    public function testAddAliasesDelegateToLoggerRenderingMethods(): void
    {
        $logger = $this->createMock(Logger::class);
        foreach ([
            'error',
            'errorWithDetails',
            'warning',
            'note',
            'success',
            'text',
            'rawValue',
            'writeln',
            'jiraWriteln',
            'gitWriteln',
            'jiraText',
            'gitText',
            'section',
            'title',
            'listing',
            'comment',
            'info',
            'caution',
            'table',
            'horizontalTable',
            'definitionList',
            'newLine',
        ] as $method) {
            $logger->expects($this->once())->method($method);
        }

        $buffer = new CommandOutputBuffer($logger, $this->createMock(PromptInterface::class));
        $buffer->addError(CommandOutputBuffer::VERBOSITY_NORMAL, 'Error');
        $buffer->addErrorWithDetails(CommandOutputBuffer::VERBOSITY_NORMAL, 'Error', 'details');
        $buffer->addWarning(CommandOutputBuffer::VERBOSITY_NORMAL, 'Warning');
        $buffer->addNote(CommandOutputBuffer::VERBOSITY_NORMAL, 'Note');
        $buffer->addSuccess(CommandOutputBuffer::VERBOSITY_NORMAL, 'Done');
        $buffer->addText(CommandOutputBuffer::VERBOSITY_NORMAL, 'Text');
        $buffer->addRawValue('raw');
        $buffer->addLine(CommandOutputBuffer::VERBOSITY_NORMAL, 'Line');
        $buffer->addLine(CommandOutputBuffer::VERBOSITY_NORMAL, 'Jira', WorkflowChannel::Jira);
        $buffer->addLine(CommandOutputBuffer::VERBOSITY_NORMAL, 'Git', WorkflowChannel::Git);
        $buffer->addText(CommandOutputBuffer::VERBOSITY_NORMAL, ['Jira'], WorkflowChannel::Jira);
        $buffer->addText(CommandOutputBuffer::VERBOSITY_NORMAL, ['Git'], WorkflowChannel::Git);
        $buffer->addSection(CommandOutputBuffer::VERBOSITY_NORMAL, 'Section');
        $buffer->addTitle(CommandOutputBuffer::VERBOSITY_NORMAL, 'Title');
        $buffer->addListing(CommandOutputBuffer::VERBOSITY_NORMAL, ['item']);
        $buffer->addComment(CommandOutputBuffer::VERBOSITY_NORMAL, 'Comment');
        $buffer->addInfo(CommandOutputBuffer::VERBOSITY_NORMAL, 'Info');
        $buffer->addCaution(CommandOutputBuffer::VERBOSITY_NORMAL, 'Caution');
        $buffer->addTable(CommandOutputBuffer::VERBOSITY_NORMAL, ['A'], [['B']]);
        $buffer->addHorizontalTable(CommandOutputBuffer::VERBOSITY_NORMAL, ['A'], [['B']]);
        $buffer->addDefinitionList(CommandOutputBuffer::VERBOSITY_NORMAL, ['A' => 'B']);
        $buffer->addNewLine(CommandOutputBuffer::VERBOSITY_NORMAL);
    }

    public function testStringifiesArrayDiagnostics(): void
    {
        $buffer = new CommandOutputBuffer(null, $this->createMock(PromptInterface::class));

        $buffer->error(CommandOutputBuffer::VERBOSITY_NORMAL, ['Line 1', 'Line 2']);

        $this->assertSame("Line 1\nLine 2", $buffer->getMessages()[0]->message);
    }

    public function testRendersMessageRefArraysBeforeDelegatingToLogger(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('text')
            ->with(CommandOutputBuffer::VERBOSITY_NORMAL, ['Key']);
        $renderer = new MessageRenderer(new TranslationService('en', __DIR__ . '/../../src/resources/translations'));
        $buffer = new CommandOutputBuffer($logger, $this->createMock(PromptInterface::class), $renderer);

        $buffer->text(CommandOutputBuffer::VERBOSITY_NORMAL, [MessageRef::key('table.key')]);
    }
}
