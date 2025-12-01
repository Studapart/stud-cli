<?php

namespace App\Tests\Service;

use App\DTO\Project;
use App\DTO\WorkItem;
use App\Service\JiraService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stevebauman\Hypertext\Transformer as RealTransformer;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class JiraServiceTest extends TestCase
{
    private JiraService $jiraService;
    private HttpClientInterface&MockObject $httpClientMock;
    private RealTransformer&MockObject $transformerMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->transformerMock = $this->createMock(RealTransformer::class);
        $this->jiraService = new JiraService($this->httpClientMock, $this->transformerMock);
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
        $mockHtmlDescription = '<p>Rendered Description</p>';
        $expectedPlainText = 'Rendered Description';

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
                'description' => $mockHtmlDescription,
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', "/rest/api/3/issue/{$key}?expand=renderedFields")
            ->willReturn($responseMock);

        $this->transformerMock->expects($this->once())
            ->method('keepLinks')
            ->willReturnSelf();
        $this->transformerMock->expects($this->once())
            ->method('keepNewLines')
            ->willReturnSelf();
        $this->transformerMock->expects($this->once())
            ->method('toText')
            ->with($mockHtmlDescription)
            ->willReturn($expectedPlainText);

        $workItem = $this->jiraService->getIssue($key, true);

        $this->assertInstanceOf(WorkItem::class, $workItem);
        $this->assertSame($expectedPlainText, $workItem->description); // Assert against description, not renderedDescription
        $this->assertSame($mockHtmlDescription, $workItem->renderedDescription); // renderedDescription still holds raw HTML
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

    public function testMapToWorkItemWithHtmlDescription(): void
    {
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

        $this->transformerMock->expects($this->once())
            ->method('keepLinks')
            ->willReturnSelf();
        $this->transformerMock->expects($this->once())
            ->method('keepNewLines')
            ->willReturnSelf();
        $this->transformerMock->expects($this->once())
            ->method('toText')
            ->with($expectedHtml)
            ->willReturn($expectedPlainText);

        $workItem = $this->callPrivateMethod($this->jiraService, 'mapToWorkItem', [$data]);

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

        $workItem = $this->callPrivateMethod($this->jiraService, 'mapToWorkItem', [$data]);

        $this->assertSame('Unassigned', $workItem->assignee);
    }

    public function testConvertHtmlToPlainText(): void
    {
        $html1 = '<p>Hello, <strong>world</strong>!</p><ul><li>Item 1</li><li>Item 2</li></ul><pre><code>function test() { return true; }</code></pre>';
        $expected1 = "Hello, world!

* Item 1
* Item 2

```
function test() { return true; }
```";

        $html2 = '<p>This &amp; that &gt; 10 &euro;</p>';
        $expected2 = "This & that > 10 €";

        $html3 = "<p>Line 1</p>\n\n<p>Line 2</p>";
        $expected3 = "Line 1

Line 2";

        $html4 = "Line 1<br>Line 2<br/>Line 3";
        $expected4 = "Line 1
Line 2
Line 3";

        $this->transformerMock->expects($this->exactly(4))
            ->method('keepLinks')
            ->willReturnSelf();
        $this->transformerMock->expects($this->exactly(4))
            ->method('keepNewLines')
            ->willReturnSelf();
        $this->transformerMock->expects($this->exactly(4))
            ->method('toText')
            ->willReturnCallback(function ($html) use ($html1, $expected1, $html2, $expected2, $html3, $expected3, $html4, $expected4) {
                switch ($html) {
                    case $html1:
                        return $expected1;
                    case $html2:
                        return $expected2;
                    case $html3:
                        return $expected3;
                    case $html4:
                        return $expected4;
                    default:
                        return 'Unexpected HTML input: ' . $html;
                }
            });

        $this->assertSame($expected1, $this->callPrivateMethod($this->jiraService, '_convertHtmlToPlainText', [$html1]));
        $this->assertSame($expected2, $this->callPrivateMethod($this->jiraService, '_convertHtmlToPlainText', [$html2]));
        $this->assertSame($expected3, $this->callPrivateMethod($this->jiraService, '_convertHtmlToPlainText', [$html3]));
        $this->assertSame($expected4, $this->callPrivateMethod($this->jiraService, '_convertHtmlToPlainText', [$html4]));
    }

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

        $workItem = $this->callPrivateMethod($this->jiraService, 'mapToWorkItem', [$data]);

        $expectedDescription = 'ADF content not rendered: {"type":"doc","version":1,"content":[]}';
        $this->assertSame($expectedDescription, $workItem->description);
    }

    public function testGetTransitionsSuccess(): void
    {
        $key = 'TEST-123';
        $mockResponseData = [
            'transitions' => [
                [
                    'id' => 11,
                    'name' => 'Start Progress',
                    'to' => [
                        'name' => 'In Progress',
                        'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                    ],
                ],
                [
                    'id' => 21,
                    'name' => 'Done',
                    'to' => [
                        'name' => 'Done',
                        'statusCategory' => ['key' => 'done', 'name' => 'Done'],
                    ],
                ],
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', "/rest/api/3/issue/{$key}/transitions")
            ->willReturn($responseMock);

        $transitions = $this->jiraService->getTransitions($key);

        $this->assertIsArray($transitions);
        $this->assertCount(2, $transitions);
        $this->assertSame(11, $transitions[0]['id']);
        $this->assertSame('Start Progress', $transitions[0]['name']);
        $this->assertSame('in_progress', $transitions[0]['to']['statusCategory']['key']);
    }

    public function testGetTransitionsFailure(): void
    {
        $key = 'TEST-123';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', "/rest/api/3/issue/{$key}/transitions")
            ->willReturn($responseMock);

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage("Could not fetch transitions for issue \"{$key}\".");

        $this->jiraService->getTransitions($key);
    }

    public function testTransitionIssueSuccess(): void
    {
        $key = 'TEST-123';
        $transitionId = 11;

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(204);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', "/rest/api/3/issue/{$key}/transitions", [
                'json' => [
                    'transition' => [
                        'id' => $transitionId,
                    ],
                ],
            ])
            ->willReturn($responseMock);

        $this->jiraService->transitionIssue($key, $transitionId);
        // No exception means success
        $this->assertTrue(true);
    }

    public function testTransitionIssueFailure(): void
    {
        $key = 'TEST-123';
        $transitionId = 11;

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(400);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage("Could not execute transition {$transitionId} for issue \"{$key}\".");

        $this->jiraService->transitionIssue($key, $transitionId);
    }

    public function testGetCurrentUserAccountId(): void
    {
        $accountId = '5d5b5c5e5f5a5b5c5d5e5f5a';
        $mockResponseData = [
            'accountId' => $accountId,
            'displayName' => 'Test User',
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/rest/api/3/myself')
            ->willReturn($responseMock);

        $result = $this->callPrivateMethod($this->jiraService, 'getCurrentUserAccountId');

        $this->assertSame($accountId, $result);
    }

    public function testGetCurrentUserAccountIdCaching(): void
    {
        $accountId = '5d5b5c5e5f5a5b5c5d5e5f5a';
        $mockResponseData = [
            'accountId' => $accountId,
            'displayName' => 'Test User',
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        // Expect only one call to /myself even though we call getCurrentUserAccountId twice
        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/rest/api/3/myself')
            ->willReturn($responseMock);

        $result1 = $this->callPrivateMethod($this->jiraService, 'getCurrentUserAccountId');
        $result2 = $this->callPrivateMethod($this->jiraService, 'getCurrentUserAccountId');

        $this->assertSame($accountId, $result1);
        $this->assertSame($accountId, $result2);
    }

    public function testGetCurrentUserAccountIdFailure(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(401);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/rest/api/3/myself')
            ->willReturn($responseMock);

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage('Could not retrieve current user information.');

        $this->callPrivateMethod($this->jiraService, 'getCurrentUserAccountId');
    }

    public function testGetCurrentUserAccountIdMissingAccountId(): void
    {
        $mockResponseData = [
            'displayName' => 'Test User',
            // Missing accountId
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($mockResponseData);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/rest/api/3/myself')
            ->willReturn($responseMock);

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage('Could not find accountId in current user information.');

        $this->callPrivateMethod($this->jiraService, 'getCurrentUserAccountId');
    }

    public function testAssignIssueToCurrentUser(): void
    {
        $key = 'TEST-123';
        $accountId = '5d5b5c5e5f5a5b5c5d5e5f5a';

        $myselfResponseMock = $this->createMock(ResponseInterface::class);
        $myselfResponseMock->method('getStatusCode')->willReturn(200);
        $myselfResponseMock->method('toArray')->willReturn([
            'accountId' => $accountId,
            'displayName' => 'Test User',
        ]);

        $assignResponseMock = $this->createMock(ResponseInterface::class);
        $assignResponseMock->method('getStatusCode')->willReturn(204);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options = []) use ($myselfResponseMock, $assignResponseMock, $key, $accountId) {
                if ($method === 'GET' && $url === '/rest/api/3/myself') {
                    return $myselfResponseMock;
                }
                if ($method === 'PUT' && $url === "/rest/api/3/issue/{$key}/assignee") {
                    $this->assertSame(['accountId' => $accountId], $options['json'] ?? []);

                    return $assignResponseMock;
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            });

        $this->jiraService->assignIssue($key);
        // No exception means success
        $this->assertTrue(true);
    }

    public function testAssignIssueToSpecificUser(): void
    {
        $key = 'TEST-123';
        $accountId = '5d5b5c5e5f5a5b5c5d5e5f5a';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(204);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('PUT', "/rest/api/3/issue/{$key}/assignee", [
                'json' => ['accountId' => $accountId],
            ])
            ->willReturn($responseMock);

        $this->jiraService->assignIssue($key, $accountId);
        // No exception means success
        $this->assertTrue(true);
    }

    public function testAssignIssueFailure(): void
    {
        $key = 'TEST-123';
        $accountId = '5d5b5c5e5f5a5b5c5d5e5f5a';

        $myselfResponseMock = $this->createMock(ResponseInterface::class);
        $myselfResponseMock->method('getStatusCode')->willReturn(200);
        $myselfResponseMock->method('toArray')->willReturn([
            'accountId' => $accountId,
            'displayName' => 'Test User',
        ]);

        $assignResponseMock = $this->createMock(ResponseInterface::class);
        $assignResponseMock->method('getStatusCode')->willReturn(403);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($myselfResponseMock, $assignResponseMock) {
                if ($method === 'GET' && $url === '/rest/api/3/myself') {
                    return $myselfResponseMock;
                }

                return $assignResponseMock;
            });

        $this->expectException("RuntimeException"::class);
        $this->expectExceptionMessage("Could not assign issue \"{$key}\" to user.");

        $this->jiraService->assignIssue($key);
    }

    public function testAssignIssueToCurrentUserUsesCache(): void
    {
        $key1 = 'TEST-123';
        $key2 = 'TEST-456';
        $accountId = '5d5b5c5e5f5a5b5c5d5e5f5a';

        $myselfResponseMock = $this->createMock(ResponseInterface::class);
        $myselfResponseMock->method('getStatusCode')->willReturn(200);
        $myselfResponseMock->method('toArray')->willReturn([
            'accountId' => $accountId,
            'displayName' => 'Test User',
        ]);

        $assignResponseMock1 = $this->createMock(ResponseInterface::class);
        $assignResponseMock1->method('getStatusCode')->willReturn(204);

        $assignResponseMock2 = $this->createMock(ResponseInterface::class);
        $assignResponseMock2->method('getStatusCode')->willReturn(204);

        // /myself should only be called once, even though we assign two issues
        $this->httpClientMock->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($myselfResponseMock, $assignResponseMock1, $assignResponseMock2, $key1, $key2, $accountId) {
                if ($method === 'GET' && $url === '/rest/api/3/myself') {
                    return $myselfResponseMock;
                }
                if ($method === 'PUT' && $url === "/rest/api/3/issue/{$key1}/assignee") {
                    return $assignResponseMock1;
                }
                if ($method === 'PUT' && $url === "/rest/api/3/issue/{$key2}/assignee") {
                    return $assignResponseMock2;
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            });

        $this->jiraService->assignIssue($key1);
        $this->jiraService->assignIssue($key2);
        // No exception means success
        $this->assertTrue(true);
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
