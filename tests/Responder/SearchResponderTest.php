<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\SearchResponder;
use App\Response\SearchResponse;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchResponderTest extends CommandTestCase
{
    private SearchResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new SearchResponder($this->translationService, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ]);
    }

    public function testRespondReturnsOneOnError(): void
    {
        $response = SearchResponse::error('Jira API error');
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
        $response = SearchResponse::success([], 'project = TPW');
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
        $response = SearchResponse::success([$issue], 'project = TPW');
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
        $response = SearchResponse::success([$issue], 'project = TPW');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('search.jql_query'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
    }
}
