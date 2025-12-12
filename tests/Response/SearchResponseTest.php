<?php

namespace App\Tests\Response;

use App\DTO\WorkItem;
use App\Response\SearchResponse;
use PHPUnit\Framework\TestCase;

class SearchResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task'),
        ];
        $jql = 'project = TPW';

        $response = SearchResponse::success($issues, $jql);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame($issues, $response->issues);
        $this->assertSame($jql, $response->jql);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'Test error';

        $response = SearchResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertEmpty($response->issues);
        $this->assertSame('', $response->jql);
    }
}
