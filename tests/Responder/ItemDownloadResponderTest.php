<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ItemDownloadResponder;
use App\Response\ItemDownloadResponse;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemDownloadResponderTest extends CommandTestCase
{
    public function testRespondJsonReturnsFilesAndErrors(): void
    {
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new ItemDownloadResponder($helper, ['JIRA_URL' => 'https://jira.example'], $logger);
        $response = ItemDownloadResponse::result(
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
        $responder = new ItemDownloadResponder($helper, [], $logger);
        $response = ItemDownloadResponse::fatal('bad');

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
        $responder = new ItemDownloadResponder($helper, ['JIRA_URL' => 'https://jira.example'], $logger);
        $response = ItemDownloadResponse::result([['filename' => 'f', 'path' => 'd/f']], []);

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
        $responder = new ItemDownloadResponder($helper, [], $logger);
        $response = ItemDownloadResponse::result([], [['filename' => 'a', 'message' => 'm']]);

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }

    public function testRespondCliReturnsNullWhenFatal(): void
    {
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('table');
        $responder = new ItemDownloadResponder($helper, [], $logger);
        $response = ItemDownloadResponse::fatal('e');

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }
}
