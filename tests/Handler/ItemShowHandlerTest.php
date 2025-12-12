<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemShowHandler;
use App\Response\ItemShowResponse;
use App\Tests\CommandTestCase;
use RuntimeException;

class ItemShowHandlerTest extends CommandTestCase
{
    private ItemShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ItemShowHandler($this->jiraService);
    }

    public function testHandleReturnsSuccessResponse(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            'This is a test description.',
            ['tests'],
            'Task',
            [],
            '<p>This is a test description.</p>'
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true)
            ->willReturn($issue);

        $response = $this->handler->handle('TPW-35');

        $this->assertInstanceOf(ItemShowResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame($issue, $response->issue);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true)
            ->willThrowException(new RuntimeException('Issue not found'));

        $response = $this->handler->handle('TPW-35');

        $this->assertInstanceOf(ItemShowResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertSame('Issue not found', $response->getError());
        $this->assertNull($response->issue);
    }

    public function testHandleNormalizesKeyToUppercase(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true)
            ->willReturn($issue);

        $response = $this->handler->handle('tpw-35');

        $this->assertTrue($response->isSuccess());
        $this->assertSame($issue, $response->issue);
    }
}
