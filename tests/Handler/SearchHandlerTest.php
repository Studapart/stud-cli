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

        $this->handler = new SearchHandler($this->issueTracker);
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

        $this->issueTracker->expects($this->once())
            ->method('search')
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
        $this->issueTracker->expects($this->once())
            ->method('search')
            ->with('project = TPW')
            ->willReturn([]);

        $response = $this->handler->handle('project = TPW');

        $this->assertInstanceOf(SearchResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('project = TPW', $response->jql);
        $this->assertEmpty($response->issues);
    }

    public function testHandleReturnsLinearTermSearchResults(): void
    {
        $issue = new WorkItem(
            'issue-1',
            'SCI-42',
            'Login bug',
            'Todo',
            'Ada',
            'description',
            ['Bug'],
            'Bug',
            [],
            'High',
            null,
            [],
            'https://linear.app/studapart/issue/SCI-42',
        );

        $this->issueTracker->expects($this->once())
            ->method('search')
            ->with('login bug')
            ->willReturn([$issue]);

        $response = $this->handler->handle('login bug');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('login bug', $response->jql);
        $this->assertCount(1, $response->issues);
        $this->assertSame('SCI-42', $response->issues[0]->key);
        $this->assertSame('https://linear.app/studapart/issue/SCI-42', $response->issues[0]->url);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('search')
            ->with('project = TPW')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle('project = TPW');

        $this->assertInstanceOf(SearchResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'search.error_search');
        $this->assertSame('Jira API error', $message->parameters['error']);
        $this->assertEmpty($response->issues);
    }
}
