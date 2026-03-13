<?php

namespace App\Tests\Response;

use App\Response\ItemUpdateResponse;
use PHPUnit\Framework\TestCase;

class ItemUpdateResponseTest extends TestCase
{
    public function testSuccessResponse(): void
    {
        $response = ItemUpdateResponse::success('SCI-71');

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame('SCI-71', $response->key);
        $this->assertNull($response->skippedOptionalFields);
    }

    public function testSuccessResponseWithSkippedFields(): void
    {
        $response = ItemUpdateResponse::success('SCI-71', ['unknown']);

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['unknown'], $response->skippedOptionalFields);
    }

    public function testSuccessResponseEmptySkippedFieldsIsNull(): void
    {
        $response = ItemUpdateResponse::success('SCI-71', []);

        $this->assertNull($response->skippedOptionalFields);
    }

    public function testErrorResponse(): void
    {
        $response = ItemUpdateResponse::error('Something broke');

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Something broke', $response->getError());
        $this->assertNull($response->key);
        $this->assertNull($response->skippedOptionalFields);
    }
}
