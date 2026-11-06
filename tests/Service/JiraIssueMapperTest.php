<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CanConvertToPlainTextInterface;
use App\Service\JiraIssueMapper;
use PHPUnit\Framework\TestCase;

class JiraIssueMapperTest extends TestCase
{
    private JiraIssueMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new JiraIssueMapper($this->createStub(CanConvertToPlainTextInterface::class));
    }

    public function testMapToWorkItemWithHtmlDescription(): void
    {
        $htmlConverter = $this->createMock(CanConvertToPlainTextInterface::class);
        $mapper = new JiraIssueMapper($htmlConverter);

        $data = [
            'id' => '10001',
            'key' => 'TEST-1',
            'fields' => [
                'summary' => 'Issue with Description',
                'status' => ['name' => 'Done'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => null, // ADF description is null as we expect renderedFields
                'labels' => [],
                'issuetype' => ['name' => 'Task'],
                'components' => [],
            ],
            'renderedFields' => [
                'description' => '<p>This is a <strong>description</strong> with a <a href="#">link</a>.</p><ul><li>Item 1</li><li>Item 2</li></ul><pre><code>echo \'hello\';</code></pre>',
            ],
        ];

        $expectedHtml = '<p>This is a <strong>description</strong> with a <a href="#">link</a>.</p><ul><li>Item 1</li><li>Item 2</li></ul><pre><code>echo \'hello\';</code></pre>';
        $expectedPlainText = "This is a description with a <a href=\"#\">link</a>.

* Item 1
* Item 2

```
echo 'hello';
```";

        $htmlConverter->expects($this->once())
            ->method('toPlainText')
            ->with($expectedHtml)
            ->willReturn($expectedPlainText);

        $workItem = $mapper->mapToWorkItem($data);

        $this->assertSame($expectedPlainText, $workItem->description);
    }

    public function testMapToWorkItemWithAssigneeNull(): void
    {
        $data = [
            'id' => '10001',
            'key' => 'TEST-1',
            'fields' => [
                'summary' => 'Issue with no assignee',
                'status' => ['name' => 'Done'],
                'assignee' => null,
                'description' => null,
                'labels' => [],
                'issuetype' => ['name' => 'Task'],
                'components' => [],
            ],
        ];

        $workItem = $this->mapper->mapToWorkItem($data);

        $this->assertSame('Unassigned', $workItem->assignee);
    }

    // Note: testConvertHtmlToPlainText was removed as the conversion logic
    // is now in JiraHtmlConverter and tested in JiraHtmlConverterTest

    public function testMapToWorkItemWithAdfDescriptionFallback(): void
    {
        $data = [
            'id' => '10001',
            'key' => 'TEST-1',
            'fields' => [
                'summary' => 'Issue with ADF Description',
                'status' => ['name' => 'To Do'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => ['type' => 'doc', 'version' => 1, 'content' => []], // Mock ADF content
                'labels' => [],
                'issuetype' => ['name' => 'Task'],
                'components' => [],
            ],
            // No renderedFields
        ];

        $workItem = $this->mapper->mapToWorkItem($data);

        $expectedDescription = 'ADF content not rendered: {"type":"doc","version":1,"content":[]}';
        $this->assertSame($expectedDescription, $workItem->description);
    }

    public function testMapToWorkItemWithPriority(): void
    {
        $data = [
            'id' => '10001',
            'key' => 'TEST-1',
            'fields' => [
                'summary' => 'Issue with Priority',
                'status' => ['name' => 'To Do'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => null,
                'labels' => [],
                'issuetype' => ['name' => 'Task'],
                'components' => [],
                'priority' => ['name' => 'High'],
            ],
        ];

        $workItem = $this->mapper->mapToWorkItem($data);

        $this->assertSame('High', $workItem->priority);
    }

    public function testMapToWorkItemWithNullPriority(): void
    {
        $data = [
            'id' => '10001',
            'key' => 'TEST-1',
            'fields' => [
                'summary' => 'Issue without Priority',
                'status' => ['name' => 'To Do'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => null,
                'labels' => [],
                'issuetype' => ['name' => 'Task'],
                'components' => [],
            ],
        ];

        $workItem = $this->mapper->mapToWorkItem($data);

        $this->assertNull($workItem->priority);
    }

    public function testMapToWorkItemWithPriorityMissingName(): void
    {
        $data = [
            'id' => '10001',
            'key' => 'TEST-1',
            'fields' => [
                'summary' => 'Issue with Priority Missing Name',
                'status' => ['name' => 'To Do'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => null,
                'labels' => [],
                'issuetype' => ['name' => 'Task'],
                'components' => [],
                'priority' => [],
            ],
        ];

        $workItem = $this->mapper->mapToWorkItem($data);

        $this->assertNull($workItem->priority);
    }
}
