<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemListHandler;
use App\Response\ItemListResponse;
use App\Tests\CommandTestCase;

class ItemListHandlerTest extends CommandTestCase
{
    private ItemListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ItemListHandler($this->jiraService);
    }

    public function testHandleDefaultReturnsSuccessResponse(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue]);

        $response = $this->handler->handle(false, null, null);

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->all);
        $this->assertNull($response->project);
        $this->assertCount(1, $response->issues);
        $this->assertSame($issue, $response->issues[0]);
    }

    public function testHandleAllReturnsSuccessResponse(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue]);

        $response = $this->handler->handle(true, null, null);

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->all);
        $this->assertNull($response->project);
    }

    public function testHandleProjectReturnsSuccessResponse(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') AND project = MYPROJ ORDER BY updated DESC')
            ->willReturn([$issue]);

        $response = $this->handler->handle(false, 'MYPROJ', null);

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('MYPROJ', $response->project);
    }

    public function testHandleWithSortByKeySortsIssues(): void
    {
        $issue1 = new WorkItem('1000', 'TPW-100', 'Feature A', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');
        $issue2 = new WorkItem('1001', 'TPW-10', 'Feature B', 'To Do', 'Jane Doe', 'description', ['tests'], 'Task');
        $issue3 = new WorkItem('1002', 'TPW-35', 'Feature C', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->willReturn([$issue1, $issue2, $issue3]);

        $response = $this->handler->handle(false, null, 'Key');

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('TPW-10', $response->issues[0]->key);
        $this->assertSame('TPW-100', $response->issues[1]->key);
        $this->assertSame('TPW-35', $response->issues[2]->key);
    }

    public function testHandleWithSortByStatusSortsIssues(): void
    {
        $issue1 = new WorkItem('1000', 'TPW-35', 'Feature A', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');
        $issue2 = new WorkItem('1001', 'TPW-10', 'Feature B', 'To Do', 'Jane Doe', 'description', ['tests'], 'Task');
        $issue3 = new WorkItem('1002', 'TPW-100', 'Feature C', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->willReturn([$issue1, $issue2, $issue3]);

        $response = $this->handler->handle(false, null, 'Status');

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('In Progress', $response->issues[0]->status);
        $this->assertSame('In Progress', $response->issues[1]->status);
        $this->assertSame('To Do', $response->issues[2]->status);
    }

    public function testHandleReturnsSuccessResponseWithEmptyIssues(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->willReturn([]);

        $response = $this->handler->handle(false, null, null);

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->issues);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle(false, null, null);

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertSame('Jira API error', $response->getError());
        $this->assertEmpty($response->issues);
    }
}
