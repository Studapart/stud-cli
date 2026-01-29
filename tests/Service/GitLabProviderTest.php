<?php

namespace App\Tests\Service;

use App\DTO\PullRequestData;
use App\Service\GitLabProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitLabProviderTest extends TestCase
{
    private const GITLAB_TOKEN = 'test_token';
    private const GITLAB_OWNER = 'test_owner';
    private const GITLAB_REPO = 'test_repo';
    private const PROJECT_PATH = 'test_owner%2Ftest_repo';

    private GitLabProvider $gitlabProvider;
    private HttpClientInterface&MockObject $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->gitlabProvider = new GitLabProvider(
            self::GITLAB_TOKEN,
            self::GITLAB_OWNER,
            self::GITLAB_REPO,
            null, // Use default gitlab.com
            $this->httpClientMock
        );
    }

    public function testCreatePullRequestSuccess(): void
    {
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

        $responseMock = $this->createMock(ResponseInterface::class);
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

        $responseMock = $this->createMock(ResponseInterface::class);
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

    public function testCreatePullRequestFailure(): void
    {
        $title = 'Test MR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test merge request.';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

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

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $head = 'test_owner:feature/test';
        $branchName = 'feature/test';
        $gitlabResponse = [];

        $responseMock = $this->createMock(ResponseInterface::class);
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

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $issueNumber = 123; // This is the iid in GitLab
        $labels = ['bug', 'enhancement'];

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $issueNumber = 123;
        $labels = ['bug'];
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to add labels to merge request #{$issueNumber}.");

        $this->gitlabProvider->addLabelsToPullRequest($issueNumber, $labels);
    }

    public function testCreateCommentSuccess(): void
    {
        $issueNumber = 123; // This is the iid in GitLab
        $body = 'This is a test comment.';
        $expectedResponse = ['id' => 456, 'body' => $body];

        $responseMock = $this->createMock(ResponseInterface::class);
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

    public function testUpdatePullRequestToDraft(): void
    {
        $pullNumber = 123; // This is the iid in GitLab
        $draft = true;
        $gitlabResponse = [
            'iid' => $pullNumber,
            'work_in_progress' => true,
            'state' => 'opened',
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $expectedResponse = [
            ['name' => 'bug', 'color' => 'd73a4a'],
            ['name' => 'enhancement', 'color' => 'a2eeef'],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $name = 'new-label';
        $color = '#ff0000';
        $description = 'A new label';
        $expectedResponse = ['name' => $name, 'color' => 'ff0000', 'description' => $description];

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $openedMrs = [
            ['iid' => 1, 'title' => 'Open MR', 'source_branch' => 'feature/branch1', 'state' => 'opened'],
        ];
        $closedMrs = [
            ['iid' => 2, 'title' => 'Closed MR', 'source_branch' => 'feature/branch2', 'state' => 'closed'],
        ];
        $mergedMrs = [
            ['iid' => 3, 'title' => 'Merged MR', 'source_branch' => 'feature/branch3', 'state' => 'merged'],
        ];

        $openedResponseMock = $this->createMock(ResponseInterface::class);
        $openedResponseMock->method('getStatusCode')->willReturn(200);
        $openedResponseMock->method('toArray')->willReturn($openedMrs);
        $openedResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $closedResponseMock = $this->createMock(ResponseInterface::class);
        $closedResponseMock->method('getStatusCode')->willReturn(200);
        $closedResponseMock->method('toArray')->willReturn($closedMrs);
        $closedResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $mergedResponseMock = $this->createMock(ResponseInterface::class);
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
        $page1Response = [
            ['iid' => 1, 'title' => 'MR 1', 'source_branch' => 'feature/branch1', 'state' => 'opened'],
        ];
        $page2Response = [
            ['iid' => 2, 'title' => 'MR 2', 'source_branch' => 'feature/branch2', 'state' => 'opened'],
        ];

        $page1ResponseMock = $this->createMock(ResponseInterface::class);
        $page1ResponseMock->method('getStatusCode')->willReturn(200);
        $page1ResponseMock->method('toArray')->willReturn($page1Response);
        $page1ResponseMock->method('getHeaders')->willReturn(['x-total-pages' => ['2']]);

        $page2ResponseMock = $this->createMock(ResponseInterface::class);
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
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, 'owner:branch-name');

        $this->assertSame('branch-name', $result);
    }

    public function testExtractBranchNameFromBranchOnly(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('extractBranchName');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, 'branch-name');

        $this->assertSame('branch-name', $result);
    }

    public function testNormalizeColorRemovesHash(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('normalizeColor');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, '#ff0000');

        $this->assertSame('ff0000', $result);
    }

    public function testNormalizeColorKeepsColorWithoutHash(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('normalizeColor');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, 'ff0000');

        $this->assertSame('ff0000', $result);
    }

    public function testMapStateToGitLab(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('mapStateToGitLab');
        $method->setAccessible(true);

        $this->assertSame('opened', $method->invoke($this->gitlabProvider, 'open'));
        $this->assertSame('closed', $method->invoke($this->gitlabProvider, 'closed'));
        $this->assertSame('all', $method->invoke($this->gitlabProvider, 'all'));
    }

    public function testMapStateFromGitLab(): void
    {
        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('mapStateFromGitLab');
        $method->setAccessible(true);

        $this->assertSame('open', $method->invoke($this->gitlabProvider, 'opened'));
        $this->assertSame('closed', $method->invoke($this->gitlabProvider, 'closed'));
        $this->assertSame('closed', $method->invoke($this->gitlabProvider, 'merged'));
    }

    public function testFindPullRequestByBranchWithStateClosed(): void
    {
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

        $responseMock = $this->createMock(ResponseInterface::class);
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

        $openResponseMock = $this->createMock(ResponseInterface::class);
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

        $openResponseMock = $this->createMock(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($openResponse);

        $closedResponseMock = $this->createMock(ResponseInterface::class);
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
        $head = 'test_owner:feature/test';
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to find merge request by branch.');

        $this->gitlabProvider->findPullRequestByBranch($head);
    }

    public function testUpdatePullRequestFromDraft(): void
    {
        $pullNumber = 123;
        $draft = false;
        $gitlabResponse = [
            'iid' => $pullNumber,
            'work_in_progress' => false,
            'state' => 'opened',
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $pullNumber = 123;
        $draft = true;
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to update merge request #{$pullNumber}.");

        $this->gitlabProvider->updatePullRequest($pullNumber, $draft);
    }

    public function testCreateCommentFailure(): void
    {
        $issueNumber = 123;
        $body = 'This is a test comment.';
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to create comment on merge request #{$issueNumber}.");

        $this->gitlabProvider->createComment($issueNumber, $body);
    }

    public function testGetLabelsFailure(): void
    {
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get labels.');

        $this->gitlabProvider->getLabels();
    }

    public function testCreateLabelWithoutDescription(): void
    {
        $name = 'new-label';
        $color = 'ff0000';
        $expectedResponse = ['name' => $name, 'color' => $color];

        $responseMock = $this->createMock(ResponseInterface::class);
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
        $name = 'new-label';
        $color = 'ff0000';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to create label '{$name}'.");

        $this->gitlabProvider->createLabel($name, $color);
    }

    public function testGetAllPullRequestsWithStateOpen(): void
    {
        $openedMrs = [
            ['iid' => 1, 'title' => 'Open MR', 'source_branch' => 'feature/branch1', 'state' => 'opened'],
        ];

        $openedResponseMock = $this->createMock(ResponseInterface::class);
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
        $closedMrs = [
            ['iid' => 2, 'title' => 'Closed MR', 'source_branch' => 'feature/branch2', 'state' => 'closed'],
        ];

        $closedResponseMock = $this->createMock(ResponseInterface::class);
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
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get all merge requests.');

        $this->gitlabProvider->getAllPullRequests('open');
    }

    public function testGetAllPullRequestsWithEmptyResult(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([]);
        $responseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->gitlabProvider->getAllPullRequests('open');

        $this->assertSame([], $result);
    }

    public function testHasNextPageWithXTotalPagesHeader(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getHeaders')->willReturn(['x-total-pages' => ['2']]);

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('hasNextPage');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 1);

        $this->assertTrue($result);
    }

    public function testHasNextPageWithXNextPageHeader(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getHeaders')->willReturn(['x-next-page' => ['2']]);

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('hasNextPage');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 1);

        $this->assertTrue($result);
    }

    public function testHasNextPageReturnsFalseWhenNoNextPage(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getHeaders')->willReturn(['x-total-pages' => ['1']]);

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('hasNextPage');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 1);

        $this->assertFalse($result);
    }

    public function testHasNextPageReturnsFalseWhenNoHeaders(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getHeaders')->willReturn([]);

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('hasNextPage');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 1);

        $this->assertFalse($result);
    }

    public function testNormalizeMergeRequestData(): void
    {
        $gitlabMr = [
            'iid' => 123,
            'id' => 456,
            'title' => 'Test MR',
            'source_branch' => 'feature/test',
            'target_branch' => 'develop',
            'work_in_progress' => true,
            'state' => 'opened',
            'description' => 'Test description',
            'web_url' => 'https://gitlab.com/test_owner/test_repo/-/merge_requests/123',
        ];

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('normalizeMergeRequestData');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $gitlabMr);

        $this->assertSame(123, $result['number']);
        $this->assertSame('Test MR', $result['title']);
        $this->assertSame('feature/test', $result['head']['ref']);
        $this->assertSame('develop', $result['base']['ref']);
        $this->assertTrue($result['draft']);
        $this->assertSame('open', $result['state']);
        $this->assertSame('Test description', $result['body']);
        $this->assertSame('https://gitlab.com/test_owner/test_repo/-/merge_requests/123', $result['html_url']);
        $this->assertArrayHasKey('_gitlab_data', $result);
    }

    public function testExtractTechnicalDetails(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn('Error message');

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('extractTechnicalDetails');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('GitLab API Error', $result);
        $this->assertStringContainsString('Status: 404', $result);
        $this->assertStringContainsString('GET', $result);
        $this->assertStringContainsString('/test/endpoint', $result);
        $this->assertStringContainsString('Error message', $result);
    }

    public function testExtractTechnicalDetailsWithTruncatedResponse(): void
    {
        $longResponse = str_repeat('A', 600);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn($longResponse);

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('extractTechnicalDetails');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('... (truncated)', $result);
    }

    public function testExtractTechnicalDetailsWithException(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willThrowException(new \Exception('Connection timeout'));

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('extractTechnicalDetails');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('Unable to read response body: Connection timeout', $result);
    }

    public function testExtractTechnicalDetailsWithEmptyResponse(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->with(false)->willReturn('');

        $reflection = new \ReflectionClass($this->gitlabProvider);
        $method = $reflection->getMethod('extractTechnicalDetails');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitlabProvider, $responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('No response body', $result);
    }
}
