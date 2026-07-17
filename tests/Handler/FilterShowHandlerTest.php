<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Handler\FilterShowHandler;
use App\Response\FilterShowResponse;
use App\Service\IssueTrackerPortSupplier;
use App\Tests\CommandTestCase;

class FilterShowHandlerTest extends CommandTestCase
{
    private FilterShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $portSupplier = $this->createMock(IssueTrackerPortSupplier::class);
        $portSupplier->method('resolveForProvider')
            ->with('jira', $this->anything())
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);

        $this->handler = new FilterShowHandler(
            $portSupplier,
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['issueTrackerProvider' => 'jira'],
        );
    }

    public function testHandleReturnsSuccessResponseWithIssues(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            'description',
            ['tests'],
            'Task'
        );

        $this->issueTracker->expects($this->once())
            ->method('runFilterOrView')
            ->with('My Filter')
            ->willReturn([$issue]);

        $response = $this->handler->handle('My Filter', 'jira');

        $this->assertInstanceOf(FilterShowResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('My Filter', $response->filterName);
        $this->assertCount(1, $response->issues);
        $this->assertSame($issue, $response->issues[0]);
    }

    public function testHandleReturnsErrorWhenNoMatches(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('runFilterOrView')
            ->with('My Filter')
            ->willReturn([]);

        $response = $this->handler->handle('My Filter', 'jira');

        $this->assertInstanceOf(FilterShowResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'filter.show.error_not_found', ['filterName' => 'My Filter']);
    }

    public function testHandleFallsBackWhenProviderListEmpty(): void
    {
        $portSupplier = $this->createMock(\App\Service\IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $portSupplier->method('resolveForProvider')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $handler = new FilterShowHandler($portSupplier, [], []);

        $this->issueTracker->expects($this->once())->method('runFilterOrView')->willReturn([]);

        $response = $handler->handle('Missing', null);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenFallbackResolutionFails(): void
    {
        $portSupplier = $this->createMock(IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn([
            'ok' => false,
            'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured'),
        ]);
        $handler = new FilterShowHandler($portSupplier, [], []);

        $response = $handler->handle('My Filter', null);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenSingleProviderResolutionFails(): void
    {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturn([
            'ok' => false,
            'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured'),
        ]);
        $handler = new FilterShowHandler($supplier, [], []);

        $response = $handler->handle('My Filter', 'jira');

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorOnApiException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('runFilterOrView')
            ->with('My Filter')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle('My Filter', 'jira');

        $this->assertInstanceOf(FilterShowResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'filter.show.error_fetch');
        $this->assertSame('Jira API error', $message->parameters['error']);
        $this->assertEmpty($response->issues);
    }

    public function testHandleDualAutoContinuesWhenOneProviderFails(): void
    {
        $issue = new WorkItem('1', 'ENG-1', 'Linear issue', 'Open', null, '', [], 'Story');
        $linearPort = $this->createMock(\App\Service\IssueTrackerPort::class);
        $linearPort->method('runFilterOrView')->willReturn([$issue]);

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

        $handler = new FilterShowHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
        );

        $response = $handler->handle('Shared Filter', null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->issues);
        $this->assertTrue($response->hasDiagnostics());
    }

    public function testHandleDualAutoSkipsProviderFetchExceptionWhenOtherMatches(): void
    {
        $jiraPort = $this->issueTracker;
        $jiraPort->expects($this->once())
            ->method('runFilterOrView')
            ->willThrowException(new \Exception('Jira API error'));

        $linearPort = $this->createMock(\App\Service\IssueTrackerPort::class);
        $issue = new WorkItem('2', 'ENG-2', 'Linear issue', 'Open', null, '', [], 'Story');
        $linearPort->method('runFilterOrView')->willReturn([$issue]);

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

        $handler = new FilterShowHandler(
            $supplier,
            $this->dualGlobalConfig(),
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
        );

        $response = $handler->handle('Shared Filter', null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->issues);
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
