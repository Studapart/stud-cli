<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\ItemListResponder;
use App\Response\ItemListResponse;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListResponderTest extends CommandTestCase
{
    private ItemListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new ItemListResponder($this->translationService);
    }

    public function testRespondReturnsOneOnError(): void
    {
        $response = ItemListResponse::error('Jira API error');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(false);
        $io->expects($this->once())
            ->method('error')
            ->with($this->callback(function ($message) {
                return is_string($message) && str_contains($message, 'Jira API error');
            }));

        $result = $this->responder->respond($io, $response);

        $this->assertSame(1, $result);
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

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
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

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
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

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
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

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
    }
}
