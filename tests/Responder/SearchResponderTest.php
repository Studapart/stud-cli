<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Enum\OutputFormat;
use App\Responder\SearchResponder;
use App\Response\SearchResponse;
use App\Service\ColorHelper;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchResponderTest extends CommandTestCase
{
    private SearchResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService, null);
        $this->responder = new SearchResponder($helper, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ]);
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
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new SearchResponder($helper, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ]);

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
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new SearchResponder($helper, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ]);
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

    public function testRespondJsonReturnsSerializedResults(): void
    {
        $issue = new WorkItem('1', 'PROJ-1', 'Test', 'Open', 'user', '', [], 'Story');
        $response = SearchResponse::success([$issue], 'project = PROJ');
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('project = PROJ', $result->data['jql']);
        $this->assertCount(1, $result->data['issues']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = SearchResponse::error('API error');
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }
}
