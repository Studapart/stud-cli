<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\ItemListAggregate;
use App\DTO\ItemListConfigs;
use App\DTO\MessageRef;
use App\DTO\WorkItem;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\ItemListProviderFetcher;
use App\Service\ProjectScopeKeyResolver;
use PHPUnit\Framework\TestCase;

class ItemListProviderFetcherTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $projectConfig = ['projectKey' => 'SCI', 'linearTeamKey' => 'eng'];

    public function testFetchIntoAddsProviderItemsAndReturnsNull(): void
    {
        $issue = $this->workItem('SCI-1');
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())->method('listAssignedActive')->with('SCI', true)->willReturn([$issue]);
        $aggregate = new ItemListAggregate();

        $failure = $this->fetcher(['ok' => true, 'provider' => 'jira', 'port' => $port])
            ->fetchInto($aggregate, 'jira', null, true);

        $this->assertNull($failure);
        $this->assertSame([$issue], $aggregate->issues());
        $this->assertSame(['jira'], $aggregate->providers());
    }

    public function testFetchIntoUsesLinearTeamKeyForLinearWithoutProject(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())->method('listAssignedActive')->with('ENG', false)->willReturn([]);

        $failure = $this->fetcher(['ok' => true, 'provider' => 'linear', 'port' => $port])
            ->fetchInto(new ItemListAggregate(), 'linear', null, false);

        $this->assertNull($failure);
    }

    public function testFetchIntoPrefersTrimmedUppercasedProjectScope(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())->method('listAssignedActive')->with('MYPROJ', true)->willReturn([]);

        $this->fetcher(['ok' => true, 'provider' => 'jira', 'port' => $port])
            ->fetchInto(new ItemListAggregate(), 'jira', ' myproj ', true);
    }

    public function testFetchIntoReturnsResolutionErrorWithoutFetching(): void
    {
        $error = MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => 'nope']);
        $aggregate = new ItemListAggregate();

        $failure = $this->fetcher(['ok' => false, 'error' => $error])->fetchInto($aggregate, 'nope', null, true);

        $this->assertSame($error, $failure);
        $this->assertSame([], $aggregate->issues());
    }

    public function testFetchIntoReturnsFetchErrorWhenPortThrows(): void
    {
        $port = $this->createStub(IssueTrackerPort::class);
        $port->method('listAssignedActive')->willThrowException(new \RuntimeException('boom'));
        $aggregate = new ItemListAggregate();

        $failure = $this->fetcher(['ok' => true, 'provider' => 'jira', 'port' => $port])
            ->fetchInto($aggregate, 'jira', null, true);

        $this->assertInstanceOf(MessageRef::class, $failure);
        $this->assertSame('item.list.error_fetch', $failure->key);
        $this->assertSame(['error' => 'boom'], $failure->parameters);
        $this->assertSame([], $aggregate->issues());
    }

    public function testFetchIntoReturnsFetchErrorWhenPortThrowsError(): void
    {
        $port = $this->createStub(IssueTrackerPort::class);
        $port->method('listAssignedActive')->willThrowException(new \Error('type blew up'));
        $aggregate = new ItemListAggregate();

        $failure = $this->fetcher(['ok' => true, 'provider' => 'jira', 'port' => $port])
            ->fetchInto($aggregate, 'jira', null, true);

        $this->assertInstanceOf(MessageRef::class, $failure);
        $this->assertSame('item.list.error_fetch', $failure->key);
        $this->assertSame(['error' => 'type blew up'], $failure->parameters);
        $this->assertSame([], $aggregate->issues());
    }

    /**
     * @param array{ok: true, provider: 'jira'|'linear', port: IssueTrackerPort}|array{ok: false, error: MessageRef} $resolution
     */
    private function fetcher(array $resolution): ItemListProviderFetcher
    {
        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturn($resolution);

        return new ItemListProviderFetcher($supplier, new ItemListConfigs([], $this->projectConfig), new ProjectScopeKeyResolver());
    }

    private function workItem(string $key): WorkItem
    {
        return new WorkItem('1', $key, 'Title', 'In Progress', 'John Doe', 'description', [], 'Task');
    }
}
