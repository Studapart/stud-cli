<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\PrCommentResponder;
use App\Response\PrCommentResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class PrCommentResponderTest extends CommandTestCase
{
    public function testRespondSerializesSuccessForAgentMode(): void
    {
        $responder = new PrCommentResponder(new ResponderHelper($this->translationService), $this->createMock(Logger::class));
        $response = PrCommentResponse::replied('Reply posted', 123, 'github:review_thread:thread-1', true);

        $agentResponse = $responder->respond($this->io(), $response, OutputFormat::Json);

        $this->assertNotNull($agentResponse);
        $this->assertTrue($agentResponse->success);
        $this->assertSame('replied', $agentResponse->data['action']);
        $this->assertTrue($agentResponse->data['resolved']);
    }

    public function testRespondSerializesErrorForAgentMode(): void
    {
        $responder = new PrCommentResponder(new ResponderHelper($this->translationService), $this->createMock(Logger::class));

        $agentResponse = $responder->respond($this->io(), PrCommentResponse::error('Failed'), OutputFormat::Json);

        $this->assertNotNull($agentResponse);
        $this->assertFalse($agentResponse->success);
        $this->assertSame('Failed', $agentResponse->error);
    }

    public function testRespondRendersSuccessForCliMode(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('section');
        $logger->expects($this->once())->method('success')->with(Logger::VERBOSITY_NORMAL, 'Posted');
        $responder = new PrCommentResponder(new ResponderHelper($this->translationService), $logger);

        $agentResponse = $responder->respond(
            $this->io(),
            PrCommentResponse::posted('Posted', 123),
            OutputFormat::Cli
        );

        $this->assertNull($agentResponse);
    }

    public function testRespondRendersErrorForCliMode(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('error')->with(Logger::VERBOSITY_NORMAL, 'Failed');
        $responder = new PrCommentResponder(new ResponderHelper($this->translationService), $logger);

        $agentResponse = $responder->respond($this->io(), PrCommentResponse::error('Failed'), OutputFormat::Cli);

        $this->assertNull($agentResponse);
    }

    private function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }
}
