<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\ItemCreatePortSource;
use PHPUnit\Framework\TestCase;

final class ItemCreatePortSourceTest extends TestCase
{
    public function testPinnedSourceReturnsThePortChosenForTheRun(): void
    {
        $port = $this->createStub(IssueTrackerPort::class);

        $source = ItemCreatePortSource::pinned($port);

        $this->assertSame($port, $source->portFor('linear'));
        $this->assertSame($port, $source->pinnedPort());
    }

    public function testLookedUpSourceReturnsPortOfTheTrackerTheProjectLookupMatched(): void
    {
        $port = $this->createStub(IssueTrackerPort::class);
        $global = ['ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear']];
        $project = ['projectKey' => 'SCI'];

        $suppliers = $this->createMock(IssueTrackerPortSupplier::class);
        $suppliers->expects($this->once())
            ->method('resolveForProvider')
            ->with('linear', $global, $project)
            ->willReturn(['ok' => true, 'provider' => 'linear', 'port' => $port]);

        $source = ItemCreatePortSource::lookedUp($suppliers, $global, $project);

        $this->assertSame($port, $source->portFor('linear'));
        $this->assertNull($source->pinnedPort());
    }

    public function testLookedUpSourceReturnsTrackerErrorWhenPortCannotBeBuilt(): void
    {
        $error = MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => 'linear']);
        $suppliers = $this->createStub(IssueTrackerPortSupplier::class);
        $suppliers->method('resolveForProvider')->willReturn(['ok' => false, 'error' => $error]);

        $source = ItemCreatePortSource::lookedUp($suppliers, [], []);

        $this->assertSame($error, $source->portFor('linear'));
    }
}
