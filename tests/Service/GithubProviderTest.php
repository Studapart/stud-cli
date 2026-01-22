<?php

namespace App\Tests\Service;

use App\DTO\PullRequestData;
use App\Service\GithubProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GithubProviderTest extends TestCase
{
    private const GITHUB_TOKEN = 'test_token';
    private const GITHUB_OWNER = 'test_owner';
    private const GITHUB_REPO = 'test_repo';

    private GithubProvider $githubProvider;
    private HttpClientInterface&MockObject $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->githubProvider = new GithubProvider(
            self::GITHUB_TOKEN,
            self::GITHUB_OWNER,
            self::GITHUB_REPO,
            $this->httpClientMock // Inject the mock client
        );
    }

    public function testCreatePullRequestSuccess(): void
    {
        $title = 'Test PR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test pull request.';
        $expectedResponse = ['html_url' => 'https://github.com/test_owner/test_repo/pull/1'];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls",
                [
                    'json' => [
                        'title' => $title,
                        'head' => $head,
                        'base' => $base,
                        'body' => $body,
                        'draft' => false,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $prData = new PullRequestData($title, $head, $base, $body);
        $result = $this->githubProvider->createPullRequest($prData);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCreatePullRequestWithDraft(): void
    {
        $title = 'Test Draft PR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a draft pull request.';
        $expectedResponse = ['html_url' => 'https://github.com/test_owner/test_repo/pull/1'];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls",
                [
                    'json' => [
                        'title' => $title,
                        'head' => $head,
                        'base' => $base,
                        'body' => $body,
                        'draft' => true,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $prData = new PullRequestData($title, $head, $base, $body, true);
        $result = $this->githubProvider->createPullRequest($prData);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCreatePullRequestFailure(): void
    {
        $title = 'Test PR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test pull request.';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 422\) when calling \'POST .*\'\.\nResponse: Validation Failed/');

        $prData = new PullRequestData($title, $head, $base, $body);
        $this->githubProvider->createPullRequest($prData);
    }

    public function testGetLatestReleaseSuccess(): void
    {
        $expectedResponse = [
            'tag_name' => 'v1.2.3',
            'name' => 'Release 1.2.3',
            'assets' => [],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/releases/latest"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getLatestRelease();

        $this->assertSame($expectedResponse, $result);
    }

    public function testGetLatestReleaseFailure(): void
    {
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 404\) when calling \'GET .*\'\.\nResponse: Not Found/');

        $this->githubProvider->getLatestRelease();
    }

    public function testGetChangelogContentSuccess(): void
    {
        $tag = 'v1.0.0';
        $changelogContent = '# Changelog\n\n## [1.0.0] - 2025-01-01\n\n### Added\n- Initial release';
        $encodedContent = base64_encode($changelogContent);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'content' => $encodedContent,
            'encoding' => 'base64',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/contents/CHANGELOG.md?ref=" . $tag
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getChangelogContent($tag);

        $this->assertSame($changelogContent, $result);
    }

    public function testGetChangelogContentFailure(): void
    {
        $tag = 'v1.0.0';
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 404\) when calling \'GET .*\'\.\nResponse: Not Found/');

        $this->githubProvider->getChangelogContent($tag);
    }

    public function testGetChangelogContentInvalidEncoding(): void
    {
        $tag = 'v1.0.0';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'content' => 'not-base64-content',
            'encoding' => 'text',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decode CHANGELOG.md content from GitHub API');

        $this->githubProvider->getChangelogContent($tag);
    }

    public function testGetChangelogContentMissingContent(): void
    {
        $tag = 'v1.0.0';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'encoding' => 'base64',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decode CHANGELOG.md content from GitHub API');

        $this->githubProvider->getChangelogContent($tag);
    }

    public function testGetChangelogContentBase64DecodeFailure(): void
    {
        $tag = 'v1.0.0';
        // Invalid base64 that will cause base64_decode to return false
        $invalidBase64 = '!!!invalid-base64!!!';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'content' => $invalidBase64,
            'encoding' => 'base64',
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decode base64 content from GitHub API');

        $this->githubProvider->getChangelogContent($tag);
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
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/labels"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getLabels();

        $this->assertSame($expectedResponse, $result);
    }

    public function testGetLabelsFailure(): void
    {
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 404\) when calling \'GET .*\'\.\nResponse: Not Found/');

        $this->githubProvider->getLabels();
    }

    public function testCreateLabelSuccess(): void
    {
        $name = 'new-label';
        $color = 'ff0000';
        $description = 'A new label';
        $expectedResponse = ['name' => $name, 'color' => $color, 'description' => $description];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/labels",
                [
                    'json' => [
                        'name' => $name,
                        'color' => $color,
                        'description' => $description,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->createLabel($name, $color, $description);

        $this->assertSame($expectedResponse, $result);
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
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/labels",
                [
                    'json' => [
                        'name' => $name,
                        'color' => $color,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->createLabel($name, $color);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCreateLabelFailure(): void
    {
        $name = 'new-label';
        $color = 'ff0000';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 422\) when calling \'POST .*\'\.\nResponse: Validation Failed/');

        $this->githubProvider->createLabel($name, $color);
    }

    public function testAddLabelsToPullRequestSuccess(): void
    {
        $issueNumber = 123;
        $labels = ['bug', 'enhancement'];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/issues/{$issueNumber}/labels",
                [
                    'json' => $labels,
                ]
            )
            ->willReturn($responseMock);

        $this->githubProvider->addLabelsToPullRequest($issueNumber, $labels);
    }

    public function testAddLabelsToPullRequestFailure(): void
    {
        $issueNumber = 123;
        $labels = ['bug', 'enhancement'];
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 404\) when calling \'POST .*\'\.\nResponse: Not Found/');

        $this->githubProvider->addLabelsToPullRequest($issueNumber, $labels);
    }

    public function testFindPullRequestByBranchSuccess(): void
    {
        $head = 'test_owner:feature/test';
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
                'draft' => false,
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls?head=" . urlencode($head) . "&state=open"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->findPullRequestByBranch($head);

        $this->assertSame($expectedResponse[0], $result);
    }

    public function testFindPullRequestByBranchNotFound(): void
    {
        $head = 'test_owner:feature/test';
        $expectedResponse = [];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->githubProvider->findPullRequestByBranch($head);

        $this->assertNull($result);
    }

    public function testFindPullRequestByBranchFailure(): void
    {
        $head = 'test_owner:feature/test';
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 404\) when calling \'GET .*\'\.\nResponse: Not Found/');

        $this->githubProvider->findPullRequestByBranch($head);
    }

    public function testUpdatePullRequestToDraft(): void
    {
        $pullNumber = 123;
        $draft = true;
        $expectedResponse = [
            'number' => $pullNumber,
            'draft' => true,
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls/{$pullNumber}",
                [
                    'json' => [
                        'draft' => $draft,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->updatePullRequest($pullNumber, $draft);

        $this->assertSame($expectedResponse, $result);
    }

    public function testUpdatePullRequestFromDraft(): void
    {
        $pullNumber = 123;
        $draft = false;
        $expectedResponse = [
            'number' => $pullNumber,
            'draft' => false,
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls/{$pullNumber}",
                [
                    'json' => [
                        'draft' => $draft,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->updatePullRequest($pullNumber, $draft);

        $this->assertSame($expectedResponse, $result);
    }

    public function testUpdatePullRequestFailure(): void
    {
        $pullNumber = 123;
        $draft = true;
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 404\) when calling \'PATCH .*\'\.\nResponse: Not Found/');

        $this->githubProvider->updatePullRequest($pullNumber, $draft);
    }

    public function testCreateCommentSuccess(): void
    {
        $issueNumber = 123;
        $body = 'This is a test comment.';
        $expectedResponse = ['id' => 456, 'body' => $body];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/issues/{$issueNumber}/comments",
                [
                    'json' => [
                        'body' => $body,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->createComment($issueNumber, $body);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCreateCommentFailure(): void
    {
        $issueNumber = 123;
        $body = 'This is a test comment.';
        $errorMessage = 'Not Found';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 404\) when calling \'POST .*\'\.\nResponse: Not Found/');

        $this->githubProvider->createComment($issueNumber, $body);
    }

    public function testUpdatePullRequestHeadSuccess(): void
    {
        $pullNumber = 123;
        $newHead = 'owner:new-branch';
        $expectedResponse = ['number' => $pullNumber, 'head' => ['ref' => 'new-branch']];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls/{$pullNumber}",
                [
                    'json' => [
                        'head' => $newHead,
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->updatePullRequestHead($pullNumber, $newHead);

        $this->assertSame($expectedResponse, $result);
    }

    public function testUpdatePullRequestHeadThrowsExceptionOnError(): void
    {
        $pullNumber = 123;
        $newHead = 'owner:new-branch';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')->with(false)->willReturn('{"message": "Head branch cannot be changed"}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GitHub API Error \(Status: 422\) when calling \'PATCH .*\'\.\nResponse: .*Head branch cannot be changed/');

        $this->githubProvider->updatePullRequestHead($pullNumber, $newHead);
    }

    public function testFindPullRequestByBranchWithStateOpen(): void
    {
        $head = 'test_owner:feature/test';
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
                'draft' => false,
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls?head=" . urlencode($head) . "&state=open"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->findPullRequestByBranch($head, 'open');

        $this->assertSame($expectedResponse[0], $result);
    }

    public function testFindPullRequestByBranchWithStateClosed(): void
    {
        $head = 'test_owner:feature/test';
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
                'state' => 'closed',
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls?head=" . urlencode($head) . "&state=closed"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->findPullRequestByBranch($head, 'closed');

        $this->assertSame($expectedResponse[0], $result);
    }

    public function testFindPullRequestByBranchWithStateAllReturnsOpenFirst(): void
    {
        $head = 'test_owner:feature/test';
        $openResponse = [
            [
                'number' => 123,
                'title' => 'Open PR',
                'head' => ['ref' => 'feature/test'],
            ],
        ];

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($openResponse);

        $this->httpClientMock->expects($this->exactly(1))
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls?head=" . urlencode($head) . "&state=open"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->findPullRequestByBranch($head, 'all');

        $this->assertSame($openResponse[0], $result);
    }

    public function testFindPullRequestByBranchWithStateAllReturnsClosedWhenOpenNotFound(): void
    {
        $head = 'test_owner:feature/test';
        $openResponse = [];
        $closedResponse = [
            [
                'number' => 123,
                'title' => 'Closed PR',
                'head' => ['ref' => 'feature/test'],
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
                if (str_contains($url, 'state=open')) {
                    return $openResponseMock;
                }
                if (str_contains($url, 'state=closed')) {
                    return $closedResponseMock;
                }

                throw new \RuntimeException("Unexpected URL: {$url}");
            });

        $result = $this->githubProvider->findPullRequestByBranch($head, 'all');

        $this->assertSame($closedResponse[0], $result);
    }

    public function testFindPullRequestByBranchName(): void
    {
        $branchName = 'feature/test';
        $head = self::GITHUB_OWNER . ':' . $branchName;
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
            ],
        ];

        $openResponseMock = $this->createMock(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($expectedResponse);

        $this->httpClientMock->expects($this->exactly(1))
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls?head=" . urlencode($head) . "&state=open"
            )
            ->willReturn($openResponseMock);

        $result = $this->githubProvider->findPullRequestByBranchName($branchName, 'all');

        $this->assertSame($expectedResponse[0], $result);
    }

    public function testFindPullRequestByBranchNameReturnsNullWhenNotFound(): void
    {
        $branchName = 'feature/test';
        $head = self::GITHUB_OWNER . ':' . $branchName;
        $openResponse = [];
        $closedResponse = [];

        $openResponseMock = $this->createMock(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($openResponse);

        $closedResponseMock = $this->createMock(ResponseInterface::class);
        $closedResponseMock->method('getStatusCode')->willReturn(200);
        $closedResponseMock->method('toArray')->willReturn($closedResponse);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($openResponseMock, $closedResponseMock) {
                if (str_contains($url, 'state=open')) {
                    return $openResponseMock;
                }
                if (str_contains($url, 'state=closed')) {
                    return $closedResponseMock;
                }

                throw new \RuntimeException("Unexpected URL: {$url}");
            });

        $result = $this->githubProvider->findPullRequestByBranchName($branchName, 'all');

        $this->assertNull($result);
    }
}
