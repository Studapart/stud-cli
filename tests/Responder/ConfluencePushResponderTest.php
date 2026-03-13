<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ConfluencePushResponder;
use App\Response\ConfluencePushResponse;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfluencePushResponderTest extends CommandTestCase
{
    public function testRespondCliSuccessCallsIoSuccessWithMessage(): void
    {
        $response = ConfluencePushResponse::success(
            '12345',
            'My Page',
            'https://example.atlassian.net/wiki/spaces/DEV/pages/12345',
            'created'
        );
        $responder = new ConfluencePushResponder($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects(self::once())
            ->method('success')
            ->with(self::callback(function (string $message): bool {
                return str_contains($message, 'My Page') && str_contains($message, '12345');
            }));

        $result = $responder->respond($io, $response, OutputFormat::Cli);

        self::assertNull($result);
    }

    public function testRespondCliSuccessUpdatedUsesUpdatedKey(): void
    {
        $response = ConfluencePushResponse::success(
            '67890',
            'Updated Page',
            'https://example.atlassian.net/wiki/spaces/DOC/pages/67890',
            'updated'
        );
        $responder = new ConfluencePushResponder($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects(self::once())
            ->method('success')
            ->with(self::callback(function (string $message): bool {
                return str_contains($message, 'Updated Page') && str_contains($message, '67890');
            }));

        $responder->respond($io, $response, OutputFormat::Cli);
    }

    public function testRespondCliErrorCallsIoError(): void
    {
        $response = ConfluencePushResponse::error('Space not found');
        $responder = new ConfluencePushResponder($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects(self::once())->method('error')->with('Space not found');
        $io->expects(self::never())->method('success');

        $result = $responder->respond($io, $response, OutputFormat::Cli);

        self::assertNull($result);
    }

    public function testRespondJsonSuccessReturnsAgentJsonResponseWithData(): void
    {
        $response = ConfluencePushResponse::success(
            '12345',
            'Sprint Retro',
            'https://example.atlassian.net/wiki/spaces/DEV/pages/12345',
            'updated'
        );
        $responder = new ConfluencePushResponder($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects(self::never())->method('success');
        $io->expects(self::never())->method('error');

        $agentResponse = $responder->respond($io, $response, OutputFormat::Json);

        self::assertNotNull($agentResponse);
        self::assertTrue($agentResponse->success);
        self::assertSame('12345', $agentResponse->data['pageId'] ?? null);
        self::assertSame('Sprint Retro', $agentResponse->data['title'] ?? null);
        self::assertSame('https://example.atlassian.net/wiki/spaces/DEV/pages/12345', $agentResponse->data['url'] ?? null);
        self::assertSame('updated', $agentResponse->data['action'] ?? null);
    }

    public function testRespondJsonErrorReturnsAgentJsonResponseWithError(): void
    {
        $response = ConfluencePushResponse::error('No content provided');
        $responder = new ConfluencePushResponder($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);

        $agentResponse = $responder->respond($io, $response, OutputFormat::Json);

        self::assertNotNull($agentResponse);
        self::assertFalse($agentResponse->success);
        self::assertSame('No content provided', $agentResponse->error);
    }
}
