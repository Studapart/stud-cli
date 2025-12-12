<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\FilterShowResponder;
use App\Response\FilterShowResponse;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterShowResponderTest extends CommandTestCase
{
    private FilterShowResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new FilterShowResponder($this->translationService, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ]);
    }

    public function testRespondReturnsOneOnError(): void
    {
        $response = FilterShowResponse::error('Jira API error');
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
        $response = FilterShowResponse::success([], 'My Filter');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(false);
        $io->expects($this->once())
            ->method('note')
            ->with($this->callback(function ($message) {
                return is_string($message) && str_contains($message, 'My Filter');
            }));

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = FilterShowResponse::success([$issue], 'My Filter');
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
        $response = FilterShowResponse::success([$issue], 'My Filter');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('filter.show.jql_query'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
    }
}
