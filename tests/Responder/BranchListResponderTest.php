<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\BranchListRow;
use App\Responder\BranchListResponder;
use App\Response\BranchListResponse;
use App\Service\ColorHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchListResponderTest extends CommandTestCase
{
    private BranchListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responder = new BranchListResponder($this->translationService, null);
    }

    public function testRespondShowsMessageWhenNoBranches(): void
    {
        $response = BranchListResponse::success([]);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->callback(fn ($m) => is_string($m) && $m !== ''));

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersTableWhenRowsPresent(): void
    {
        $row = new BranchListRow('feat/PROJ-123 (current)', 'Active', '✓', '✗');
        $response = BranchListResponse::success([$row]);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->never())
            ->method('writeln');
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondShowsVerboseMessagesWhenVerbose(): void
    {
        $row = new BranchListRow('main', 'Active', '✓', '✗');
        $response = BranchListResponse::success([$row]);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->atLeastOnce())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('note')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperAppliesFormatting(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new BranchListResponder($this->translationService, $colorHelper);
        $row = new BranchListRow('main', 'Active', '✓', '✗');
        $response = BranchListResponse::success([$row]);
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->atLeastOnce())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('note')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $responder->respond($io, $response);
    }
}
