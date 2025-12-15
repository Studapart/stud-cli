<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\SearchResponder;
use App\Response\SearchResponse;
use App\Service\ColorHelper;
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
        ], null);
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

        $this->responder->respond($io, $response);
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

        $this->responder->respond($io, $response);
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

        $this->responder->respond($io, $response);
    }

    public function testRespondShowsVerboseOutputWithoutColorHelperUsesFallback(): void
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
            ->with($this->stringContains('<fg=gray>'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondShowsVerboseOutputWithColorHelper(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new SearchResponder($this->translationService, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $colorHelper);

        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = SearchResponse::success([$issue], 'project = TPW');
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
            ->with($this->stringContains('search.jql_query'));
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $responder->respond($io, $response);
    }

    public function testRespondWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new SearchResponder($this->translationService, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $colorHelper);
        $response = SearchResponse::success([], 'project = TPW');
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
