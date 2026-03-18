<?php

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ItemCreateResponder;
use App\Response\ItemCreateResponse;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemCreateResponderTest extends CommandTestCase
{
    public function testRespondCallsSuccessWithKeyAndUrl(): void
    {
        $response = ItemCreateResponse::success('PROJ-42', 'https://jira.example.com/rest/api/3/issue/123');
        $jiraConfig = ['JIRA_URL' => 'https://jira.example.com'];
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemCreateResponder($this->translationService, $jiraConfig, $this->createLogger($io));

        $io->expects($this->once())
            ->method('success')
            ->with($this->callback(function ($message) {
                return is_string($message)
                    && str_contains($message, 'PROJ-42')
                    && (str_contains($message, 'jira.example.com') && str_contains($message, 'browse'));
            }));

        $responder->respond($io, $response);
    }

    public function testRespondDoesNotCallSuccessWhenResponseIsError(): void
    {
        $response = ItemCreateResponse::error('Something failed');
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemCreateResponder($this->translationService, ['JIRA_URL' => 'https://jira.example.com'], $this->createLogger($io));

        $io->expects($this->never())->method('success');

        $responder->respond($io, $response);
    }

    public function testRespondUsesSelfWhenJiraUrlEmpty(): void
    {
        $response = ItemCreateResponse::success('PROJ-1', 'https://api.jira/issue/1');
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemCreateResponder($this->translationService, ['JIRA_URL' => ''], $this->createLogger($io));

        $io->expects($this->once())
            ->method('success')
            ->with($this->callback(function ($message) {
                return str_contains($message, 'PROJ-1') && str_contains($message, 'api.jira');
            }));

        $responder->respond($io, $response);
    }

    public function testRespondShowsNoteWhenSkippedOptionalFields(): void
    {
        $response = ItemCreateResponse::success('PROJ-1', 'https://jira.example.com/issue/1', ['labels', 'time original estimate']);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemCreateResponder($this->translationService, ['JIRA_URL' => 'https://jira.example.com'], $this->createLogger($io));

        $io->expects($this->once())->method('success');
        $io->expects($this->once())
            ->method('note')
            ->with($this->callback(function (string $message) {
                return str_contains($message, 'item.create.note_skipped_optional_fields')
                    && str_contains($message, 'labels')
                    && str_contains($message, 'time original estimate');
            }));

        $responder->respond($io, $response);
    }

    public function testRespondJsonReturnsCreatedIssueData(): void
    {
        $response = ItemCreateResponse::success('PROJ-1', 'https://jira.example.com/issue/PROJ-1', []);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemCreateResponder($this->translationService, ['JIRA_URL' => 'https://jira.example.com'], $this->createLogger($io));

        $result = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('PROJ-1', $result->data['key']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = ItemCreateResponse::error('Failed to create');
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemCreateResponder($this->translationService, ['JIRA_URL' => 'https://jira.example.com'], $this->createLogger($io));

        $result = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
    }
}
