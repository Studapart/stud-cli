<?php

namespace App\Tests\Response;

use App\DTO\Project;
use App\Response\ProjectListResponse;
use PHPUnit\Framework\TestCase;

class ProjectListResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $projects = [
            new Project('TPW', 'Test Project'),
            new Project('PROJ', 'Another Project'),
        ];

        $response = ProjectListResponse::success($projects);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame($projects, $response->projects);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'Test error';

        $response = ProjectListResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertEmpty($response->projects);
    }
}
