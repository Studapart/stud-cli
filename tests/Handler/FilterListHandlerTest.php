<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\Filter;
use App\Enum\IssueTrackerProvider;
use App\Handler\FilterListHandler;
use App\Service\IssueTrackerPortSupplier;
use App\Tests\CommandTestCase;

class FilterListHandlerTest extends CommandTestCase
{
    private FilterListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $portSupplier = $this->createMock(IssueTrackerPortSupplier::class);
        $portSupplier->method('resolveForProvider')
            ->with('jira', $this->anything())
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);

        $this->handler = new FilterListHandler(
            $portSupplier,
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['issueTrackerProvider' => 'jira'],
            $this->translationService,
        );
    }

    public function testHandleReturnsSuccessWithFilters(): void
    {
        $filter = new Filter('My Filter', 'Filter description');

        $this->issueTracker->expects($this->once())
            ->method('listFiltersOrViews')
            ->willReturn([$filter]);

        $response = $this->handler->handle('jira');

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->filters);
        $this->assertSame('My Filter', $response->filters[0]->name);
        $this->assertSame('Filter description', $response->filters[0]->description);
    }

    public function testHandleReturnsSuccessWithEmptyFilters(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listFiltersOrViews')
            ->willReturn([]);

        $response = $this->handler->handle('jira');

        $this->assertTrue($response->isSuccess());
        $this->assertCount(0, $response->filters);
    }

    public function testHandleReturnsErrorOnJiraApiClientException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listFiltersOrViews')
            ->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle('jira');

        $this->assertFalse($response->isSuccess());
        $this->assertNotNull($response->getError());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'filter.list.error_fetch');
        $this->assertSame('Jira API error', $message->parameters['error']);
        $this->assertCount(0, $response->filters);
    }

    public function testHandleSortsFiltersByName(): void
    {
        $filter1 = new Filter('Zebra Filter', 'Description 1');
        $filter2 = new Filter('Alpha Filter', 'Description 2');
        $filter3 = new Filter('Beta Filter', 'Description 3');

        $this->issueTracker->expects($this->once())
            ->method('listFiltersOrViews')
            ->willReturn([$filter1, $filter2, $filter3]);

        $response = $this->handler->handle('jira');

        $this->assertTrue($response->isSuccess());
        $this->assertCount(3, $response->filters);
        $this->assertSame('Alpha Filter', $response->filters[0]->name);
        $this->assertSame('Beta Filter', $response->filters[1]->name);
        $this->assertSame('Zebra Filter', $response->filters[2]->name);
    }

    public function testHandleFallsBackWhenProviderListEmpty(): void
    {
        $portSupplier = $this->createMock(\App\Service\IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $portSupplier->method('resolveForProvider')->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->issueTracker]);
        $handler = new FilterListHandler($portSupplier, [], [], $this->translationService);

        $this->issueTracker->expects($this->once())->method('listFiltersOrViews')->willReturn([]);

        $response = $handler->handle(null);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenFallbackResolutionFails(): void
    {
        $portSupplier = $this->createMock(\App\Service\IssueTrackerPortSupplier::class);
        $portSupplier->method('resolve')->willReturn([
            'ok' => false,
            'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured'),
        ]);
        $handler = new FilterListHandler($portSupplier, [], [], $this->translationService);

        $response = $handler->handle(null);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsFilterWithNullDescription(): void
    {
        $filter = new Filter('My Filter', null);

        $this->issueTracker->expects($this->once())
            ->method('listFiltersOrViews')
            ->willReturn([$filter]);

        $response = $this->handler->handle('jira');

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->filters);
        $this->assertSame('My Filter', $response->filters[0]->name);
        $this->assertNull($response->filters[0]->description);
    }

    public function testHandleDualAutoContinuesWhenOneProviderFails(): void
    {
        $jiraPort = $this->issueTracker;
        $linearPort = $this->createMock(\App\Service\IssueTrackerPort::class);
        $linearPort->method('listFiltersOrViews')->willReturn([new Filter('Linear View', null)]);

        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static function (string $provider) use ($jiraPort, $linearPort): array {
                return match ($provider) {
                    IssueTrackerProvider::Jira->value => ['ok' => false, 'error' => \App\DTO\MessageRef::key('issue_tracker_provider.not_configured')],
                    IssueTrackerProvider::Linear->value => ['ok' => true, 'provider' => 'linear', 'port' => $linearPort],
                    default => ['ok' => false, 'error' => 'unknown'],
                };
            },
        );

        $handler = new FilterListHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
            $this->translationService,
        );

        $response = $handler->handle(null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->filters);
        $this->assertTrue($response->hasDiagnostics());
    }

    public function testHandleDualAutoReturnsErrorWhenSingleProviderFetchFails(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('listFiltersOrViews')
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
        $handler = new FilterListHandler($supplier, [], [], $this->translationService);

        $response = $handler->handle('jira');

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleDualAutoWarnsWhenOneProviderFetchThrows(): void
    {
        $jiraPort = $this->issueTracker;
        $jiraPort->expects($this->once())->method('listFiltersOrViews')->willThrowException(new \Exception('jira down'));
        $linearPort = $this->createMock(\App\Service\IssueTrackerPort::class);
        $linearPort->method('listFiltersOrViews')->willReturn([new Filter('Linear View', null)]);

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

        $handler = new FilterListHandler(
            $supplier,
            $this->dualGlobalConfig(),
            $this->dualProjectConfig(),
            $this->translationService,
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
