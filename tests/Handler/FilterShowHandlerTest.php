<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\FilterShowHandler;
use App\Response\FilterShowResponse;
use App\Tests\CommandTestCase;

class FilterShowHandlerTest extends CommandTestCase
{
    private FilterShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new FilterShowHandler($this->workItemProvider);
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

        $this->workItemProvider->expects($this->once())
            ->method('runFilterOrView')
            ->with('My Filter')
            ->willReturn([$issue]);

        $response = $this->handler->handle('My Filter');

        $this->assertInstanceOf(FilterShowResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('My Filter', $response->filterName);
        $this->assertCount(1, $response->issues);
        $this->assertSame($issue, $response->issues[0]);
    }

    public function testHandleReturnsSuccessResponseWithEmptyIssues(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('runFilterOrView')
            ->with('My Filter')
            ->willReturn([]);

        $response = $this->handler->handle('My Filter');

        $this->assertInstanceOf(FilterShowResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('My Filter', $response->filterName);
        $this->assertEmpty($response->issues);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('runFilterOrView')
            ->with('My Filter')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle('My Filter');

        $this->assertInstanceOf(FilterShowResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertSame('Jira API error', $response->getError());
        $this->assertEmpty($response->issues);
    }
}
