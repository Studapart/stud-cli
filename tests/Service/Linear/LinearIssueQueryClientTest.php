<?php

declare(strict_types=1);

namespace App\Tests\Service\Linear;

use App\Exception\ApiException;
use App\Service\Linear\LinearIssueQueryClient;
use App\Service\LinearGraphqlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinearIssueQueryClientTest extends TestCase
{
    private function createService(MockHttpClient $client): LinearIssueQueryClient
    {
        return new LinearIssueQueryClient(new LinearGraphqlClient($client));
    }

    public function testResolveIssueIdReturnsInternalId(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issue' => [
                        'id' => 'uuid-1',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $this->assertSame('uuid-1', $service->resolveIssueId('SCI-1'));
    }

    public function testResolveTeamKeyFromIssue(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issue' => [
                        'team' => ['key' => 'ENG'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $this->assertSame('ENG', $service->resolveTeamKeyFromIssue('ENG-5'));
    }

    public function testResolveIssueIdThrowsWhenIssueMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issue' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->resolveIssueId('SCI-404');
    }

    public function testResolveTeamKeyFromIssueThrowsWhenTeamMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issue' => ['id' => 'x', 'team' => null]]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->resolveTeamKeyFromIssue('SCI-1');
    }

    public function testGetIssueReturnsIssueNode(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issue' => [
                        'id' => 'issue-1',
                        'identifier' => 'SCI-123',
                        'title' => 'Show me',
                        'state' => ['name' => 'Todo'],
                        'assignee' => ['name' => 'Ada'],
                        'labels' => ['nodes' => []],
                        'attachments' => ['nodes' => []],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issue = $this->createService($client)->getIssue('SCI-123');

        $this->assertSame('SCI-123', $issue['identifier']);
    }

    public function testGetIssueThrowsWhenMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issue' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->getIssue('MISSING');
    }

    public function testListAssignedActiveIssuesReturnsNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issues' => [
                        'nodes' => [
                            ['id' => 'i1', 'identifier' => 'ENG-1', 'title' => 'One'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issues = $this->createService($client)->listAssignedActiveIssues('ENG', true);

        $this->assertCount(1, $issues);
        $this->assertSame('ENG-1', $issues[0]['identifier']);
    }

    public function testListAssignedActiveIssuesReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issues' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->listAssignedActiveIssues(null, false));
    }

    public function testSearchIssuesReturnsNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'searchIssues' => [
                        'nodes' => [
                            [
                                'id' => 'issue-1',
                                'identifier' => 'SCI-99',
                                'title' => 'Login bug',
                                'url' => 'https://linear.app/studapart/issue/SCI-99',
                                'state' => ['name' => 'Todo'],
                                'labels' => ['nodes' => []],
                            ],
                        ],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issues = $this->createService($client)->searchIssues('login bug');

        $this->assertCount(1, $issues);
        $this->assertSame('SCI-99', $issues[0]['identifier']);
    }

    public function testSearchIssuesReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['searchIssues' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->searchIssues('login bug'));
    }

    public function testSearchIssuesPassesAfterCursorWhenProvided(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'searchIssues' => [
                        'nodes' => [],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->searchIssues('login bug', 25, 'cursor-abc');

        $this->addToAssertionCount(1);
    }

    public function testListIssuesByFilterReturnsNodes(): void
    {
        $filter = ['labels' => ['some' => ['name' => ['eq' => 'Bug']]]];
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issues' => [
                        'nodes' => [
                            ['id' => 'i1', 'identifier' => 'SCI-9', 'title' => 'Bug fix'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issues = $this->createService($client)->listIssuesByFilter($filter);

        $this->assertCount(1, $issues);
        $this->assertSame('SCI-9', $issues[0]['identifier']);
    }

    public function testListIssuesByFilterReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issues' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->listIssuesByFilter(['number' => ['eq' => -1]]));
    }
}
