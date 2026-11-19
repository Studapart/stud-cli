<?php

namespace App\Tests\Service;

use App\DTO\GitLabProject;
use App\DTO\PullRequestData;
use App\DTO\PullRequestFeedbackIds;
use App\Service\GitLabApiClient;
use App\Service\GitLabGitHostingAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitLabGitHostingAdapterTest extends TestCase
{
    private const GITLAB_TOKEN = 'test_token';
    private const GITLAB_OWNER = 'test_owner';
    private const GITLAB_REPO = 'test_repo';
    private const PROJECT_PATH = 'test_owner%2Ftest_repo';

    private GitLabGitHostingAdapter $gitlabProvider;
    private HttpClientInterface $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createStub(HttpClientInterface::class);
        $this->bindSubject();
    }

    public function testCreatePullRequestSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $title = 'Test MR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test merge request.';
        $gitlabResponse = [
            'iid' => 1,
            'id' => 123,
            'title' => $title,
            'source_branch' => $head,
            'target_branch' => $base,
            'description' => $body,
            'work_in_progress' => false,
            'state' => 'opened',
            'web_url' => 'https://gitlab.com/test_owner/test_repo/-/merge_requests/1',
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/' . self::PROJECT_PATH . '/merge_requests',
                [
                    'json' => [
                        'title' => $title,
                        'source_branch' => $head,
                        'target_branch' => $base,
                        'description' => $body,
                        'work_in_progress' => false,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $prData = new PullRequestData($title, $head, $base, $body);
        $result = $this->gitlabProvider->createPullRequest($prData);

        $this->assertSame(1, $result['number']); // Normalized to use iid as number
        $this->assertSame($title, $result['title']);
        $this->assertSame($head, $result['head']['ref']);
        $this->assertSame($base, $result['base']['ref']);
        $this->assertFalse($result['draft']);
        $this->assertSame('open', $result['state']);
    }

    public function testCreatePullRequestWithDraft(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $title = 'Test Draft MR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a draft merge request.';
        $gitlabResponse = [
            'iid' => 1,
            'title' => $title,
            'source_branch' => $head,
            'target_branch' => $base,
            'description' => $body,
            'work_in_progress' => true,
            'state' => 'opened',
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/' . self::PROJECT_PATH . '/merge_requests',
                $this->callback(function ($options) {
                    return isset($options['json']['work_in_progress']) && $options['json']['work_in_progress'] === true;
                })
            )
            ->willReturn($responseMock);

        $prData = new PullRequestData($title, $head, $base, $body, true);
        $result = $this->gitlabProvider->createPullRequest($prData);

        $this->assertTrue($result['draft']);
    }

    public function testCreatePullRequestAssignsToAuthenticatedAuthorWhenRequested(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $title = 'Assigned MR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'Assigned merge request.';
        $gitlabResponse = [
            'iid' => 1,
            'id' => 123,
            'title' => $title,
            'source_branch' => $head,
            'target_branch' => $base,
            'description' => $body,
            'work_in_progress' => false,
            'state' => 'opened',
            'web_url' => 'https://gitlab.com/test_owner/test_repo/-/merge_requests/1',
            'assignees' => [['id' => 42, 'username' => 'alice']],
        ];

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['id' => 42, 'username' => 'alice']);

        $createResponse = $this->createStub(ResponseInterface::class);
        $createResponse->method('getStatusCode')->willReturn(201);
        $createResponse->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options = []) use ($userResponse, $createResponse) {
                if ($method === 'GET' && $url === '/user') {
                    return $userResponse;
                }
                if ($method === 'POST' && $url === '/projects/' . self::PROJECT_PATH . '/merge_requests') {
                    $this->assertSame([42], $options['json']['assignee_ids']);

                    return $createResponse;
                }

                throw new \RuntimeException("Unexpected request {$method} {$url}");
            });

        $prData = new PullRequestData($title, $head, $base, $body, false, true);
        $result = $this->gitlabProvider->createPullRequest($prData);

        $this->assertSame(1, $result['number']);
        $this->assertSame($title, $result['title']);
    }

    public function testCreatePullRequestAssignmentFailsWhenGitLabDoesNotConfirmAssignee(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['id' => 42]);

        $createResponse = $this->createStub(ResponseInterface::class);
        $createResponse->method('getStatusCode')->willReturn(201);
        $createResponse->method('toArray')->willReturn([
            'iid' => 1,
            'web_url' => 'https://gitlab.com/test_owner/test_repo/-/merge_requests/1',
            'assignees' => [],
        ]);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($userResponse, $createResponse);

        $this->expectException(\App\Exception\PullRequestAssignmentException::class);
        $this->expectExceptionMessage("GitLab did not confirm user '42'");

        $this->gitlabProvider->createPullRequest(new PullRequestData('Title', 'feature/test', 'develop', 'Body', false, true));
    }

    public function testAssignPullRequestToAuthorAssignsAuthenticatedUser(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['id' => 42, 'username' => 'alice']);

        $assignResponse = $this->createStub(ResponseInterface::class);
        $assignResponse->method('getStatusCode')->willReturn(200);
        $assignResponse->method('toArray')->willReturn([
            'iid' => 1,
            'assignees' => [['id' => 42]],
        ]);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options = []) use ($userResponse, $assignResponse) {
                if ($method === 'GET' && $url === '/user') {
                    return $userResponse;
                }
                if ($method === 'PUT' && $url === '/projects/' . self::PROJECT_PATH . '/merge_requests/1') {
                    $this->assertSame(['assignee_ids' => [42]], $options['json']);

                    return $assignResponse;
                }

                throw new \RuntimeException("Unexpected request {$method} {$url}");
            });

        $this->gitlabProvider->assignPullRequestToAuthor(['number' => 1]);

        $this->addToAssertionCount(1);
    }

    public function testAssignPullRequestToAuthorRequiresMergeRequestNumber(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $this->httpClientMock->expects($this->never())->method('request');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot assign merge request because its number is missing.');

        $this->gitlabProvider->assignPullRequestToAuthor([]);
    }

    public function testAssignPullRequestToAuthorRequiresAuthenticatedUserId(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['username' => 'alice']);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/user')
            ->willReturn($userResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot assign merge request because the authenticated GitLab user id is missing.');

        $this->gitlabProvider->assignPullRequestToAuthor(['number' => 1]);
    }

    public function testAssignPullRequestToAuthorFailsWhenGitLabDoesNotConfirmAssignee(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['id' => 42]);

        $assignResponse = $this->createStub(ResponseInterface::class);
        $assignResponse->method('getStatusCode')->willReturn(200);
        $assignResponse->method('toArray')->willReturn([
            'iid' => 1,
            'assignees' => 'alice',
        ]);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($userResponse, $assignResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("GitLab did not confirm user '42'");

        $this->gitlabProvider->assignPullRequestToAuthor(['number' => 1]);
    }

    public function testCreatePullRequestFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $title = 'Test MR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test merge request.';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to create merge request.');

        $prData = new PullRequestData($title, $head, $base, $body);
        $this->gitlabProvider->createPullRequest($prData);
    }

    public function testFindPullRequestByBranchSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $branchName = 'feature/test';
        $gitlabResponse = [
            [
                'iid' => 123,
                'id' => 456,
                'title' => 'Test MR',
                'source_branch' => $branchName,
                'target_branch' => 'develop',
                'work_in_progress' => false,
                'state' => 'opened',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/projects/' . self::PROJECT_PATH . '/merge_requests?source_branch=' . urlencode($branchName) . '&state=opened'
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->findPullRequestByBranch($head);

        $this->assertNotNull($result);
        $this->assertSame(123, $result['number']);
        $this->assertSame($branchName, $result['head']['ref']);
    }

    public function testFindPullRequestByBranchNotFound(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $branchName = 'feature/test';
        $gitlabResponse = [];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->findPullRequestByBranch($head);

        $this->assertNull($result);
    }

    public function testFindPullRequestByBranchName(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $branchName = 'feature/test';
        $gitlabResponse = [
            [
                'iid' => 123,
                'title' => 'Test MR',
                'source_branch' => $branchName,
                'target_branch' => 'develop',
                'state' => 'opened',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->findPullRequestByBranchName($branchName);

        $this->assertNotNull($result);
        $this->assertSame(123, $result['number']);
    }

    public function testAddLabelsToPullRequestSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123; // This is the iid in GitLab
        $labels = ['bug', 'enhancement'];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/' . self::PROJECT_PATH . '/merge_requests/' . $issueNumber . '/labels',
                [
                    'json' => [
                        'labels' => 'bug,enhancement',
                    ],
                ]
            )
            ->willReturn($responseMock);

        $this->gitlabProvider->addLabelsToPullRequest($issueNumber, $labels);
    }

    public function testAddLabelsToPullRequestFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $labels = ['bug'];
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to add labels to merge request #{$issueNumber}.");

        $this->gitlabProvider->addLabelsToPullRequest($issueNumber, $labels);
    }

    public function testCreateCommentSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123; // This is the iid in GitLab
        $body = 'This is a test comment.';
        $expectedResponse = ['id' => 456, 'body' => $body];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/' . self::PROJECT_PATH . '/merge_requests/' . $issueNumber . '/notes',
                [
                    'json' => [
                        'body' => $body,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->createComment($issueNumber, $body);

        $this->assertSame($expectedResponse, $result);
    }

    public function testReplyToPullRequestFeedbackPostsDiscussionNote(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $body = 'Thanks';
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn(['id' => 456, 'body' => $body]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/' . self::PROJECT_PATH . '/merge_requests/123/discussions/discussion-id/notes',
                ['json' => ['body' => $body]]
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->replyToPullRequestFeedback(
            123,
            new PullRequestFeedbackIds('gitlab', 'review_thread', discussionId: 'discussion-id'),
            $body
        );

        $this->assertSame(456, $result['id']);
    }

    public function testResolvePullRequestFeedbackUpdatesDiscussion(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn(['id' => 'discussion-id', 'resolved' => true]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                '/projects/' . self::PROJECT_PATH . '/merge_requests/123/discussions/discussion-id',
                ['json' => ['resolved' => true]]
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->resolvePullRequestFeedback(
            123,
            new PullRequestFeedbackIds('gitlab', 'review_thread', discussionId: 'discussion-id')
        );

        $this->assertTrue($result['resolved']);
    }

    public function testReplyToPullRequestFeedbackRejectsMissingDiscussionId(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->gitlabProvider->replyToPullRequestFeedback(
            123,
            new PullRequestFeedbackIds('gitlab', 'review_thread'),
            'Thanks'
        );
    }

    public function testUpdatePullRequestToDraft(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123; // This is the iid in GitLab
        $draft = true;
        $gitlabResponse = [
            'iid' => $pullNumber,
            'work_in_progress' => true,
            'state' => 'opened',
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                '/projects/' . self::PROJECT_PATH . '/merge_requests/' . $pullNumber,
                [
                    'json' => [
                        'work_in_progress' => $draft,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->updatePullRequest($pullNumber, $draft);

        $this->assertTrue($result['draft']);
    }

    public function testGetLabelsSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $expectedResponse = [
            ['name' => 'bug', 'color' => 'd73a4a'],
            ['name' => 'enhancement', 'color' => 'a2eeef'],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/projects/' . self::PROJECT_PATH . '/labels'
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getLabels();

        $this->assertSame($expectedResponse, $result);
    }

    public function testCreateLabelSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $name = 'new-label';
        $color = '#ff0000';
        $description = 'A new label';
        $expectedResponse = ['name' => $name, 'color' => 'ff0000', 'description' => $description];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/' . self::PROJECT_PATH . '/labels',
                [
                    'json' => [
                        'name' => $name,
                        'color' => 'ff0000', // # removed
                        'description' => $description,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->createLabel($name, $color, $description);

        $this->assertSame($expectedResponse, $result);
    }

    public function testGetAllPullRequestsWithStateAll(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $openedMrs = [
            ['iid' => 1, 'title' => 'Open MR', 'source_branch' => 'feature/branch1', 'state' => 'opened'],
        ];
        $closedMrs = [
            ['iid' => 2, 'title' => 'Closed MR', 'source_branch' => 'feature/branch2', 'state' => 'closed'],
        ];
        $mergedMrs = [
            ['iid' => 3, 'title' => 'Merged MR', 'source_branch' => 'feature/branch3', 'state' => 'merged'],
        ];

        $openedResponseMock = $this->createStub(ResponseInterface::class);
        $openedResponseMock->method('getStatusCode')->willReturn(200);
        $openedResponseMock->method('toArray')->willReturn($openedMrs);
        $openedResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $closedResponseMock = $this->createStub(ResponseInterface::class);
        $closedResponseMock->method('getStatusCode')->willReturn(200);
        $closedResponseMock->method('toArray')->willReturn($closedMrs);
        $closedResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $mergedResponseMock = $this->createStub(ResponseInterface::class);
        $mergedResponseMock->method('getStatusCode')->willReturn(200);
        $mergedResponseMock->method('toArray')->willReturn($mergedMrs);
        $mergedResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $this->httpClientMock->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($openedResponseMock, $closedResponseMock, $mergedResponseMock) {
                if (str_contains($url, 'state=opened')) {
                    return $openedResponseMock;
                }
                if (str_contains($url, 'state=closed')) {
                    return $closedResponseMock;
                }
                if (str_contains($url, 'state=merged')) {
                    return $mergedResponseMock;
                }

                throw new \RuntimeException("Unexpected URL: {$url}");
            });

        $result = $this->gitlabProvider->getAllPullRequests('all');

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]['number']);
        $this->assertSame(2, $result[1]['number']);
        $this->assertSame(3, $result[2]['number']);
    }

    public function testGetAllPullRequestsWithPagination(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $page1Response = [
            ['iid' => 1, 'title' => 'MR 1', 'source_branch' => 'feature/branch1', 'state' => 'opened'],
        ];
        $page2Response = [
            ['iid' => 2, 'title' => 'MR 2', 'source_branch' => 'feature/branch2', 'state' => 'opened'],
        ];

        $page1ResponseMock = $this->createStub(ResponseInterface::class);
        $page1ResponseMock->method('getStatusCode')->willReturn(200);
        $page1ResponseMock->method('toArray')->willReturn($page1Response);
        $page1ResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['2']]);

        $page2ResponseMock = $this->createStub(ResponseInterface::class);
        $page2ResponseMock->method('getStatusCode')->willReturn(200);
        $page2ResponseMock->method('toArray')->willReturn($page2Response);
        $page2ResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['2']]);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($page1ResponseMock, $page2ResponseMock) {
                if (preg_match('/[?&]page=1(?=&|$)/', $url)) {
                    return $page1ResponseMock;
                }
                if (preg_match('/[?&]page=2(?=&|$)/', $url)) {
                    return $page2ResponseMock;
                }

                throw new \RuntimeException("Unexpected URL: {$url}");
            });

        $result = $this->gitlabProvider->getAllPullRequests('opened');

        $this->assertCount(2, $result);
    }

    public function testExtractBranchNameFromOwnerBranchFormat(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('extractBranchName');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->gitlabProvider, 'owner:branch-name');

        $this->assertSame('branch-name', $result);
    }

    public function testExtractBranchNameFromBranchOnly(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('extractBranchName');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->gitlabProvider, 'branch-name');

        $this->assertSame('branch-name', $result);
    }

    public function testNormalizeColorRemovesHash(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('normalizeColor');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->gitlabProvider, '#ff0000');

        $this->assertSame('ff0000', $result);
    }

    public function testNormalizeColorKeepsColorWithoutHash(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('normalizeColor');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->gitlabProvider, 'ff0000');

        $this->assertSame('ff0000', $result);
    }

    public function testFindPullRequestByBranchWithStateClosed(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $branchName = 'feature/test';
        $gitlabResponse = [
            [
                'iid' => 123,
                'title' => 'Closed MR',
                'source_branch' => $branchName,
                'target_branch' => 'develop',
                'state' => 'closed',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/projects/' . self::PROJECT_PATH . '/merge_requests?source_branch=' . urlencode($branchName) . '&state=closed'
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->findPullRequestByBranch($head, 'closed');

        $this->assertNotNull($result);
        $this->assertSame(123, $result['number']);
    }

    public function testFindPullRequestByBranchWithStateAllReturnsOpenFirst(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $branchName = 'feature/test';
        $openResponse = [
            [
                'iid' => 123,
                'title' => 'Open MR',
                'source_branch' => $branchName,
                'state' => 'opened',
            ],
        ];

        $openResponseMock = $this->createStub(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($openResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($openResponseMock);

        $result = $this->gitlabProvider->findPullRequestByBranch($head, 'all');

        $this->assertNotNull($result);
        $this->assertSame(123, $result['number']);
    }

    public function testFindPullRequestByBranchWithStateAllReturnsClosedWhenOpenNotFound(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $branchName = 'feature/test';
        $openResponse = [];
        $closedResponse = [
            [
                'iid' => 123,
                'title' => 'Closed MR',
                'source_branch' => $branchName,
                'state' => 'closed',
            ],
        ];

        $openResponseMock = $this->createStub(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($openResponse);

        $closedResponseMock = $this->createStub(ResponseInterface::class);
        $closedResponseMock->method('getStatusCode')->willReturn(200);
        $closedResponseMock->method('toArray')->willReturn($closedResponse);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($openResponseMock, $closedResponseMock) {
                if (str_contains($url, 'state=opened')) {
                    return $openResponseMock;
                }
                if (str_contains($url, 'state=closed')) {
                    return $closedResponseMock;
                }

                throw new \RuntimeException("Unexpected URL: {$url}");
            });

        $result = $this->gitlabProvider->findPullRequestByBranch($head, 'all');

        $this->assertNotNull($result);
        $this->assertSame(123, $result['number']);
    }

    public function testFindPullRequestByBranchFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to find merge request by branch.');

        $this->gitlabProvider->findPullRequestByBranch($head);
    }

    public function testUpdatePullRequestFromDraft(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $draft = false;
        $gitlabResponse = [
            'iid' => $pullNumber,
            'work_in_progress' => false,
            'state' => 'opened',
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->updatePullRequest($pullNumber, $draft);

        $this->assertFalse($result['draft']);
    }

    public function testUpdatePullRequestFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $draft = true;
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to update merge request #{$pullNumber}.");

        $this->gitlabProvider->updatePullRequest($pullNumber, $draft);
    }

    public function testCreateCommentFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $body = 'This is a test comment.';
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to create comment on merge request #{$issueNumber}.");

        $this->gitlabProvider->createComment($issueNumber, $body);
    }

    public function testGetPullRequestCommentsSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $apiResponse = [
            [
                'author' => ['username' => 'alice'],
                'body' => 'MR note body',
                'created_at' => '2025-01-15T10:00:00Z',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains('/projects/' . self::PROJECT_PATH . '/merge_requests/123/notes')
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getPullRequestComments($issueNumber);

        $this->assertCount(1, $result);
        $this->assertSame('alice', $result[0]->author);
        $this->assertSame('MR note body', $result[0]->body);
        $this->assertNull($result[0]->path);
        $this->assertNull($result[0]->line);
    }

    public function testGetPullRequestReviewCommentsSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [
            [
                'id' => 'disc-1',
                'notes' => [
                    [
                        'author' => ['username' => 'bob'],
                        'body' => 'Inline review',
                        'created_at' => '2025-01-15T11:00:00Z',
                    ],
                ],
                'position' => [
                    'new_path' => 'src/File.php',
                    'new_line' => 10,
                ],
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains('/projects/' . self::PROJECT_PATH . '/merge_requests/123/discussions')
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getPullRequestReviewComments($pullNumber);

        $this->assertCount(1, $result);
        $this->assertSame('bob', $result[0]->author);
        $this->assertSame('Inline review', $result[0]->body);
        $this->assertSame('src/File.php', $result[0]->path);
        $this->assertSame(10, $result[0]->line);
    }

    public function testGetPullRequestReviewCommentsFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('Not Found');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('/projects/' . self::PROJECT_PATH . '/merge_requests/123/discussions'))
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to get review comments for merge request #{$pullNumber}.");

        $this->gitlabProvider->getPullRequestReviewComments($pullNumber);
    }

    public function testGetPullRequestReviewsReturnsEmpty(): void
    {
        $pullNumber = 123;

        $result = $this->gitlabProvider->getPullRequestReviews($pullNumber);

        $this->assertSame([], $result);
    }

    public function testGetPullRequestFeedbackConversationsPreservesDiscussions(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $apiResponse = [[
            'id' => 'discussion-1',
            'individual_note' => false,
            'position' => [
                'new_path' => 'src/File.php',
                'new_line' => 10,
                'position_type' => 'text',
            ],
            'notes' => [[
                'id' => 99,
                'author' => ['username' => 'alice'],
                'body' => 'Inline discussion',
                'created_at' => '2025-01-15T10:00:00Z',
                'resolved' => false,
                'resolvable' => true,
                'type' => 'DiffNote',
            ]],
        ]];
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);
        $responseMock->method('getHeaders')->willReturn([]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('/projects/' . self::PROJECT_PATH . '/merge_requests/123/discussions'))
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getPullRequestFeedbackConversations(123);

        $this->assertCount(1, $result);
        $this->assertSame('review_thread', $result[0]->type);
        $this->assertSame('discussion-1', $result[0]->ids->discussionId);
        $this->assertSame('gitlab:review_thread:discussion-1', $result[0]->ids->target);
        $this->assertSame('99', $result[0]->comments[0]->ids->noteId);
        $this->assertTrue($result[0]->actions->canResolve);
        $this->assertSame('src/File.php', $result[0]->location?->path);
    }

    public function testGetPullRequestFeedbackConversationsPaginatesAndSorts(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $firstResponse = $this->createStub(ResponseInterface::class);
        $firstResponse->method('getStatusCode')->willReturn(200);
        $firstResponse->method('toArray')->willReturn([[
            'id' => 'later',
            'notes' => [[
                'id' => 2,
                'author' => ['username' => 'bob'],
                'body' => 'Later',
                'created_at' => '2025-01-15T12:00:00Z',
            ]],
        ]]);
        $firstResponse->method('getHeaders')->willReturn(['x-next-page' => ['2']]);

        $secondResponse = $this->createStub(ResponseInterface::class);
        $secondResponse->method('getStatusCode')->willReturn(200);
        $secondResponse->method('toArray')->willReturn([[
            'id' => 'earlier',
            'notes' => [[
                'id' => 1,
                'author' => ['username' => 'alice'],
                'body' => 'Earlier',
                'created_at' => '2025-01-15T10:00:00Z',
            ]],
        ]]);
        $secondResponse->method('getHeaders')->willReturn(['x-next-page' => ['']]);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($firstResponse, $secondResponse);

        $result = $this->gitlabProvider->getPullRequestFeedbackConversations(123);

        $this->assertSame('earlier', $result[0]->ids->discussionId);
        $this->assertSame('later', $result[1]->ids->discussionId);
    }

    public function testGetPullRequestFeedbackConversationsPaginatesWithTotalPagesHeader(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $firstResponse = $this->createStub(ResponseInterface::class);
        $firstResponse->method('getStatusCode')->willReturn(200);
        $firstResponse->method('toArray')->willReturn([[
            'id' => 'first',
            'notes' => [[
                'id' => 1,
                'author' => ['username' => 'alice'],
                'body' => 'First',
                'created_at' => '2025-01-15T10:00:00Z',
            ]],
        ]]);
        $firstResponse->method('getHeaders')->willReturn(['x-total-pages' => ['2']]);

        $secondResponse = $this->createStub(ResponseInterface::class);
        $secondResponse->method('getStatusCode')->willReturn(200);
        $secondResponse->method('toArray')->willReturn([[
            'id' => 'second',
            'notes' => [[
                'id' => 2,
                'author' => ['username' => 'bob'],
                'body' => 'Second',
                'created_at' => '2025-01-15T11:00:00Z',
            ]],
        ]]);
        $secondResponse->method('getHeaders')->willReturn(['x-total-pages' => ['2']]);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($firstResponse, $secondResponse);

        $result = $this->gitlabProvider->getPullRequestFeedbackConversations(123);

        $this->assertCount(2, $result);
    }

    public function testGetPullRequestCommentsFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('Not Found');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to get comments for merge request #{$issueNumber}.");

        $this->gitlabProvider->getPullRequestComments($issueNumber);
    }

    public function testGetPullRequestReviewCommentsSkipsDiscussionWithoutPosition(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [
            [
                'id' => 'disc-1',
                'notes' => [['author' => ['username' => 'alice'], 'body' => 'Note', 'created_at' => '2025-01-15T10:00:00Z']],
                'position' => null,
            ],
            [
                'id' => 'disc-2',
                'notes' => [
                    ['author' => ['username' => 'bob'], 'body' => 'Inline', 'created_at' => '2025-01-15T11:00:00Z'],
                ],
                'position' => ['old_path' => 'src/Old.php', 'old_line' => 5],
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getPullRequestReviewComments($pullNumber);

        $this->assertCount(1, $result);
        $this->assertSame('bob', $result[0]->author);
        $this->assertSame('src/Old.php', $result[0]->path);
        $this->assertSame(5, $result[0]->line);
    }

    public function testGetPullRequestReviewCommentsSkipsDiscussionWhenPositionIsNotArray(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [
            [
                'id' => 'disc-1',
                'notes' => [['author' => ['username' => 'alice'], 'body' => 'Note', 'created_at' => '2025-01-15T10:00:00Z']],
                'position' => 'invalid',
            ],
            [
                'id' => 'disc-2',
                'notes' => [['author' => ['username' => 'bob'], 'body' => 'OK', 'created_at' => '2025-01-15T11:00:00Z']],
                'position' => ['new_path' => 'src/File.php', 'new_line' => 1],
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getPullRequestReviewComments($pullNumber);

        $this->assertCount(1, $result);
        $this->assertSame('bob', $result[0]->author);
    }

    public function testGetPullRequestReviewCommentsStopsAtCapWhenDiscussionHasManyNotes(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $notes = [];
        for ($i = 0; $i < 51; $i++) {
            $notes[] = [
                'author' => ['username' => 'user'],
                'body' => "Note {$i}",
                'created_at' => '2025-01-15T10:00:00Z',
            ];
        }
        $apiResponse = [
            [
                'id' => 'disc-1',
                'notes' => $notes,
                'position' => ['new_path' => 'src/File.php', 'new_line' => 10],
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getPullRequestReviewComments($pullNumber);

        $this->assertCount(50, $result);
    }

    public function testGetPullRequestReviewCommentsStopsAtCapBeforeNextDiscussion(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $notes = [];
        for ($i = 0; $i < 50; $i++) {
            $notes[] = [
                'author' => ['username' => 'user'],
                'body' => "Note {$i}",
                'created_at' => '2025-01-15T10:00:00Z',
            ];
        }
        $apiResponse = [
            [
                'id' => 'disc-1',
                'notes' => $notes,
                'position' => ['new_path' => 'src/File.php', 'new_line' => 10],
            ],
            [
                'id' => 'disc-2',
                'notes' => [['author' => ['username' => 'other'], 'body' => 'Extra', 'created_at' => '2025-01-15T12:00:00Z']],
                'position' => ['new_path' => 'src/Other.php', 'new_line' => 5],
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getPullRequestReviewComments($pullNumber);

        $this->assertCount(50, $result);
    }

    public function testGetLabelsFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get labels.');

        $this->gitlabProvider->getLabels();
    }

    public function testCreateLabelWithoutDescription(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $name = 'new-label';
        $color = 'ff0000';
        $expectedResponse = ['name' => $name, 'color' => $color];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/' . self::PROJECT_PATH . '/labels',
                $this->callback(function ($options) {
                    return ! isset($options['json']['description']);
                })
            )
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->createLabel($name, $color);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCreateLabelFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $name = 'new-label';
        $color = 'ff0000';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to create label '{$name}'.");

        $this->gitlabProvider->createLabel($name, $color);
    }

    public function testGetAllPullRequestsWithStateOpen(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $openedMrs = [
            ['iid' => 1, 'title' => 'Open MR', 'source_branch' => 'feature/branch1', 'state' => 'opened'],
        ];

        $openedResponseMock = $this->createStub(ResponseInterface::class);
        $openedResponseMock->method('getStatusCode')->willReturn(200);
        $openedResponseMock->method('toArray')->willReturn($openedMrs);
        $openedResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($openedResponseMock);

        $result = $this->gitlabProvider->getAllPullRequests('open');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['number']);
    }

    public function testGetAllPullRequestsWithStateClosed(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $closedMrs = [
            ['iid' => 2, 'title' => 'Closed MR', 'source_branch' => 'feature/branch2', 'state' => 'closed'],
        ];

        $closedResponseMock = $this->createStub(ResponseInterface::class);
        $closedResponseMock->method('getStatusCode')->willReturn(200);
        $closedResponseMock->method('toArray')->willReturn($closedMrs);
        $closedResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($closedResponseMock);

        $result = $this->gitlabProvider->getAllPullRequests('closed');

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['number']);
    }

    public function testGetAllPullRequestsFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get all merge requests.');

        $this->gitlabProvider->getAllPullRequests('open');
    }

    public function testGetAllPullRequestsWithEmptyResult(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([]);
        $responseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getAllPullRequests('open');

        $this->assertSame([], $result);
    }

    public function testCreatePullRequestWithNestedGroupPath(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $nestedGroupProvider = $this->createProvider('group/subgroup');

        $title = 'Test MR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test merge request.';
        $gitlabResponse = [
            'iid' => 1,
            'id' => 123,
            'title' => $title,
            'source_branch' => $head,
            'target_branch' => $base,
            'description' => $body,
            'work_in_progress' => false,
            'state' => 'opened',
            'web_url' => 'https://gitlab.com/group/subgroup/test_repo/-/merge_requests/1',
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/group%2Fsubgroup%2Ftest_repo/merge_requests',
                [
                    'json' => [
                        'title' => $title,
                        'source_branch' => $head,
                        'target_branch' => $base,
                        'description' => $body,
                        'work_in_progress' => false,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $prData = new PullRequestData($title, $head, $base, $body);
        $result = $nestedGroupProvider->createPullRequest($prData);

        $this->assertSame(1, $result['number']);
        $this->assertSame($title, $result['title']);
    }

    public function testCreatePullRequestWithDeeplyNestedGroupPath(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $deeplyNestedProvider = $this->createProvider('group/subgroup/subsubgroup');

        $title = 'Test MR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test merge request.';
        $gitlabResponse = [
            'iid' => 1,
            'id' => 123,
            'title' => $title,
            'source_branch' => $head,
            'target_branch' => $base,
            'description' => $body,
            'work_in_progress' => false,
            'state' => 'opened',
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($gitlabResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/projects/group%2Fsubgroup%2Fsubsubgroup%2Ftest_repo/merge_requests',
                $this->anything()
            )
            ->willReturn($responseMock);

        $prData = new PullRequestData($title, $head, $base, $body);
        $result = $deeplyNestedProvider->createPullRequest($prData);

        $this->assertSame(1, $result['number']);
    }

    private function bindSubject(): void
    {
        $this->gitlabProvider = $this->createProvider(self::GITLAB_OWNER);
    }

    private function createProvider(string $owner): GitLabGitHostingAdapter
    {
        return new GitLabGitHostingAdapter(new GitLabApiClient(
            self::GITLAB_TOKEN,
            new GitLabProject($owner, self::GITLAB_REPO),
            $this->httpClientMock
        ));
    }
}
