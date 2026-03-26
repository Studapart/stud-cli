<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\DTO\BranchListRow;
use App\Response\BranchListResponse;
use PHPUnit\Framework\TestCase;

class BranchListResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $rows = [
            new BranchListRow('feat/PROJ-123', 'Active', 'No', '✓', '✗'),
        ];

        $response = BranchListResponse::success($rows);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame($rows, $response->rows);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'Git error';

        $response = BranchListResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertEmpty($response->rows);
    }
}
