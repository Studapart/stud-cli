<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\ResponseMessage;
use App\Enum\OutputFormat;
use App\Responder\CommandResponder;
use App\Response\CommandResponse;
use App\Service\Logger;
use PHPUnit\Framework\TestCase;

final class CommandResponderTest extends TestCase
{
    public function testRespondJsonReturnsAgentResponse(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('success');

        $response = CommandResponse::success('Done');
        $result = (new CommandResponder($logger))->respond($response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertSame(['success' => true, 'data' => ['message' => 'Done']], $result->toPayload());
    }

    public function testRespondCompactJsonReturnsCompactPayload(): void
    {
        $result = (new CommandResponder($this->createMock(Logger::class)))
            ->respond(CommandResponse::success(), OutputFormat::Json, compact: true);

        $this->assertNotNull($result);
        $this->assertSame(['success' => true], $result->toPayload());
    }

    public function testRespondCliRendersSuccessAndDiagnostics(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('errorWithDetails')->with($this->anything(), 'Failed once', 'details');
        $logger->expects($this->once())->method('warning')->with($this->anything(), 'Careful');
        $logger->expects($this->once())->method('note')->with($this->anything(), 'Heads up');
        $logger->expects($this->once())->method('text')->with($this->anything(), 'Verbose detail');
        $logger->expects($this->once())->method('success')->with($this->anything(), 'Done');

        $response = CommandResponse::success('Done', messages: [
            ResponseMessage::error('Failed once', 'details'),
            ResponseMessage::warning('Careful'),
            ResponseMessage::notice('Heads up'),
            ResponseMessage::info('Verbose detail'),
        ]);

        $this->assertNull((new CommandResponder($logger))->respond($response));
    }

    public function testRespondCliRendersErrorWhenResponseFails(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('error')->with($this->anything(), 'Failed');
        $logger->expects($this->never())->method('success');

        $this->assertNull((new CommandResponder($logger))->respond(CommandResponse::error('Failed')));
    }
}
