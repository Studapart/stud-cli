<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ItemUploadResponder;
use App\Response\ItemUploadResponse;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemUploadResponderTest extends CommandTestCase
{
    public function testRespondJsonReturnsFilesAndErrors(): void
    {
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new ItemUploadResponder($helper, ['JIRA_URL' => 'https://jira.example'], $logger);
        $response = ItemUploadResponse::result(
            [['filename' => 'a.txt', 'path' => 'p/a.txt']],
            [['filename' => 'b.txt', 'message' => 'x']]
        );

        $agent = $responder->respond($io, $response, OutputFormat::Json);
        $this->assertNotNull($agent);
        $payload = $agent->toPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame([['filename' => 'a.txt', 'path' => 'p/a.txt']], $payload['data']['files']);
        $this->assertCount(1, $payload['data']['errors']);
    }

    public function testRespondJsonReturnsErrorWhenNotSuccessful(): void
    {
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new ItemUploadResponder($helper, [], $logger);
        $response = ItemUploadResponse::fatal('bad');

        $agent = $responder->respond($io, $response, OutputFormat::Json);
        $this->assertNotNull($agent);
        $payload = $agent->toPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('bad', $payload['error']);
    }

    public function testRespondCliRendersTableWhenFilesPresent(): void
    {
        $helper = new ResponderHelper($this->translationService, new ColorHelper([]));
        $io = $this->createMock(SymfonyStyle::class);
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->atLeastOnce())->method('section');
        $logger->expects($this->once())->method('table');
        $responder = new ItemUploadResponder($helper, ['JIRA_URL' => 'https://jira.example'], $logger);
        $response = ItemUploadResponse::result([['filename' => 'f', 'path' => 'd/f']], []);

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }

    public function testRespondCliLogsPartialErrors(): void
    {
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->atLeastOnce())->method('section');
        $logger->expects($this->once())->method('note');
        $logger->expects($this->once())->method('error');
        $responder = new ItemUploadResponder($helper, [], $logger);
        $response = ItemUploadResponse::result([], [['filename' => 'a', 'message' => 'm']]);

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }

    public function testRespondCliReturnsNullWhenFatal(): void
    {
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('table');
        $responder = new ItemUploadResponder($helper, [], $logger);
        $response = ItemUploadResponse::fatal('e');

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }
}
