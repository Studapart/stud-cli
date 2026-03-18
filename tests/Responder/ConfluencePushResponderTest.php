<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ConfluencePushResponder;
use App\Response\ConfluencePushResponse;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfluencePushResponderTest extends CommandTestCase
{
    public function testRespondCliSuccessCallsLoggerSuccessWithMessage(): void
    {
        $response = ConfluencePushResponse::success(
            '12345',
            'My Page',
            'https://example.atlassian.net/wiki/spaces/DEV/pages/12345',
            'created'
        );
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())
            ->method('success')
            ->with(self::anything(), self::callback(function (string $message): bool {
                return str_contains($message, 'My Page') && str_contains($message, '12345');
            }));
        $responder = new ConfluencePushResponder($this->translationService, $logger);
        $io = $this->createMock(SymfonyStyle::class);

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
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())
            ->method('success')
            ->with(self::anything(), self::callback(function (string $message): bool {
                return str_contains($message, 'Updated Page') && str_contains($message, '67890');
            }));
        $responder = new ConfluencePushResponder($this->translationService, $logger);
        $io = $this->createMock(SymfonyStyle::class);

        $responder->respond($io, $response, OutputFormat::Cli);
    }

    public function testRespondCliErrorCallsLoggerError(): void
    {
        $response = ConfluencePushResponse::error('Space not found');
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error')->with(self::anything(), 'Space not found');
        $logger->expects(self::never())->method('success');
        $responder = new ConfluencePushResponder($this->translationService, $logger);
        $io = $this->createMock(SymfonyStyle::class);

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
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::never())->method('success');
        $logger->expects(self::never())->method('error');
        $responder = new ConfluencePushResponder($this->translationService, $logger);
        $io = $this->createMock(SymfonyStyle::class);

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
        $responder = new ConfluencePushResponder($this->translationService, $this->createMock(Logger::class));
        $io = $this->createMock(SymfonyStyle::class);

        $agentResponse = $responder->respond($io, $response, OutputFormat::Json);

        self::assertNotNull($agentResponse);
        self::assertFalse($agentResponse->success);
        self::assertSame('No content provided', $agentResponse->error);
    }
}
