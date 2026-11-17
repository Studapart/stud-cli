<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Handler\FilterShowHandler;
use App\Response\FilterShowResponse;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerProviderFanOut;
use App\Service\IssueTrackerProvidersQueryResolver;
use App\Tests\CommandTestCase;

class FilterShowHandlerTest extends CommandTestCase
{
    private FilterShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issueTracker = $this->createStub(IssueTrackerPort::class);
        $this->bindSubject();
    }

    public function testHandleReturnsSuccessResponseWithIssues(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

        $portSupplier = $this->createStub(\App\Service\IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $portSupplier->method('resolveForProvider')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $handler = $this->createHandler($portSupplier, [], []);

        $this->issueTracker->expects($this->once())->method('runFilterOrView')->willReturn([]);

        $response = $handler->handle('Missing', null);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenFallbackResolutionFails(): void
    {
        $portSupplier = $this->createStub(IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn([
            'ok' => false,
            'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured'),
        ]);
        $handler = $this->createHandler($portSupplier, [], []);

        $response = $handler->handle('My Filter', null);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenSingleProviderResolutionFails(): void
    {
        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturn([
            'ok' => false,
            'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured'),
        ]);
        $handler = $this->createHandler($supplier, [], []);

        $response = $handler->handle('My Filter', 'jira');

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorOnApiException(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $linearPort = $this->createStub(IssueTrackerPort::class);
        $linearPort->method('runFilterOrView')->willReturn([$issue]);

        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider, mixed ...$ignored) use ($linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => false, 'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured')],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = $this->createHandler(
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
        $jiraPort = $this->createMock(IssueTrackerPort::class);
        $jiraPort->expects($this->once())
            ->method('runFilterOrView')
            ->willThrowException(new \Exception('Jira API error'));

        $linearPort = $this->createStub(IssueTrackerPort::class);
        $issue = new WorkItem('2', 'ENG-2', 'Linear issue', 'Open', null, '', [], 'Story');
        $linearPort->method('runFilterOrView')->willReturn([$issue]);

        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider, mixed ...$ignored) use ($jiraPort, $linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => true, 'provider' => 'jira', 'port' => $jiraPort],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = $this->createHandler(
            $supplier,
            $this->dualGlobalConfig(),
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
        );

        $response = $handler->handle('Shared Filter', null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->issues);
    }

    public function testHandleDualAutoReturnsFetchErrorWhenOtherProviderIsEmpty(): void
    {
        $jiraPort = $this->createStub(IssueTrackerPort::class);
        $jiraPort->method('runFilterOrView')->willThrowException(new \RuntimeException('Jira down'));
        $linearPort = $this->createStub(IssueTrackerPort::class);
        $linearPort->method('runFilterOrView')->willReturn([]);

        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider, mixed ...$ignored) use ($jiraPort, $linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => true, 'provider' => 'jira', 'port' => $jiraPort],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = $this->createHandler(
            $supplier,
            $this->dualGlobalConfig(),
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
        );

        $response = $handler->handle('Shared Filter', null);

        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'filter.show.error_fetch');
        $this->assertSame('Jira down', $message->parameters['error']);
    }

    public function testHandleDualAutoReturnsResolutionErrorWhenOtherProviderIsEmpty(): void
    {
        $linearPort = $this->createStub(IssueTrackerPort::class);
        $linearPort->method('runFilterOrView')->willReturn([]);

        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider, mixed ...$ignored) use ($linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => false, 'error' => MessageRef::key('issue_tracker_provider.not_configured')],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = $this->createHandler(
            $supplier,
            $this->dualGlobalConfig(),
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
        );

        $response = $handler->handle('Shared Filter', null);

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'issue_tracker_provider.not_configured');
        $this->assertFalse($response->hasDiagnostics());
    }

    public function testHandleDualAutoKeepsLaterFetchFailuresOnTheError(): void
    {
        $jiraPort = $this->createStub(IssueTrackerPort::class);
        $jiraPort->method('runFilterOrView')->willThrowException(new \RuntimeException('Jira down'));
        $linearPort = $this->createStub(IssueTrackerPort::class);
        $linearPort->method('runFilterOrView')->willThrowException(new \RuntimeException('Linear down'));

        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider, mixed ...$ignored) use ($jiraPort, $linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => true, 'provider' => 'jira', 'port' => $jiraPort],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = $this->createHandler(
            $supplier,
            $this->dualGlobalConfig(),
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
        );

        $response = $handler->handle('Shared Filter', null);

        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'filter.show.error_fetch');
        $this->assertSame('Jira down', $message->parameters['error']);
        $warnings = $response->getWarnings();
        $this->assertCount(1, $warnings);
        $later = $this->assertMessageRef($warnings[0]->message, 'filter.show.error_fetch');
        $this->assertSame('Linear down', $later->parameters['error']);
    }

    public function testHandleDualAutoKeepsResolutionWarningBesideAFetchFailure(): void
    {
        $jiraPort = $this->createStub(IssueTrackerPort::class);
        $jiraPort->method('runFilterOrView')->willThrowException(new \RuntimeException('Jira down'));

        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider, mixed ...$ignored) use ($jiraPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => true, 'provider' => 'jira', 'port' => $jiraPort],
                    IssueTrackerProvider::Linear->value => ['ok' => false, 'error' => MessageRef::key('issue_tracker_provider.not_configured')],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = $this->createHandler(
            $supplier,
            $this->dualGlobalConfig(),
            ['issueTrackerProvider' => 'auto', 'projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
        );

        $response = $handler->handle('Shared Filter', null);

        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'filter.show.error_fetch');
        $this->assertSame('Jira down', $message->parameters['error']);
        $warnings = $response->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertMessageRef($warnings[0]->message, 'issue_tracker_provider.not_configured');
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

    private function bindSubject(): void
    {

        $portSupplier = $this->createStub(IssueTrackerPortSupplier::class);
        $portSupplier->method('resolveForProvider')
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);

        $this->handler = $this->createHandler(
            $portSupplier,
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['issueTrackerProvider' => 'jira'],
        );
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function createHandler(
        IssueTrackerPortSupplier $portSupplier,
        array $globalConfig = [],
        array $projectConfig = [],
    ): FilterShowHandler {
        return new FilterShowHandler(new IssueTrackerProviderFanOut(
            $portSupplier,
            $globalConfig,
            $projectConfig,
            new IssueTrackerProvidersQueryResolver(),
        ));
    }
}
