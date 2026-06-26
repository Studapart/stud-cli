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

        $this->handler = new ProjectListHandler($this->issueTracker);
    }

    public function testHandleReturnsSuccessResponseWithProjects(): void
    {
        $project = new Project('PROJ', 'My Project');

        $this->issueTracker->expects($this->once())
            ->method('listTeams')
            ->willReturn([$project]);

        $response = $this->handler->handle();

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->projects);
        $this->assertSame($project, $response->projects[0]);
    }

    public function testHandleReturnsSuccessResponseWithEmptyProjects(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listTeams')
            ->willReturn([]);

        $response = $this->handler->handle();

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->projects);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listTeams')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle();

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'project.list.error_fetch');
        $this->assertSame('Jira API error', $message->parameters['error']);
        $this->assertEmpty($response->projects);
    }
}
