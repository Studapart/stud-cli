<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\Project;
use App\Enum\IssueTrackerProvider;
use App\Handler\ProjectListHandler;
use App\Response\ProjectListResponse;
use App\Service\IssueTrackerPortSupplier;
use App\Tests\CommandTestCase;

class ProjectListHandlerTest extends CommandTestCase
{
    private ProjectListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $portSupplier = $this->createMock(IssueTrackerPortSupplier::class);
        $portSupplier->method('resolveForProvider')
            ->with('jira', $this->anything())
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);

        $this->handler = new ProjectListHandler(
            $portSupplier,
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['issueTrackerProvider' => 'jira'],
        );
    }

    public function testHandleReturnsSuccessResponseWithProjects(): void
    {
        $project = new Project('PROJ', 'My Project');

        $this->issueTracker->expects($this->once())
            ->method('listTeams')
            ->willReturn([$project]);

        $response = $this->handler->handle('jira');

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->projects);
        $this->assertSame('PROJ', $response->projects[0]->key);
        $this->assertSame('My Project', $response->projects[0]->name);
    }

    public function testHandleReturnsSuccessResponseWithEmptyProjects(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listTeams')
            ->willReturn([]);

        $response = $this->handler->handle('jira');

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->projects);
    }

    public function testHandleFallsBackWhenProviderListEmpty(): void
    {
        $portSupplier = $this->createMock(\App\Service\IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $portSupplier->method('resolveForProvider')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $handler = new ProjectListHandler($portSupplier, [], []);

        $this->issueTracker->expects($this->once())->method('listTeams')->willReturn([]);

        $this->assertTrue($handler->handle(null)->isSuccess());
    }

    public function testHandleReturnsErrorWhenFallbackResolutionFails(): void
    {
        $portSupplier = $this->createMock(IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn([
            'ok' => false,
            'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured'),
        ]);
        $handler = new ProjectListHandler($portSupplier, [], []);

        $response = $handler->handle(null);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorOnApiException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listTeams')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle('jira');

        $this->assertInstanceOf(ProjectListResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'project.list.error_fetch');
        $this->assertSame('Jira API error', $message->parameters['error']);
        $this->assertEmpty($response->projects);
    }

    public function testHandleDualAutoContinuesWhenOneProviderFails(): void
    {
        $linearPort = $this->createMock(\App\Service\IssueTrackerPort::class);
        $linearPort->method('listTeams')->willReturn([new Project('ENG', 'Linear team')]);

        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider) use ($linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => false, 'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured')],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = new ProjectListHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->projects);
        $this->assertTrue($response->hasDiagnostics());
    }

    public function testHandleDualAutoReturnsErrorWhenSingleProviderFetchFails(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listTeams')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle('jira');

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenSingleProviderResolutionFails(): void
    {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturn([
            'ok' => false,
            'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured'),
        ]);
        $handler = new ProjectListHandler($supplier, [], []);

        $response = $handler->handle('jira');

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleDualAutoWarnsWhenOneProviderFetchThrows(): void
    {
        $jiraPort = $this->issueTracker;
        $jiraPort->expects($this->once())->method('listTeams')->willThrowException(new \Exception('jira down'));
        $linearPort = $this->createMock(\App\Service\IssueTrackerPort::class);
        $linearPort->method('listTeams')->willReturn([new Project('ENG', 'Linear team')]);

        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider) use ($jiraPort, $linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => true, 'provider' => 'jira', 'port' => $jiraPort],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = new ProjectListHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(null);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->hasDiagnostics());
    }

    /**
     * @return array<string, mixed>
     */
    private function dualGlobalConfig(): array
    {
        return [
            'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
            'LINEAR_API_KEY' => 'lin',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dualProjectConfig(): array
    {
        return [
            'issueTrackerProvider' => 'auto',
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];
    }
}
