<?php

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ErrorResponder;
use App\Response\FilterShowResponse;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ErrorResponderTest extends CommandTestCase
{
    public function testRespondDisplaysError(): void
    {
        $response = FilterShowResponse::error('Test error message');
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createMock(Logger::class);

        $logger->expects($this->once())
            ->method('error')
            ->with($this->anything(), 'Test error message');

        $responder = new ErrorResponder($this->translationService, [], $logger);
        $responder->respond($io, $response);
    }

    public function testRespondDisplaysErrorString(): void
    {
        $response = FilterShowResponse::error('Custom error');
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createMock(Logger::class);

        $logger->expects($this->once())
            ->method('error')
            ->with($this->anything(), 'Custom error');

        $responder = new ErrorResponder($this->translationService, [], $logger);
        $responder->respond($io, $response);
    }

    public function testRespondJsonReturnsAgentJsonResponse(): void
    {
        $response = FilterShowResponse::error('Test error');
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createMock(Logger::class);

        $logger->expects($this->never())->method('error');

        $responder = new ErrorResponder($this->translationService, [], $logger);
        $result = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('Test error', $result->error);
    }

    public function testRespondCliReturnsNull(): void
    {
        $response = FilterShowResponse::error('Test error');
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createMock(Logger::class);

        $responder = new ErrorResponder($this->translationService, [], $logger);
        $result = $responder->respond($io, $response, OutputFormat::Cli);

        $this->assertNull($result);
    }
}
