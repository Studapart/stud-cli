<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\BranchListRow;
use App\Enum\OutputFormat;
use App\Responder\BranchListResponder;
use App\Response\BranchListResponse;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchListResponderTest extends CommandTestCase
{
    private BranchListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $helper = new ResponderHelper($this->translationService, null);
        $io = $this->createMock(SymfonyStyle::class);
        $this->responder = new BranchListResponder($helper, $this->createLogger($io));
    }

    public function testRespondShowsMessageWhenNoBranches(): void
    {
        $response = BranchListResponse::success([]);
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new BranchListResponder(new ResponderHelper($this->translationService, null), $logger);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(fn ($m) => is_string($m) && $m !== ''));

        $responder->respond($io, $response);
    }

    public function testRespondRendersTableWhenRowsPresent(): void
    {
        $row = new BranchListRow('feat/PROJ-123 (current)', 'Active', 'No', '✓', '✗');
        $response = BranchListResponse::success([$row]);
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($io);
        $responder = new BranchListResponder(new ResponderHelper($this->translationService, null), $logger);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->never())
            ->method('text');
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $responder->respond($io, $response);
    }

    public function testRespondShowsVerboseMessagesWhenVerbose(): void
    {
        $row = new BranchListRow('main', 'Active', 'No', '✓', '✗');
        $response = BranchListResponse::success([$row]);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new Logger($io, []);
        $responder = new BranchListResponder(new ResponderHelper($this->translationService, null), $logger);

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('branches.list.section', $output);
        $this->assertStringContainsString('branches.list.fetching_local', $output);
        $this->assertStringContainsString('branches.list.note_origin', $output);
    }

    public function testRespondWithColorHelperAppliesFormatting(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($this->anything());
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new Logger($io, []);
        $responder = new BranchListResponder($helper, $logger);
        $row = new BranchListRow('main', 'Active', 'No', '✓', '✗');
        $response = BranchListResponse::success([$row]);

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('branches.list.section', $output);
        $this->assertStringContainsString('branches.list.fetching_local', $output);
    }

    public function testRespondJsonReturnsSerializedRows(): void
    {
        $row = new BranchListRow('feat/test', 'active', 'yes', 'origin/feat/test', '');
        $response = BranchListResponse::success([$row]);
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['rows']);
        $this->assertSame('feat/test', $result->data['rows'][0]['branch']);
    }

    public function testRespondCliReturnsNull(): void
    {
        $response = BranchListResponse::success([]);
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Cli);

        $this->assertNull($result);
    }
}
