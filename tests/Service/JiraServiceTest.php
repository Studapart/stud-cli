<?php

namespace App\Tests\Service;

use App\DTO\Project;
use App\DTO\WorkItem;
use App\Service\JiraService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class JiraServiceTest extends TestCase
{
    private JiraService $jiraService;
    private HttpClientInterface&MockObject $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->jiraService = new JiraService($this->httpClientMock);
    }

    public function testGetIssueSuccess(): void
    {
        $key = 'TEST-123';
        $mockResponseData = [
            'id' => '10001',
            'key' => $key,
            'fields' => [
                'summary' => 'Test Issue Summary',
                'status' => ['name' => 'To Do'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => null,
                'labels' => ['bug', 'frontend'],
                'issuetype' => ['name' => 'Bug'],
                'components' => [['name' => 'Component A']],
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', "/rest/api/3/issue/{$key}")
            ->willReturn($responseMock);

        $workItem = $this->jiraService->getIssue($key);

        $this->assertInstanceOf(WorkItem::class, $workItem);
        $this->assertSame($key, $workItem->key);
        $this->assertSame('Test Issue Summary', $workItem->title);
        $this->assertSame('To Do', $workItem->status);
        $this->assertSame('John Doe', $workItem->assignee);
        $this->assertSame(['bug', 'frontend'], $workItem->labels);
        $this->assertSame('Bug', $workItem->issueType);
        $this->assertSame(['Component A'], $workItem->components);
        $this->assertSame('No description provided.', $workItem->description);
    }

    public function testGetIssueWithRenderedFields(): void
    {
        $key = 'TEST-123';
        $mockResponseData = [
            'id' => '10001',
            'key' => $key,
            'fields' => [
                'summary' => 'Test Issue Summary',
                'status' => ['name' => 'To Do'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => null,
                'labels' => [],
                'issuetype' => ['name' => 'Bug'],
                'components' => [],
            ],
            'renderedFields' => [
                'description' => '<p>Rendered Description</p>',
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', "/rest/api/3/issue/{$key}?expand=renderedFields")
            ->willReturn($responseMock);

        $workItem = $this->jiraService->getIssue($key, true);

        $this->assertInstanceOf(WorkItem::class, $workItem);
        $this->assertSame('<p>Rendered Description</p>', $workItem->renderedDescription);
    }

    public function testGetIssueNotFound(): void
    {
        $key = 'NONEXISTENT-404';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', "/rest/api/3/issue/{$key}")
            ->willReturn($responseMock);

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage("Could not find Jira issue with key \"{$key}\".");

        $this->jiraService->getIssue($key);
    }

    public function testSearchIssuesSuccess(): void
    {
        $jql = 'project = TEST';
        $mockResponseData = [
            'issues' => [
                [
                    'id' => '10001',
                    'key' => 'TEST-1',
                    'fields' => [
                        'summary' => 'Issue 1',
                        'status' => ['name' => 'To Do'],
                        'assignee' => ['displayName' => 'John Doe'],
                        'description' => null,
                        'labels' => [],
                        'issuetype' => ['name' => 'Task'],
                        'components' => [],
                    ],
                ],
                [
                    'id' => '10002',
                    'key' => 'TEST-2',
                    'fields' => [
                        'summary' => 'Issue 2',
                        'status' => ['name' => 'In Progress'],
                        'assignee' => ['displayName' => 'Jane Doe'],
                        'description' => null,
                        'labels' => [],
                        'issuetype' => ['name' => 'Bug'],
                        'components' => [],
                    ],
                ],
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', '/rest/api/3/search/jql', [
                'json' => [
                    'jql' => $jql,
                    'fields' => ['key', 'summary', 'status', 'description', 'assignee', 'labels', 'issuetype', 'components'],
                ],
            ])
            ->willReturn($responseMock);

        $workItems = $this->jiraService->searchIssues($jql);

        $this->assertIsArray($workItems);
        $this->assertCount(2, $workItems);
        $this->assertInstanceOf(WorkItem::class, $workItems[0]);
        $this->assertSame('TEST-1', $workItems[0]->key);
        $this->assertSame('Issue 2', $workItems[1]->title);
    }

    public function testSearchIssuesEmptyResult(): void
    {
        $jql = 'project = NONEXISTENT';
        $mockResponseData = [
            'issues' => [],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $workItems = $this->jiraService->searchIssues($jql);

        $this->assertIsArray($workItems);
        $this->assertEmpty($workItems);
    }

    public function testSearchIssuesFailure(): void
    {
        $jql = 'invalid jql';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(400);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage('Failed to search for issues.');

        $this->jiraService->searchIssues($jql);
    }

    public function testGetProjectsSuccess(): void
    {
        $mockResponseData = [
            'values' => [
                ['key' => 'PROJ1', 'name' => 'Project One'],
                ['key' => 'PROJ2', 'name' => 'Project Two'],
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/rest/api/3/project/search')
            ->willReturn($responseMock);

        $projects = $this->jiraService->getProjects();

        $this->assertIsArray($projects);
        $this->assertCount(2, $projects);
        $this->assertInstanceOf(Project::class, $projects[0]);
        $this->assertSame('PROJ1', $projects[0]->key);
        $this->assertSame('Project Two', $projects[1]->name);
    }

    public function testGetProjectsEmptyResult(): void
    {
        $mockResponseData = [
            'values' => [],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $projects = $this->jiraService->getProjects();

        $this->assertIsArray($projects);
        $this->assertEmpty($projects);
    }

    public function testGetProjectsFailure(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage('Failed to fetch projects.');

        $this->jiraService->getProjects();
    }

    public function testMapToWorkItemWithDescription(): void
    {
        $data = [
            'id' => '10001',
            'key' => 'TEST-1',
            'fields' => [
                'summary' => 'Issue with Description',
                'status' => ['name' => 'Done'],
                'assignee' => ['displayName' => 'John Doe'],
                'description' => [
                    'version' => 1,
                    'type' => 'doc',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'This is a description.']]],
                    ],
                ],
                'labels' => [],
                'issuetype' => ['name' => 'Task'],
                'components' => [],
            ],
        ];

        $workItem = $this->callPrivateMethod($this->jiraService, 'mapToWorkItem', [$data]);

        $this->assertSame("\nThis is a description.", $workItem->description);
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

        $workItem = $this->callPrivateMethod($this->jiraService, 'mapToWorkItem', [$data]);

        $this->assertSame('Unassigned', $workItem->assignee);
    }

    public function testRenderDocNodeParagraph(): void
    {
        $node = [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hello, world!'],
            ],
        ];
        $expected = "\nHello, world!";
        $this->assertSame($expected, $this->callPrivateMethod($this->jiraService, '_render_doc_node', [$node]));
    }

    public function testRenderDocNodeHeading(): void
    {
        $node = [
            'type' => 'heading',
            'attrs' => ['level' => 1],
            'content' => [
                ['type' => 'text', 'text' => 'Main Title'],
            ],
        ];
        $expected = "\n# Main Title";
        $this->assertSame($expected, $this->callPrivateMethod($this->jiraService, '_render_doc_node', [$node]));
    }

    public function testRenderDocNodeBulletList(): void
    {
        $node = [
            'type' => 'bulletList',
            'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item 1']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item 2']]]]],
            ],
        ];
        $expected = "\n* Item 1\n* Item 2";
        $this->assertSame($expected, $this->callPrivateMethod($this->jiraService, '_render_doc_node', [$node]));
    }

    public function testRenderDocNodeOrderedList(): void
    {
        $node = [
            'type' => 'orderedList',
            'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First item']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second item']]]]],
            ],
        ];
        $expected = "\n1. First item\n2. Second item";
        $this->assertSame($expected, $this->callPrivateMethod($this->jiraService, '_render_doc_node', [$node]));
    }

    public function testRenderDocNodeCodeBlock(): void
    {
        $node = [
            'type' => 'codeBlock',
            'content' => [
                ['type' => 'text', 'text' => 'echo \'hello\';'],
            ],
        ];
        $expected = "\n```\necho 'hello';\n```\n";
        $this->assertSame($expected, $this->callPrivateMethod($this->jiraService, '_render_doc_node', [$node]));
    }

    public function testRenderDocNodeNestedList(): void
    {
        $node = [
            'type' => 'bulletList',
            'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Parent item']]],
                    ['type' => 'bulletList', 'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Child item 1']]]]],
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Child item 2']]]]],
                    ]],
                ]],
            ],
        ];
        $expected = "\n* Parent item\n  * Child item 1\n  * Child item 2";
        $this->assertSame($expected, $this->callPrivateMethod($this->jiraService, '_render_doc_node', [$node]));
    }

    // Helper to call private methods for testing
    private function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
