<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\ProjectsWorkflowHandler;
use App\Service\IssueTrackerResolver;
use App\Service\LinearMetadataClient;
use App\Service\ProjectsWorkflowNormalizer;
use App\Tests\CommandTestCase;

class ProjectsWorkflowHandlerTest extends CommandTestCase
{
    public function testHandleReturnsJiraWorkflows(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProjectTransitions')
            ->with('SCI')
            ->willReturn([
                ['id' => 11, 'name' => 'Start Progress', 'to' => ['name' => 'In Progress']],
            ]);

        $handler = $this->createHandler();
        $response = $handler->handle('SCI');

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->stateChanges);
        $this->assertSame('11', $response->stateChanges[0]['id']);
        $this->assertSame('jira', $response->stateChanges[0]['provider']);
    }

    public function testHandleReturnsLinearWorkflows(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->expects($this->once())
            ->method('getTeamWorkflowStates')
            ->with('SCI')
            ->willReturn([
                ['id' => 'state-1', 'name' => 'Todo', 'type' => 'unstarted'],
            ]);

        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            linearClient: $linearClient,
        );
        $response = $handler->handle('SCI');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('linear', $response->stateChanges[0]['provider']);
    }

    public function testHandleReturnsWarningWhenNoWorkflowsFound(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProjectTransitions')
            ->with('SCI')
            ->willReturn([]);

        $response = $this->createHandler()->handle('SCI');

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->stateChanges);
        $this->assertCount(1, $response->getWarnings());
    }

    public function testHandleReturnsErrorWhenProviderAmbiguous(): void
    {
        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            projectConfig: [],
        );

        $response = $handler->handle('SCI');

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenJiraApiClientMissing(): void
    {
        $handler = new ProjectsWorkflowHandler(
            null,
            null,
            new IssueTrackerResolver(),
            new ProjectsWorkflowNormalizer(),
            ['WORK_ITEM_PROVIDERS' => ['jira']],
            [],
        );

        $response = $handler->handle('SCI');

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Jira is not configured.', $response->getError());
    }

    public function testHandleReturnsErrorWhenLinearClientMissing(): void
    {
        $handler = new ProjectsWorkflowHandler(
            $this->jiraApiClient,
            null,
            new IssueTrackerResolver(),
            new ProjectsWorkflowNormalizer(),
            ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            [],
        );

        $response = $handler->handle('SCI');

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Linear is not configured.', $response->getError());
    }

    public function testHandleReturnsErrorWhenWorkflowFetchFails(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProjectTransitions')
            ->with('SCI')
            ->willThrowException(new \RuntimeException('Jira unavailable'));

        $response = $this->createHandler()->handle('SCI');

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Jira unavailable', $response->getError());
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function createHandler(
        array $globalConfig = ['WORK_ITEM_PROVIDERS' => ['jira']],
        array $projectConfig = [],
        ?LinearMetadataClient $linearClient = null,
    ): ProjectsWorkflowHandler {
        return new ProjectsWorkflowHandler(
            $this->jiraApiClient,
            $linearClient,
            new IssueTrackerResolver(),
            new ProjectsWorkflowNormalizer(),
            $globalConfig,
            $projectConfig,
        );
    }
}
