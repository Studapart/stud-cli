<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\ItemListResponder;
use App\Response\ItemListResponse;
use App\Service\ColorHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListResponderTest extends CommandTestCase
{
    private ItemListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new ItemListResponder($this->translationService, null);
    }

    public function testRespondReturnsZeroOnEmptyIssues(): void
    {
        $response = ItemListResponse::success([], false, null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(false);
        $io->expects($this->once())
            ->method('note')
            ->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(false);
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondShowsVerboseOutputWhenVerbose(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('JQL Query'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondShowsVerboseOutputWithoutColorHelperUsesFallback(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('<fg=gray>'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondShowsVerboseOutputWithColorHelper(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new ItemListResponder($this->translationService, $colorHelper);

        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeast(2))
            ->method('format')
            ->willReturnCallback(function ($color, $text) {
                // First call is for section_title, second is for comment
                return "<{$color}>{$text}</>";
            });

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('JQL Query'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $responder->respond($io, $response);
    }

    public function testRespondShowsVerboseOutputWithProject(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, 'MYPROJ');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('MYPROJ'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new ItemListResponder($this->translationService, $colorHelper);
        $response = ItemListResponse::success([], false, null);
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(false);
        $io->expects($this->once())
            ->method('note');

        $responder->respond($io, $response);
    }
}
