<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\Filter;
use App\Handler\FilterListHandler;
use App\Tests\CommandTestCase;

class FilterListHandlerTest extends CommandTestCase
{
    private FilterListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new FilterListHandler($this->jiraService, $this->translationService);
    }

    public function testHandleReturnsSuccessWithFilters(): void
    {
        $filter = new Filter('My Filter', 'Filter description');

        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([$filter]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->filters);
        $this->assertSame('My Filter', $response->filters[0]->name);
        $this->assertSame('Filter description', $response->filters[0]->description);
    }

    public function testHandleReturnsSuccessWithEmptyFilters(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(0, $response->filters);
    }

    public function testHandleReturnsErrorOnJiraServiceException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertNotNull($response->getError());
        $this->assertStringContainsString('Jira API error', $response->getError());
        $this->assertCount(0, $response->filters);
    }

    public function testHandleSortsFiltersByName(): void
    {
        $filter1 = new Filter('Zebra Filter', 'Description 1');
        $filter2 = new Filter('Alpha Filter', 'Description 2');
        $filter3 = new Filter('Beta Filter', 'Description 3');

        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([$filter1, $filter2, $filter3]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(3, $response->filters);
        $this->assertSame('Alpha Filter', $response->filters[0]->name);
        $this->assertSame('Beta Filter', $response->filters[1]->name);
        $this->assertSame('Zebra Filter', $response->filters[2]->name);
    }

    public function testHandleWithNullDescription(): void
    {
        $filter = new Filter('My Filter', null);

        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([$filter]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->filters);
        $this->assertSame('My Filter', $response->filters[0]->name);
        $this->assertNull($response->filters[0]->description);
    }
}
