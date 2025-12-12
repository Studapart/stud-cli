<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\SearchHandler;
use App\Response\SearchResponse;
use App\Tests\CommandTestCase;

class SearchHandlerTest extends CommandTestCase
{
    private SearchHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new SearchHandler($this->jiraService);
    }

    public function testHandleReturnsSuccessResponseWithIssues(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('project = TPW')
            ->willReturn([$issue]);

        $response = $this->handler->handle('project = TPW');

        $this->assertInstanceOf(SearchResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('project = TPW', $response->jql);
        $this->assertCount(1, $response->issues);
        $this->assertSame($issue, $response->issues[0]);
    }

    public function testHandleReturnsSuccessResponseWithEmptyIssues(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('project = TPW')
            ->willReturn([]);

        $response = $this->handler->handle('project = TPW');

        $this->assertInstanceOf(SearchResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('project = TPW', $response->jql);
        $this->assertEmpty($response->issues);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('project = TPW')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle('project = TPW');

        $this->assertInstanceOf(SearchResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertSame('Jira API error', $response->getError());
        $this->assertEmpty($response->issues);
    }
}
