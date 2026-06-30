<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\IssueAttachment;
use App\Service\LinearIssueMapper;
use PHPUnit\Framework\TestCase;

class LinearIssueMapperTest extends TestCase
{
    private LinearIssueMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new LinearIssueMapper();
    }

    public function testMapToWorkItemMapsFullGraphqlFixture(): void
    {
        $node = $this->fullIssueNode();

        $workItem = $this->mapper->mapToWorkItem($node, 'type-group-uuid');

        $this->assertSame('issue-uuid-1', $workItem->id);
        $this->assertSame('SCI-166', $workItem->key);
        $this->assertSame('Linear issue mapper', $workItem->title);
        $this->assertSame('In Progress', $workItem->status);
        $this->assertSame('Ada Lovelace', $workItem->assignee);
        $this->assertSame("## Spec\n\nDetails here.", $workItem->description);
        $this->assertSame("## Spec\n\nDetails here.", $workItem->renderedDescription);
        $this->assertSame(['Bug', 'DX'], $workItem->labels);
        $this->assertSame('Bug', $workItem->issueType);
        $this->assertSame([], $workItem->components);
        $this->assertSame('High', $workItem->priority);
        $this->assertCount(1, $workItem->attachments);
        $this->assertInstanceOf(IssueAttachment::class, $workItem->attachments[0]);
        $this->assertSame('att-1', $workItem->attachments[0]->id);
        $this->assertSame('spec.md', $workItem->attachments[0]->filename);
        $this->assertSame(2048, $workItem->attachments[0]->size);
        $this->assertSame('https://uploads.linear.app/spec.md', $workItem->attachments[0]->contentUrl);
        $this->assertSame('text/markdown', $workItem->attachments[0]->mimeType);
        $this->assertSame('https://linear.app/studapart/issue/SCI-166', $workItem->url);
    }

    public function testMapToWorkItemUsesUnassignedWhenAssigneeMissing(): void
    {
        $node = $this->fullIssueNode();
        unset($node['assignee']);

        $workItem = $this->mapper->mapToWorkItem($node);

        $this->assertSame('Unassigned', $workItem->assignee);
    }

    public function testMapToWorkItemUsesDefaultDescriptionWhenMissing(): void
    {
        $node = $this->fullIssueNode();
        unset($node['description']);

        $workItem = $this->mapper->mapToWorkItem($node);

        $this->assertSame('No description provided.', $workItem->description);
        $this->assertSame('No description provided.', $workItem->renderedDescription);
    }

    public function testMapToWorkItemLeavesIssueTypeEmptyWithoutConfiguredGroup(): void
    {
        $workItem = $this->mapper->mapToWorkItem($this->fullIssueNode());

        $this->assertSame('', $workItem->issueType);
    }

    public function testMapToWorkItemResolvesIssueTypeFromMatchingGroupOnly(): void
    {
        $workItem = $this->mapper->mapToWorkItem($this->fullIssueNode(), 'other-group');

        $this->assertSame('DX', $workItem->issueType);
    }

    public function testMapToWorkItemMapsAllPriorityLevels(): void
    {
        $priorities = [
            0 => null,
            1 => 'Urgent',
            2 => 'High',
            3 => 'Medium',
            4 => 'Low',
        ];

        foreach ($priorities as $raw => $expected) {
            $node = $this->fullIssueNode();
            $node['priority'] = $raw;

            $workItem = $this->mapper->mapToWorkItem($node);

            $this->assertSame($expected, $workItem->priority, 'priority ' . (string) $raw);
        }
    }

    public function testMapToWorkItemSkipsIncompleteAttachmentRows(): void
    {
        $node = $this->fullIssueNode();
        $node['attachments']['nodes'][] = ['id' => 'incomplete'];
        $node['attachments']['nodes'][] = 'not-an-array';
        $node['attachments']['nodes'][] = [
            'id' => 'att-2',
            'filename' => 'notes.txt',
            'url' => 'https://uploads.linear.app/notes.txt',
            'size' => 10,
        ];

        $workItem = $this->mapper->mapToWorkItem($node);

        $this->assertCount(2, $workItem->attachments);
        $this->assertSame('notes.txt', $workItem->attachments[1]->filename);
    }

    public function testMapToWorkItemHandlesSparseGraphqlPayload(): void
    {
        $node = [
            'id' => 'issue-2',
            'identifier' => 'SCI-2',
            'title' => 'Sparse',
            'state' => ['name' => 'Todo'],
            'assignee' => ['name' => ''],
            'labels' => ['nodes' => 'invalid'],
            'attachments' => null,
            'priority' => 'not-a-number',
        ];

        $workItem = $this->mapper->mapToWorkItem($node, 'missing-group');

        $this->assertSame('Unassigned', $workItem->assignee);
        $this->assertSame([], $workItem->labels);
        $this->assertSame('', $workItem->issueType);
        $this->assertNull($workItem->priority);
        $this->assertSame([], $workItem->attachments);

        $emptyPriorityNode = $node;
        $emptyPriorityNode['priority'] = '';
        $this->assertNull($this->mapper->mapToWorkItem($emptyPriorityNode)->priority);
    }

    public function testPriorityNameToValueMapsKnownLabels(): void
    {
        $this->assertSame(1, LinearIssueMapper::priorityNameToValue('Urgent'));
        $this->assertSame(2, LinearIssueMapper::priorityNameToValue('high'));
        $this->assertNull(LinearIssueMapper::priorityNameToValue('unknown'));
    }

    public function testMapCreateResponseExtractsIdentifierAndUrl(): void
    {
        $mapped = $this->mapper->mapCreateResponse([
            'identifier' => 'SCI-99',
            'url' => 'https://linear.app/SCI-99',
        ]);

        $this->assertSame('SCI-99', $mapped['identifier']);
        $this->assertSame('https://linear.app/SCI-99', $mapped['url']);
    }

    public function testMapToWorkItemIgnoresLabelsWithoutParentForIssueType(): void
    {
        $node = $this->fullIssueNode();
        $node['labels']['nodes'] = [
            ['id' => 'flat', 'name' => 'Flat'],
        ];

        $workItem = $this->mapper->mapToWorkItem($node, 'type-group-uuid');

        $this->assertSame('', $workItem->issueType);
        $this->assertSame(['Flat'], $workItem->labels);
    }

    /**
     * @return array<string, mixed>
     */
    private function fullIssueNode(): array
    {
        return [
            'id' => 'issue-uuid-1',
            'identifier' => 'SCI-166',
            'title' => 'Linear issue mapper',
            'url' => 'https://linear.app/studapart/issue/SCI-166',
            'priority' => 2,
            'description' => "## Spec\n\nDetails here.",
            'state' => ['name' => 'In Progress'],
            'assignee' => ['name' => 'Ada Lovelace'],
            'labels' => [
                'nodes' => [
                    [
                        'id' => 'label-bug',
                        'name' => 'Bug',
                        'parent' => ['id' => 'type-group-uuid'],
                    ],
                    [
                        'id' => 'label-dx',
                        'name' => 'DX',
                        'parent' => ['id' => 'other-group'],
                    ],
                ],
            ],
            'attachments' => [
                'nodes' => [
                    [
                        'id' => 'att-1',
                        'title' => 'spec.md',
                        'url' => 'https://uploads.linear.app/spec.md',
                        'size' => 2048,
                        'contentType' => 'text/markdown',
                    ],
                ],
            ],
        ];
    }
}
