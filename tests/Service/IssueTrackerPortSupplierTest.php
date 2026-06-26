<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\StateChange;
use App\Service\IssueTrackerFactory;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerResolver;
use App\Service\JiraApiClient;
use App\Service\JiraAttachmentService;
use App\Service\LinearApiClient;
use App\Service\LinearIssueTrackerAdapter;
use PHPUnit\Framework\TestCase;

class IssueTrackerPortSupplierTest extends TestCase
{
    public function testResolveReturnsPortForJira(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $attachments = $this->createMock(JiraAttachmentService::class);
        $jira->method('getProjectTransitions')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $jira,
            $attachments,
            null,
        );

        $result = $supplier->resolve(
            ['WORK_ITEM_PROVIDERS' => ['jira']],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('jira', $result['provider']);
        $this->assertSame([], $result['port']->listProjectStateChanges('SCI'));
    }

    public function testResolveReturnsLinearDiscoveryPort(): void
    {
        $linear = $this->createMock(LinearApiClient::class);
        $linear->method('getTeamWorkflowStates')->willReturn([
            ['id' => 's1', 'name' => 'Todo', 'type' => 'unstarted'],
        ]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            null,
            null,
            $linear,
        );

        $result = $supplier->resolve(
            ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertInstanceOf(LinearIssueTrackerAdapter::class, $result['port']);
        $this->assertEquals(
            [new StateChange('s1', 'Todo', null, 'unstarted')],
            $result['port']->listProjectStateChanges('SCI'),
        );
    }
}
