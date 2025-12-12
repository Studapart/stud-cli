<?php

namespace App\Tests\Response;

use App\DTO\WorkItem;
use App\Response\ItemListResponse;
use PHPUnit\Framework\TestCase;

class ItemListResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task'),
        ];
        $all = true;
        $project = 'TPW';

        $response = ItemListResponse::success($issues, $all, $project);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame($issues, $response->issues);
        $this->assertTrue($response->all);
        $this->assertSame($project, $response->project);
    }

    public function testSuccessFactoryMethodWithNullProject(): void
    {
        $issues = [];
        $all = false;

        $response = ItemListResponse::success($issues, $all, null);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->all);
        $this->assertNull($response->project);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'Test error';

        $response = ItemListResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertEmpty($response->issues);
        $this->assertFalse($response->all);
        $this->assertNull($response->project);
    }
}
