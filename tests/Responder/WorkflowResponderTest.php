<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\WorkflowOutputEntry;
use App\Enum\OutputFormat;
use App\Enum\WorkflowChannel;
use App\Responder\WorkflowResponder;
use App\Response\WorkflowResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;

final class WorkflowResponderTest extends TestCase
{
    public function testRespondCliRendersRecordedEntries(): void
    {
        $logger = $this->createMock(Logger::class);
        $responder = new WorkflowResponder($logger, $this->messageRenderer());
        $response = WorkflowResponse::fromExitCode(0, [
            new WorkflowOutputEntry('error', 0, MessageRef::key('table.key')),
            new WorkflowOutputEntry('errorWithDetails', 0, MessageRef::key('table.key'), 'details'),
            new WorkflowOutputEntry('warning', 0, MessageRef::key('table.key')),
            new WorkflowOutputEntry('note', 0, MessageRef::key('table.key')),
            new WorkflowOutputEntry('success', 0, MessageRef::key('table.key')),
            new WorkflowOutputEntry('text', 0, [MessageRef::key('table.key')]),
            new WorkflowOutputEntry('rawValue', 0, 'raw'),
            new WorkflowOutputEntry('writeln', 0, 'line'),
            new WorkflowOutputEntry('writeln', 0, 'jira', channel: WorkflowChannel::Jira),
            new WorkflowOutputEntry('writeln', 0, 'git', channel: WorkflowChannel::Git),
            new WorkflowOutputEntry('text', 0, ['jira'], channel: WorkflowChannel::Jira),
            new WorkflowOutputEntry('text', 0, ['git'], channel: WorkflowChannel::Git),
            new WorkflowOutputEntry('section', 0, 'section'),
            new WorkflowOutputEntry('title', 0, 'title'),
            new WorkflowOutputEntry('listing', 0, elements: ['item']),
            new WorkflowOutputEntry('comment', 0, ['comment']),
            new WorkflowOutputEntry('comment', 0, 'comment'),
            new WorkflowOutputEntry('info', 0, ['info']),
            new WorkflowOutputEntry('caution', 0, ['caution']),
            new WorkflowOutputEntry('table', 0, headers: ['h'], rows: [['v']]),
            new WorkflowOutputEntry('horizontalTable', 0, headers: ['h'], rows: [['v']]),
            new WorkflowOutputEntry('definitionList', 0, definitionList: ['definition', new TableSeparator()]),
            new WorkflowOutputEntry('newLine', 0, count: 2),
            new WorkflowOutputEntry('unknown', 0, 'ignored'),
        ]);

        self::assertNull($responder->respond($this->createMock(SymfonyStyle::class), $response));
    }

    public function testRespondJsonCompactsSuccessfulResponses(): void
    {
        $responder = new WorkflowResponder($this->createMock(Logger::class), $this->messageRenderer());
        $response = WorkflowResponse::fromExitCode(0, messages: [
            ResponseMessage::warning(MessageRef::key('table.key')),
        ]);

        $agentResponse = $responder->respond($this->createMock(SymfonyStyle::class), $response, OutputFormat::Json, true);

        self::assertSame([
            'success' => true,
            'diagnostics' => ['warnings' => [['message' => 'Key']]],
        ], $agentResponse?->toPayload());
    }

    public function testRespondJsonUsesAgentRendererWhenProvided(): void
    {
        $responder = new WorkflowResponder($this->createMock(Logger::class), $this->agentMessageRenderer());
        $response = WorkflowResponse::fromExitCode(0, messages: [
            ResponseMessage::warning(MessageRef::key('table.key')),
        ]);

        $agentResponse = $responder->respond($this->createMock(SymfonyStyle::class), $response, OutputFormat::Json, true);

        self::assertSame([
            'success' => true,
            'diagnostics' => ['warnings' => [['message' => 'key']]],
        ], $agentResponse?->toPayload());
    }

    public function testRespondJsonExpandsFailedResponses(): void
    {
        $responder = new WorkflowResponder($this->createMock(Logger::class), $this->messageRenderer());
        $response = WorkflowResponse::fromExitCode(1, messages: [
            ResponseMessage::error(MessageRef::key('table.key')),
        ]);

        $agentResponse = $responder->respond($this->createMock(SymfonyStyle::class), $response, OutputFormat::Json, false);

        self::assertSame([
            'success' => false,
            'error' => 'Key',
            'diagnostics' => ['errors' => [['message' => 'Key']]],
        ], $agentResponse?->toPayload());
    }

    private function messageRenderer(): MessageRenderer
    {
        return new MessageRenderer(new TranslationService('en', __DIR__ . '/../../src/resources/translations'));
    }

    private function agentMessageRenderer(): MessageRenderer
    {
        return new MessageRenderer(new TranslationService('vi', __DIR__ . '/../../src/resources/translations'), agent: true);
    }
}
