<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\DTO\StateChange;
use App\Exception\IssueTrackerException;
use App\Handler\ProjectsWorkflowHandler;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\ProjectsWorkflowNormalizer;
use App\Tests\CommandTestCase;

class ProjectsWorkflowHandlerTest extends CommandTestCase
{
    public function testHandleReturnsJiraWorkflows(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listProjectStateChanges')
            ->with('SCI')
            ->willReturn([
                new StateChange('11', 'Start Progress', 'In Progress'),
            ]);

        $handler = $this->createHandler(
            port: $port,
            resolveResult: ['ok' => true, 'provider' => 'jira', 'port' => $port],
        );
        $response = $handler->handle('SCI');

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->stateChanges);
        $this->assertSame('11', $response->stateChanges[0]['id']);
        $this->assertSame('jira', $response->stateChanges[0]['provider']);
    }

    public function testHandleReturnsLinearWorkflows(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listProjectStateChanges')
            ->with('SCI')
            ->willReturn([
                new StateChange('state-1', 'Todo', null, 'unstarted'),
            ]);

        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            resolveResult: ['ok' => true, 'provider' => 'linear', 'port' => $port],
        );
        $response = $handler->handle('SCI');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('linear', $response->stateChanges[0]['provider']);
    }

    public function testHandleReturnsWarningWhenNoWorkflowsFound(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listProjectStateChanges')
            ->with('SCI')
            ->willReturn([]);

        $response = $this->createHandler(
            port: $port,
            resolveResult: ['ok' => true, 'provider' => 'jira', 'port' => $port],
        )->handle('SCI');

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->stateChanges);
        $this->assertCount(1, $response->getWarnings());
    }

    public function testHandleReturnsErrorWhenProviderAmbiguous(): void
    {
        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            projectConfig: [],
            resolveResult: [
                'ok' => false,
                'error' => MessageRef::key('project.workflow.error_ambiguous_provider'),
            ],
        );

        $response = $handler->handle('SCI');

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenJiraNotConfigured(): void
    {
        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira']],
            resolveResult: [
                'ok' => false,
                'error' => IssueTrackerException::missingJiraConfiguration()->messageRef,
            ],
        );

        $response = $handler->handle('SCI');

        $this->assertFalse($response->isSuccess());
        $this->assertSame(
            'work_item_provider.missing_jira_configuration',
            (string) $response->getErrorMessage(),
        );
    }

    public function testHandleReturnsErrorWhenLinearNotConfigured(): void
    {
        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            resolveResult: [
                'ok' => false,
                'error' => IssueTrackerException::missingLinearApiKey()->messageRef,
            ],
        );

        $response = $handler->handle('SCI');

        $this->assertFalse($response->isSuccess());
        $this->assertSame(
            'work_item_provider.missing_linear_api_key',
            (string) $response->getErrorMessage(),
        );
    }

    public function testHandleReturnsErrorWhenWorkflowFetchFails(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listProjectStateChanges')
            ->with('SCI')
            ->willThrowException(new \RuntimeException('Jira unavailable'));

        $response = $this->createHandler(
            resolveResult: ['ok' => true, 'provider' => 'jira', 'port' => $port],
        )->handle('SCI');

        $this->assertFalse($response->isSuccess());
        $error = $response->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('project.workflow.error_fetch', $error->key);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     * @param array<string, mixed> $resolveResult
     */
    private function createHandler(
        array $globalConfig = ['WORK_ITEM_PROVIDERS' => ['jira']],
        array $projectConfig = [],
        ?IssueTrackerPort $port = null,
        array $resolveResult = [],
    ): ProjectsWorkflowHandler {
        if ($resolveResult === [] && $port !== null) {
            $resolveResult = ['ok' => true, 'provider' => 'jira', 'port' => $port];
        }

        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolve')->willReturn($resolveResult);

        return new ProjectsWorkflowHandler(
            $supplier,
            new ProjectsWorkflowNormalizer(),
            $globalConfig,
            $projectConfig,
        );
    }
}
