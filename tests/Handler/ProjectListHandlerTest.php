<?php

namespace App\Tests\Handler;

use App\DTO\Project;
use App\Handler\ProjectListHandler;
use App\Response\ProjectListResponse;
use App\Tests\CommandTestCase;

class ProjectListHandlerTest extends CommandTestCase
{
    private ProjectListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ProjectListHandler($this->jiraService);
    }

    public function testHandleReturnsSuccessResponseWithProjects(): void
    {
        $project = new Project('PROJ', 'My Project');

        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([$project]);

        $response = $this->handler->handle();

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->projects);
        $this->assertSame($project, $response->projects[0]);
    }

    public function testHandleReturnsSuccessResponseWithEmptyProjects(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([]);

        $response = $this->handler->handle();

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->projects);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle();

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertSame('Jira API error', $response->getError());
        $this->assertEmpty($response->projects);
    }
}
