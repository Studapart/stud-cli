<?php

namespace App\Tests\Response;

use App\DTO\WorkItem;
use App\Response\ItemShowResponse;
use PHPUnit\Framework\TestCase;

class ItemShowResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $response = ItemShowResponse::success($issue);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame($issue, $response->issue);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'Test error';

        $response = ItemShowResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertNull($response->issue);
    }
}
