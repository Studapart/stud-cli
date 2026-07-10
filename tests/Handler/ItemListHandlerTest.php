<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Handler\ItemListHandler;
use App\Response\ItemListResponse;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Tests\CommandTestCase;

class ItemListHandlerTest extends CommandTestCase
{
    private ItemListHandler $handler;

    /** @var array<string, mixed> */
    private array $globalConfig = [
        'ISSUE_TRACKER_PROVIDERS' => ['jira'],
        'JIRA_URL' => 'https://example.atlassian.net',
        'JIRA_EMAIL' => 'user@example.com',
        'JIRA_API_TOKEN' => 'token',
    ];

    /** @var array<string, mixed> */
    private array $projectConfig = ['issueTrackerProvider' => 'jira', 'projectKey' => 'SCI'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ItemListHandler(
            $this->createPortSupplier($this->issueTracker),
            $this->globalConfig,
            $this->projectConfig,
        );
    }

    public function testHandleDefaultReturnsSuccessResponse(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', true)
            ->willReturn([$issue]);

        $response = $this->handler->handle(false, null, null);

        $this->assertInstanceOf(ItemListResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->all);
        $this->assertNull($response->project);
        $this->assertCount(1, $response->issues);
        $this->assertSame($issue, $response->issues[0]);
    }

    public function testHandleAllReturnsSuccessResponse(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', false)
            ->willReturn([$issue]);

        $response = $this->handler->handle(true, null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->all);
    }

    public function testHandleProjectReturnsSuccessResponse(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('MYPROJ', true)
            ->willReturn([$issue]);

        $response = $this->handler->handle(false, 'MYPROJ', null);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('MYPROJ', $response->project);
    }

    public function testHandleWithSortByKeySortsIssues(): void
    {
        $issue1 = new WorkItem('1000', 'TPW-100', 'Feature A', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');
        $issue2 = new WorkItem('1001', 'TPW-10', 'Feature B', 'To Do', 'Jane Doe', 'description', ['tests'], 'Task');
        $issue3 = new WorkItem('1002', 'TPW-35', 'Feature C', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');

        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', true)
            ->willReturn([$issue1, $issue2, $issue3]);

        $response = $this->handler->handle(false, null, 'Key');

        $this->assertSame('TPW-10', $response->issues[0]->key);
        $this->assertSame('TPW-100', $response->issues[1]->key);
        $this->assertSame('TPW-35', $response->issues[2]->key);
    }

    public function testHandleWithSortByStatusSortsIssues(): void
    {
        $issue1 = new WorkItem('1000', 'TPW-35', 'Feature A', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');
        $issue2 = new WorkItem('1001', 'TPW-10', 'Feature B', 'To Do', 'Jane Doe', 'description', ['tests'], 'Task');
        $issue3 = new WorkItem('1002', 'TPW-100', 'Feature C', 'In Progress', 'John Doe', 'description', ['tests'], 'Task');

        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', true)
            ->willReturn([$issue1, $issue2, $issue3]);

        $response = $this->handler->handle(false, null, 'Status');

        $this->assertSame('In Progress', $response->issues[0]->status);
        $this->assertSame('In Progress', $response->issues[1]->status);
        $this->assertSame('To Do', $response->issues[2]->status);
    }

    public function testHandleReturnsSuccessResponseWithEmptyIssues(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', true)
            ->willReturn([]);

        $response = $this->handler->handle(false, null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->issues);
    }

    public function testHandleReturnsErrorResponseOnException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', true)
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle(false, null, null);

        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'item.list.error_fetch');
        $this->assertSame('Jira API error', $message->parameters['error']);
    }

    public function testHandleDualAutoAggregateMergesProviders(): void
    {
        $jiraPort = $this->createMock(IssueTrackerPort::class);
        $linearPort = $this->createMock(IssueTrackerPort::class);
        $jiraIssue = new WorkItem('1', 'SCI-1', 'Jira issue', 'Open', null, '', [], 'Story');
        $linearIssue = new WorkItem('2', 'ENG-1', 'Linear issue', 'Open', null, '', [], 'Story');

        $jiraPort->method('listAssignedActive')->willReturn([$jiraIssue]);
        $linearPort->method('listAssignedActive')->willReturn([$linearIssue]);

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

        $handler = new ItemListHandler(
            $supplier,
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            [
                'issueTrackerProvider' => 'auto',
                'projectKey' => 'SCI',
                'linearTeamKey' => 'ENG',
            ],
        );

        $response = $handler->handle(false, null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->multiProvider);
        $this->assertCount(2, $response->issues);
        $this->assertSame(['jira', 'linear'], $response->issueProviders);
    }

    public function testHandleProviderOverrideUsesExplicitProvider(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('ENG', true)
            ->willReturn([]);

        $handler = new ItemListHandler(
            $this->createLinearPortSupplier($this->issueTracker),
            $this->globalConfig,
            [
                'issueTrackerProvider' => 'auto',
                'projectKey' => 'SCI',
                'linearTeamKey' => 'ENG',
            ],
        );

        $response = $handler->handle(false, null, null, IssueTrackerProvider::Linear->value);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleFallsBackToPortSupplierWhenProvidersUnresolved(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', true)
            ->willReturn([]);

        $handler = new ItemListHandler(
            $this->createPortSupplier($this->issueTracker),
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI'],
        );

        $response = $handler->handle(false, null, null);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenFallbackResolveFails(): void
    {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolve')->willReturn(['ok' => false, 'error' => 'missing credentials']);
        $supplier->method('resolveForProvider')->willReturn(['ok' => false, 'error' => 'missing credentials']);

        $handler = new ItemListHandler(
            $supplier,
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI'],
        );

        $response = $handler->handle(false, null, null);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('missing credentials', $response->getErrorMessage());
    }

    public function testHandleDualAutoSkipsDuplicateKeys(): void
    {
        $jiraPort = $this->createMock(IssueTrackerPort::class);
        $linearPort = $this->createMock(IssueTrackerPort::class);
        $sharedIssue = new WorkItem('1', 'SCI-1', 'Shared', 'Open', null, '', [], 'Story');

        $jiraPort->method('listAssignedActive')->willReturn([$sharedIssue]);
        $linearPort->method('listAssignedActive')->willReturn([$sharedIssue]);

        $handler = new ItemListHandler(
            $this->createDualPortSupplier($jiraPort, $linearPort),
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->issues);
    }

    public function testHandleDualAutoContinuesWhenOneProviderFails(): void
    {
        $jiraPort = $this->createMock(IssueTrackerPort::class);
        $linearPort = $this->createMock(IssueTrackerPort::class);
        $linearIssue = new WorkItem('2', 'ENG-1', 'Linear issue', 'Open', null, '', [], 'Story');

        $jiraPort->method('listAssignedActive')->willThrowException(new \Exception('jira down'));
        $linearPort->method('listAssignedActive')->willReturn([$linearIssue]);

        $handler = new ItemListHandler(
            $this->createDualPortSupplier($jiraPort, $linearPort),
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->issues);
        $this->assertTrue($response->hasDiagnostics());
    }

    public function testHandleDualAutoContinuesWhenOneProviderCannotResolve(): void
    {
        $linearPort = $this->createMock(IssueTrackerPort::class);
        $linearIssue = new WorkItem('2', 'ENG-1', 'Linear issue', 'Open', null, '', [], 'Story');
        $linearPort->method('listAssignedActive')->willReturn([$linearIssue]);

        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider) use ($linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => false, 'error' => 'jira missing'],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = new ItemListHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->issues);
        $this->assertTrue($response->hasDiagnostics());
    }

    public function testHandleProjectScopeUsesDualAutoWhenScopeMatchesBoth(): void
    {
        $jiraPort = $this->createMock(IssueTrackerPort::class);
        $linearPort = $this->createMock(IssueTrackerPort::class);
        $jiraIssue = new WorkItem('1', 'SCI-1', 'Jira issue', 'Open', null, '', [], 'Story');
        $linearIssue = new WorkItem('2', 'SCI-2', 'Linear issue', 'Open', null, '', [], 'Story');
        $jiraPort->method('listAssignedActive')->willReturn([$jiraIssue]);
        $linearPort->method('listAssignedActive')->willReturn([$linearIssue]);

        $handler = new ItemListHandler(
            $this->createDualPortSupplier($jiraPort, $linearPort),
            $this->dualGlobalConfig(),
            [
                'issueTrackerProvider' => 'auto',
                'projectKey' => 'SCI',
                'linearTeamKey' => 'SCI',
            ],
        );

        $response = $handler->handle(false, 'SCI', null);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->multiProvider);
        $this->assertCount(2, $response->issues);
    }

    public function testHandleUsesLinearTeamKeyWhenNoProjectOverride(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('ENG', true)
            ->willReturn([]);

        $handler = new ItemListHandler(
            $this->createLinearPortSupplier($this->issueTracker),
            $this->dualGlobalConfig(),
            [
                'issueTrackerProvider' => 'linear',
                'projectKey' => 'SCI',
                'linearTeamKey' => 'ENG',
            ],
        );

        $response = $handler->handle(false, null, null);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleProviderOverrideReturnsErrorWhenResolveFails(): void
    {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturn(['ok' => false, 'error' => 'linear unavailable']);

        $handler = new ItemListHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, null, null, IssueTrackerProvider::Linear->value);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('linear unavailable', $response->getErrorMessage());
    }

    public function testHandleUnknownProjectScopeFallsBackWhenDualAutoUnavailable(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('UNKNOWN', true)
            ->willReturn([]);

        $handler = new ItemListHandler(
            $this->createPortSupplier($this->issueTracker),
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI'],
        );

        $response = $handler->handle(false, 'UNKNOWN', null);

        $this->assertTrue($response->isSuccess());
    }

    public function testShouldDualAutoAggregateFalseWhenProviderExplicit(): void
    {
        $method = new \ReflectionMethod(ItemListHandler::class, 'shouldDualAutoAggregate');
        \App\Util\ReflectionAccessor::ensureAccessible($method);
        $handler = new ItemListHandler(
            $this->createPortSupplier($this->issueTracker),
            $this->dualGlobalConfig(),
            ['issueTrackerProvider' => 'jira', 'projectKey' => 'SCI'],
        );

        $this->assertFalse($method->invoke($handler));
    }

    public function testHandleDualAutoReturnsErrorWhenAllProvidersFail(): void
    {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static fn (string $provider): array => match ($provider) {
                IssueTrackerProvider::Jira->value => ['ok' => false, 'error' => 'jira missing'],
                IssueTrackerProvider::Linear->value => ['ok' => false, 'error' => 'linear missing'],
                default => ['ok' => false, 'error' => 'unknown'],
            },
        );

        $handler = new ItemListHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, null, null);

        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'item.list.error_fetch');
        $this->assertSame('all providers failed', $message->parameters['error']);
    }

    public function testHandleProjectScopeResolvesJiraOnly(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('SCI', true)
            ->willReturn([]);

        $handler = new ItemListHandler(
            $this->createPortSupplier($this->issueTracker),
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, 'SCI', null);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleProjectScopeResolvesLinearOnly(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listAssignedActive')
            ->with('ENG', true)
            ->willReturn([]);

        $handler = new ItemListHandler(
            $this->createLinearPortSupplier($this->issueTracker),
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, 'ENG', null);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleSortPreservesProviderMapping(): void
    {
        $jiraPort = $this->createMock(IssueTrackerPort::class);
        $linearPort = $this->createMock(IssueTrackerPort::class);
        $jiraIssue = new WorkItem('1', 'SCI-2', 'Jira', 'Open', null, '', [], 'Story');
        $linearIssue = new WorkItem('2', 'ENG-1', 'Linear', 'Open', null, '', [], 'Story');

        $jiraPort->method('listAssignedActive')->willReturn([$jiraIssue]);
        $linearPort->method('listAssignedActive')->willReturn([$linearIssue]);

        $handler = new ItemListHandler(
            $this->createDualPortSupplier($jiraPort, $linearPort),
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle(false, null, 'Key');

        $this->assertSame(['ENG-1', 'SCI-2'], array_map(static fn (WorkItem $issue): string => $issue->key, $response->issues));
        $this->assertSame(['linear', 'jira'], $response->issueProviders);
    }

    private function createPortSupplier(IssueTrackerPort $port): IssueTrackerPortSupplier
    {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolve')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $port]);
        $supplier->method('resolveForProvider')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $port]);

        return $supplier;
    }

    private function createLinearPortSupplier(IssueTrackerPort $port): IssueTrackerPortSupplier
    {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolve')->willReturn(['ok' => true, 'provider' => 'linear', 'port' => $port]);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static fn (string $provider) => match ($provider) {
                IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $port],
                default => ['ok' => false, 'error' => 'unsupported'],
            },
        );

        return $supplier;
    }

    private function createDualPortSupplier(IssueTrackerPort $jiraPort, IssueTrackerPort $linearPort): IssueTrackerPortSupplier
    {
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

        return $supplier;
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
