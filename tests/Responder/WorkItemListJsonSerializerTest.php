<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\WorkItemListJsonSerializer;
use PHPUnit\Framework\TestCase;

class WorkItemListJsonSerializerTest extends TestCase
{
    private WorkItemListJsonSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serializer = new WorkItemListJsonSerializer();
    }

    public function testSerializeSummaryReturnsDiscoveryFieldsWithoutPriority(): void
    {
        $issue = new WorkItem(
            '1',
            'PROJ-1',
            'Test title',
            'Open',
            'user',
            'Long description that should not appear',
            ['label'],
            'Story',
            ['component'],
            'High',
        );

        $summary = $this->serializer->serializeSummary($issue, 'https://jira.example.com/');

        $this->assertSame([
            'key' => 'PROJ-1',
            'status' => 'Open',
            'title' => 'Test title',
            'url' => 'https://jira.example.com/browse/PROJ-1',
        ], $summary);
    }

    public function testSerializeSummaryIncludesPriorityWhenRequested(): void
    {
        $issue = new WorkItem('1', 'PROJ-1', 'Test', 'Open', 'user', 'desc', [], 'Story', [], 'Medium');

        $summary = $this->serializer->serializeSummary($issue, 'https://jira.example.com', true);

        $this->assertSame('Medium', $summary['priority']);
    }

    public function testSerializeListReturnsSummaryForEachIssue(): void
    {
        $issues = [
            new WorkItem('1', 'PROJ-1', 'First', 'Open', 'user', 'desc', [], 'Story'),
            new WorkItem('2', 'PROJ-2', 'Second', 'Done', 'user', 'desc', [], 'Task'),
        ];

        $result = $this->serializer->serializeList($issues, 'https://jira.example.com', true);

        $this->assertCount(2, $result);
        $this->assertSame('PROJ-1', $result[0]['key']);
        $this->assertSame('PROJ-2', $result[1]['key']);
        $this->assertArrayHasKey('priority', $result[0]);
    }

    public function testSerializeSummaryUsesWorkItemUrlWhenPresent(): void
    {
        $issue = new WorkItem(
            '1',
            'SCI-42',
            'Linear issue',
            'Open',
            'user',
            'desc',
            [],
            'Story',
            [],
            'Medium',
            null,
            [],
            'https://linear.app/studapart/issue/SCI-42',
        );

        $summary = $this->serializer->serializeSummary($issue, 'https://jira.example.com', true);

        $this->assertSame('https://linear.app/studapart/issue/SCI-42', $summary['url']);
    }
}
