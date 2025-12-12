<?php

namespace App\Tests\Response;

use App\DTO\WorkItem;
use App\Response\FilterShowResponse;
use PHPUnit\Framework\TestCase;

class FilterShowResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task'),
            new WorkItem('2', 'TPW-2', 'Title 2', 'In Progress', 'User', 'desc', [], 'Task'),
        ];
        $filterName = 'My Filter';

        $response = FilterShowResponse::success($issues, $filterName);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame($issues, $response->issues);
        $this->assertSame($filterName, $response->filterName);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'Test error';

        $response = FilterShowResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertEmpty($response->issues);
        $this->assertSame('', $response->filterName);
    }
}
