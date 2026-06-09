<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\BranchSwitchResponder;
use App\Response\BranchSwitchResponse;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchSwitchResponderTest extends CommandTestCase
{
    public function testRespondCliRendersSuccess(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new BranchSwitchResponder(new ResponderHelper($this->translationService, null), $logger);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->anything());
        $io->expects($this->once())
            ->method('success')
            ->with($this->anything());

        $result = $responder->respond($io, BranchSwitchResponse::switched('SCI-123', 'feat/SCI-123-title'));

        $this->assertNull($result);
    }

    public function testRespondCliRendersError(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new BranchSwitchResponder(new ResponderHelper($this->translationService, null), $logger);

        $io->expects($this->once())
            ->method('error')
            ->with($this->anything());

        $result = $responder->respond($io, BranchSwitchResponse::error('SCI-123', 'failed'));

        $this->assertNull($result);
    }

    public function testRespondCliRendersSelectionFallback(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new BranchSwitchResponder(new ResponderHelper($this->translationService, null), $logger);

        $io->expects($this->once())
            ->method('text')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('listing')
            ->with($this->anything());

        $result = $responder->respond($io, BranchSwitchResponse::needsSelection('SCI-123', ['feat/SCI-123']));

        $this->assertNull($result);
    }

    public function testRespondJsonReturnsSuccessData(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new BranchSwitchResponder(
            new ResponderHelper($this->translationService, null),
            $this->createLogger($io)
        );

        $result = $responder->respond(
            $io,
            BranchSwitchResponse::switched('SCI-123', 'feat/SCI-123-title')->withSyncResult(0, 'sync failed'),
            OutputFormat::Json
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('SCI-123', $result->data['key']);
        $this->assertSame('feat/SCI-123-title', $result->data['branch']);
        $this->assertTrue($result->data['synced']);
        $this->assertSame(0, $result->data['syncExitCode']);
    }

    public function testRespondJsonReturnsError(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new BranchSwitchResponder(
            new ResponderHelper($this->translationService, null),
            $this->createLogger($io)
        );

        $result = $responder->respond($io, BranchSwitchResponse::error('SCI-123', 'failed'), OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->error);
    }
}
