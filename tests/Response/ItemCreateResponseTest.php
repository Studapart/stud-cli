<?php

namespace App\Tests\Response;

use App\Response\ItemCreateResponse;
use PHPUnit\Framework\TestCase;

class ItemCreateResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $response = ItemCreateResponse::success('PROJ-1', 'https://jira.example.com/rest/api/3/issue/1');

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame('PROJ-1', $response->key);
        $this->assertSame('https://jira.example.com/rest/api/3/issue/1', $response->self);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'Test error';
        $response = ItemCreateResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertNull($response->key);
        $this->assertNull($response->self);
    }

    public function testSuccessWithSkippedOptionalFields(): void
    {
        $response = ItemCreateResponse::success('PROJ-1', 'https://jira.example.com/issue/1', ['labels', 'time original estimate']);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
        $this->assertSame(['labels', 'time original estimate'], $response->skippedOptionalFields);
    }
}
