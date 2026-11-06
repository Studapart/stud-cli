<?php

namespace App\Tests\Service;

use App\DTO\PullRequestData;
use App\DTO\PullRequestFeedbackIds;
use App\Service\GithubApiClient;
use App\Service\GithubGitHostingAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GithubGitHostingAdapterTest extends TestCase
{
    private const GITHUB_TOKEN = 'test_token';
    private const GITHUB_OWNER = 'test_owner';
    private const GITHUB_REPO = 'test_repo';

    private GithubGitHostingAdapter $githubProvider;
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

        $title = 'Test PR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test pull request.';
        $expectedResponse = ['html_url' => 'https://github.com/test_owner/test_repo/pull/1'];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $title = 'Test Draft PR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a draft pull request.';
        $expectedResponse = ['html_url' => 'https://github.com/test_owner/test_repo/pull/1'];

        $responseMock = $this->createStub(ResponseInterface::class);
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

    public function testCreatePullRequestAssignsToAuthenticatedAuthorWhenRequested(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $createdPullRequest = [
            'number' => 123,
            'html_url' => 'https://github.com/test_owner/test_repo/pull/123',
        ];

        $createResponse = $this->createStub(ResponseInterface::class);
        $createResponse->method('getStatusCode')->willReturn(201);
        $createResponse->method('toArray')->willReturn($createdPullRequest);

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['login' => 'octocat', 'id' => 1]);

        $assignResponse = $this->createStub(ResponseInterface::class);
        $assignResponse->method('getStatusCode')->willReturn(201);
        $assignResponse->method('toArray')->willReturn([['login' => 'octocat']]);

        $this->httpClientMock->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options = []) use ($createResponse, $userResponse, $assignResponse) {
                if ($method === 'POST' && $url === '/repos/test_owner/test_repo/pulls') {
                    return $createResponse;
                }
                if ($method === 'GET' && $url === '/user') {
                    return $userResponse;
                }
                if ($method === 'POST' && $url === '/repos/test_owner/test_repo/issues/123/assignees') {
                    $this->assertSame(['assignees' => ['octocat']], $options['json']);

                    return $assignResponse;
                }

                throw new \RuntimeException("Unexpected request {$method} {$url}");
            });

        $prData = new PullRequestData('Title', 'feature/test', 'develop', 'Body', false, true);

        $this->assertSame($createdPullRequest, $this->githubProvider->createPullRequest($prData));
    }

    public function testAssignPullRequestToAuthorFailsWhenGithubDoesNotConfirmAssignee(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['login' => 'octocat']);

        $assignResponse = $this->createStub(ResponseInterface::class);
        $assignResponse->method('getStatusCode')->willReturn(201);
        $assignResponse->method('toArray')->willReturn([]);

        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($userResponse, $assignResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("GitHub did not confirm 'octocat' as an assignee");

        $this->githubProvider->assignPullRequestToAuthor(['number' => 123]);
    }

    public function testCreatePullRequestWrapsAssignmentFailureWithPullRequestUrl(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $createdPullRequest = [
            'number' => 123,
            'html_url' => 'https://github.com/test_owner/test_repo/pull/123',
        ];

        $createResponse = $this->createStub(ResponseInterface::class);
        $createResponse->method('getStatusCode')->willReturn(201);
        $createResponse->method('toArray')->willReturn($createdPullRequest);

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['login' => 'octocat']);

        $assignResponse = $this->createStub(ResponseInterface::class);
        $assignResponse->method('getStatusCode')->willReturn(201);
        $assignResponse->method('toArray')->willReturn([]);

        $this->httpClientMock->expects($this->exactly(3))
            ->method('request')
            ->willReturnOnConsecutiveCalls($createResponse, $userResponse, $assignResponse);

        $this->expectException(\App\Exception\PullRequestAssignmentException::class);
        $this->expectExceptionMessage("GitHub did not confirm 'octocat' as an assignee");

        $this->githubProvider->createPullRequest(new PullRequestData('Title', 'feature/test', 'develop', 'Body', false, true));
    }

    public function testAssignPullRequestToAuthorRequiresPullRequestNumber(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $this->httpClientMock->expects($this->never())->method('request');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot assign pull request because its number is missing.');

        $this->githubProvider->assignPullRequestToAuthor([]);
    }

    public function testAssignPullRequestToAuthorRequiresAuthenticatedLogin(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $userResponse = $this->createStub(ResponseInterface::class);
        $userResponse->method('getStatusCode')->willReturn(200);
        $userResponse->method('toArray')->willReturn(['id' => 1]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', '/user')
            ->willReturn($userResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot assign pull request because the authenticated GitHub login is missing.');

        $this->githubProvider->assignPullRequestToAuthor(['number' => 123]);
    }

    public function testCreatePullRequestFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $title = 'Test PR';
        $head = 'feature/test';
        $base = 'develop';
        $body = 'This is a test pull request.';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to create pull request.');

        $prData = new PullRequestData($title, $head, $base, $body);
        $this->githubProvider->createPullRequest($prData);
    }

    public function testGetLatestReleaseSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $expectedResponse = [
            'tag_name' => 'v1.2.3',
            'name' => 'Release 1.2.3',
            'assets' => [],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->expectExceptionMessage('Failed to get latest release.');

        $this->githubProvider->getLatestRelease();
    }

    public function testGetLatestReleaseFailureWithTruncatedResponse(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $longResponse = str_repeat('A', 600); // 600 characters

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn($longResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get latest release.');

        try {
            $this->githubProvider->getLatestRelease();
        } catch (\App\Exception\ApiException $e) {
            $technicalDetails = $e->getTechnicalDetails();
            $this->assertStringContainsString('... (truncated)', $technicalDetails);

            throw $e;
        }
    }

    public function testGetLatestReleaseFailureWithGetContentException(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willThrowException(new \Exception('Connection timeout'));

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get latest release.');

        try {
            $this->githubProvider->getLatestRelease();
        } catch (\App\Exception\ApiException $e) {
            $technicalDetails = $e->getTechnicalDetails();
            $this->assertStringContainsString('Unable to read response body: Connection timeout', $technicalDetails);

            throw $e;
        }
    }

    public function testGetLatestReleaseFailureWithEmptyResponse(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get latest release.');

        try {
            $this->githubProvider->getLatestRelease();
        } catch (\App\Exception\ApiException $e) {
            $technicalDetails = $e->getTechnicalDetails();
            $this->assertStringContainsString('No response body', $technicalDetails);

            throw $e;
        }
    }

    public function testGetChangelogContentSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $tag = 'v1.0.0';
        $changelogContent = '# Changelog\n\n## [1.0.0] - 2025-01-01\n\n### Added\n- Initial release';
        $encodedContent = base64_encode($changelogContent);

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $tag = 'v1.0.0';
        $errorMessage = 'Not Found';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get changelog content.');

        $this->githubProvider->getChangelogContent($tag);
    }

    public function testGetChangelogContentInvalidEncoding(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $tag = 'v1.0.0';

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $tag = 'v1.0.0';

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $tag = 'v1.0.0';
        // Invalid base64 that will cause base64_decode to return false
        $invalidBase64 = '!!!invalid-base64!!!';

        $responseMock = $this->createStub(ResponseInterface::class);
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
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/labels"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getLabels();

        $this->assertSame($expectedResponse, $result);
    }

    public function testGetLabelsFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $errorMessage = 'Not Found';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to get labels.');

        $this->githubProvider->getLabels();
    }

    public function testCreateLabelSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $name = 'new-label';
        $color = 'ff0000';
        $description = 'A new label';
        $expectedResponse = ['name' => $name, 'color' => $color, 'description' => $description];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $name = 'new-label';
        $color = 'ff0000';
        $errorMessage = 'Validation Failed';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to create label '{$name}'.");

        $this->githubProvider->createLabel($name, $color);
    }

    public function testAddLabelsToPullRequestSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $labels = ['bug', 'enhancement'];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $labels = ['bug', 'enhancement'];
        $errorMessage = 'Not Found';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to add labels to pull request #{$issueNumber}.");

        $this->githubProvider->addLabelsToPullRequest($issueNumber, $labels);
    }

    public function testFindPullRequestByBranchSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
                'draft' => false,
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $expectedResponse = [];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $errorMessage = 'Not Found';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage('Failed to find pull request by branch.');

        $this->githubProvider->findPullRequestByBranch($head);
    }

    public function testUpdatePullRequestToDraft(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $draft = true;
        $expectedResponse = [
            'number' => $pullNumber,
            'draft' => true,
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $draft = false;
        $expectedResponse = [
            'number' => $pullNumber,
            'draft' => false,
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $draft = true;
        $errorMessage = 'Not Found';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to update pull request #{$pullNumber}.");

        $this->githubProvider->updatePullRequest($pullNumber, $draft);
    }

    public function testCreateCommentSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $body = 'This is a test comment.';
        $expectedResponse = ['id' => 456, 'body' => $body];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $body = 'This is a test comment.';
        $errorMessage = 'Not Found';

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->method('getContent')->willReturn($errorMessage);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to create comment on issue #{$issueNumber}.");

        $this->githubProvider->createComment($issueNumber, $body);
    }

    public function testReplyToPullRequestFeedbackUsesGraphqlMutation(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->expects($this->once())->method('toArray')->with(false)->willReturn([
            'data' => ['addPullRequestReviewThreadReply' => ['comment' => ['id' => 'reply-id']]],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/graphql',
                $this->callback(function (array $options): bool {
                    $variables = $options['json']['variables'] ?? [];

                    return str_contains((string) ($options['json']['query'] ?? ''), 'addPullRequestReviewThreadReply')
                        && $variables === ['threadId' => 'thread-id', 'body' => 'Thanks'];
                })
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->replyToPullRequestFeedback(
            123,
            new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-id'),
            'Thanks'
        );

        $this->assertArrayHasKey('data', $result);
    }

    public function testResolvePullRequestFeedbackUsesGraphqlMutation(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->expects($this->once())->method('toArray')->with(false)->willReturn([
            'data' => ['resolveReviewThread' => ['thread' => ['id' => 'thread-id', 'isResolved' => true]]],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/graphql',
                $this->callback(function (array $options): bool {
                    $variables = $options['json']['variables'] ?? [];

                    return str_contains((string) ($options['json']['query'] ?? ''), 'resolveReviewThread')
                        && $variables === ['threadId' => 'thread-id'];
                })
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->resolvePullRequestFeedback(
            123,
            new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-id')
        );

        $this->assertArrayHasKey('data', $result);
    }

    public function testReplyToPullRequestFeedbackRejectsMissingThreadId(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->githubProvider->replyToPullRequestFeedback(
            123,
            new PullRequestFeedbackIds('github', 'review_thread'),
            'Thanks'
        );
    }

    public function testResolvePullRequestFeedbackReportsGraphqlErrors(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->expects($this->once())->method('toArray')->with(false)->willReturn([
            'errors' => [['message' => 'Cannot resolve']],
        ]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve');

        $this->githubProvider->resolvePullRequestFeedback(
            123,
            new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-id')
        );
    }

    public function testUpdatePullRequestHeadSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $newHead = 'owner:new-branch';
        $expectedResponse = ['number' => $pullNumber, 'head' => ['ref' => 'new-branch']];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $newHead = 'owner:new-branch';

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(422);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('{"message": "Head branch cannot be changed"}');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to update pull request head for PR #{$pullNumber}.");

        $this->githubProvider->updatePullRequestHead($pullNumber, $newHead);
    }

    public function testFindPullRequestByBranchWithStateOpen(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
                'draft' => false,
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
                'state' => 'closed',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $head = 'test_owner:feature/test';
        $openResponse = [
            [
                'number' => 123,
                'title' => 'Open PR',
                'head' => ['ref' => 'feature/test'],
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

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

        $openResponseMock = $this->createStub(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($openResponse);

        $closedResponseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $branchName = 'feature/test';
        $head = self::GITHUB_OWNER . ':' . $branchName;
        $expectedResponse = [
            [
                'number' => 123,
                'title' => 'Test PR',
                'head' => ['ref' => 'feature/test'],
            ],
        ];

        $openResponseMock = $this->createStub(ResponseInterface::class);
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
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $branchName = 'feature/test';
        $head = self::GITHUB_OWNER . ':' . $branchName;
        $openResponse = [];
        $closedResponse = [];

        $openResponseMock = $this->createStub(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($openResponse);

        $closedResponseMock = $this->createStub(ResponseInterface::class);
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

    public function testGetAllPullRequestsWithStateOpen(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $expectedResponse = [
            [
                'number' => 1,
                'title' => 'Open PR 1',
                'head' => ['ref' => 'feature/branch1'],
                'state' => 'open',
            ],
            [
                'number' => 2,
                'title' => 'Open PR 2',
                'head' => ['ref' => 'feature/branch2'],
                'state' => 'open',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);
        $responseMock->method('getHeaders')->willReturn([]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls?state=open&per_page=100&page=1"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getAllPullRequests('open');

        $this->assertSame($expectedResponse, $result);
    }

    public function testGetAllPullRequestsWithStateClosed(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $expectedResponse = [
            [
                'number' => 3,
                'title' => 'Closed PR 1',
                'head' => ['ref' => 'feature/branch3'],
                'state' => 'closed',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($expectedResponse);
        $responseMock->method('getHeaders')->willReturn([]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                "/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls?state=closed&per_page=100&page=1"
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getAllPullRequests('closed');

        $this->assertSame($expectedResponse, $result);
    }

    public function testGetAllPullRequestsWithStateAll(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $openResponse = [
            [
                'number' => 1,
                'title' => 'Open PR',
                'head' => ['ref' => 'feature/branch1'],
                'state' => 'open',
            ],
        ];

        $closedResponse = [
            [
                'number' => 2,
                'title' => 'Closed PR',
                'head' => ['ref' => 'feature/branch2'],
                'state' => 'closed',
            ],
        ];

        $openResponseMock = $this->createStub(ResponseInterface::class);
        $openResponseMock->method('getStatusCode')->willReturn(200);
        $openResponseMock->method('toArray')->willReturn($openResponse);
        $openResponseMock->method('getHeaders')->willReturn([]);

        $closedResponseMock = $this->createStub(ResponseInterface::class);
        $closedResponseMock->method('getStatusCode')->willReturn(200);
        $closedResponseMock->method('toArray')->willReturn($closedResponse);
        $closedResponseMock->method('getHeaders')->willReturn([]);

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

        $result = $this->githubProvider->getAllPullRequests('all');

        $this->assertCount(2, $result);
        $this->assertSame($openResponse[0], $result[0]);
        $this->assertSame($closedResponse[0], $result[1]);
    }

    public function testGetAllPullRequestsWithPagination(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $page1Response = [
            ['number' => 1, 'title' => 'PR 1', 'head' => ['ref' => 'feature/branch1'], 'state' => 'open'],
            ['number' => 2, 'title' => 'PR 2', 'head' => ['ref' => 'feature/branch2'], 'state' => 'open'],
        ];

        $page2Response = [
            ['number' => 3, 'title' => 'PR 3', 'head' => ['ref' => 'feature/branch3'], 'state' => 'open'],
        ];

        $page1ResponseMock = $this->createMock(ResponseInterface::class);
        $page1ResponseMock->method('getStatusCode')->willReturn(200);
        $page1ResponseMock->method('toArray')->willReturn($page1Response);
        // getHeaders() may be called multiple times (once in hasNextPage check)
        // Add explicit expectation to ensure it always returns the same value
        $page1Headers = [
            'link' => ['<https://api.github.com/repos/test_owner/test_repo/pulls?state=open&per_page=100&page=2>; rel="next"'],
        ];
        $page1ResponseMock->expects($this->atLeastOnce())
            ->method('getHeaders')
            ->willReturn($page1Headers);

        $page2ResponseMock = $this->createMock(ResponseInterface::class);
        $page2ResponseMock->method('getStatusCode')->willReturn(200);
        $page2ResponseMock->method('toArray')->willReturn($page2Response);
        // Return empty headers array - no 'link' key means no next page
        // CRITICAL: getHeaders() must ALWAYS return [] to stop pagination
        // Add explicit expectation to ensure it always returns []
        $page2ResponseMock->expects($this->atLeastOnce())
            ->method('getHeaders')
            ->willReturn([]);

        $callCount = 0;
        $this->httpClientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($page1ResponseMock, $page2ResponseMock, &$callCount) {
                $callCount++;

                // Use more specific matching - check for exact page parameter
                // Match '&page=1' or '?page=1' to avoid matching 'page=10', 'page=11', etc.
                if (preg_match('/[?&]page=1(?=&|$)/', $url)) {
                    return $page1ResponseMock;
                }
                if (preg_match('/[?&]page=2(?=&|$)/', $url)) {
                    return $page2ResponseMock;
                }

                throw new \RuntimeException("Unexpected URL: {$url} (call #{$callCount})");
            });

        $result = $this->githubProvider->getAllPullRequests('open');

        $this->assertCount(3, $result);
        $this->assertSame($page1Response[0], $result[0]);
        $this->assertSame($page1Response[1], $result[1]);
        $this->assertSame($page2Response[0], $result[2]);
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
        $this->expectExceptionMessage('Failed to get all pull requests.');

        $this->githubProvider->getAllPullRequests('open');
    }

    public function testGetAllPullRequestsWithEmptyResult(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([]);
        $responseMock->method('getHeaders')->willReturn([]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->githubProvider->getAllPullRequests('open');

        $this->assertSame([], $result);
    }

    public function testGetPullRequestCommentsSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $apiResponse = [
            [
                'user' => ['login' => 'alice'],
                'body' => 'Issue comment body',
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
                $this->stringContains("/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/issues/{$issueNumber}/comments")
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestComments($issueNumber);

        $this->assertCount(1, $result);
        $this->assertSame('alice', $result[0]->author);
        $this->assertSame('Issue comment body', $result[0]->body);
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
                'user' => ['login' => 'bob'],
                'body' => 'Review comment',
                'created_at' => '2025-01-15T11:00:00Z',
                'path' => 'src/File.php',
                'line' => 10,
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains("/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls/{$pullNumber}/comments")
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestReviewComments($pullNumber);

        $this->assertCount(1, $result);
        $this->assertSame('bob', $result[0]->author);
        $this->assertSame('Review comment', $result[0]->body);
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
            ->with('GET', $this->stringContains("/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls/{$pullNumber}/comments"))
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to get review comments for pull request #{$pullNumber}.");

        $this->githubProvider->getPullRequestReviewComments($pullNumber);
    }

    public function testGetPullRequestReviewsSuccess(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [
            [
                'id' => 80,
                'user' => ['login' => 'reviewer'],
                'body' => 'Please address the suggested changes.',
                'state' => 'CHANGES_REQUESTED',
                'submitted_at' => '2025-01-15T12:00:00Z',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains("/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls/{$pullNumber}/reviews")
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestReviews($pullNumber);

        $this->assertCount(1, $result);
        $this->assertSame('reviewer', $result[0]->author);
        $this->assertSame('Please address the suggested changes.', $result[0]->body);
        $this->assertNull($result[0]->path);
        $this->assertNull($result[0]->line);
    }

    public function testGetPullRequestReviewsFailure(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('Server Error');

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains("/repos/" . self::GITHUB_OWNER . "/" . self::GITHUB_REPO . "/pulls/{$pullNumber}/reviews"))
            ->willReturn($responseMock);

        $this->expectException(\App\Exception\ApiException::class);
        $this->expectExceptionMessage("Failed to get reviews for pull request #{$pullNumber}.");

        $this->githubProvider->getPullRequestReviews($pullNumber);
    }

    public function testGetPullRequestReviewsUsesCreatedAtWhenSubmittedAtMissing(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [
            [
                'id' => 81,
                'user' => ['login' => 'user1'],
                'body' => 'Review body',
                'state' => 'COMMENT',
                'created_at' => '2025-01-15T11:00:00Z',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())->method('request')->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestReviews($pullNumber);

        $this->assertCount(1, $result);
        $this->assertSame('2025-01-15 11:00:00', $result[0]->date->format('Y-m-d H:i:s'));
    }

    public function testGetPullRequestReviewsSkipsEmptyBody(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [
            ['id' => 1, 'user' => ['login' => 'u1'], 'body' => null, 'submitted_at' => '2025-01-15T12:00:00Z'],
            ['id' => 2, 'user' => ['login' => 'u2'], 'body' => '  ', 'submitted_at' => '2025-01-15T12:01:00Z'],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())->method('request')->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestReviews($pullNumber);

        $this->assertCount(0, $result);
    }

    public function testGetPullRequestReviewsStopsAtCap(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [];
        for ($i = 0; $i < 51; $i++) {
            $apiResponse[] = [
                'id' => $i,
                'user' => ['login' => "reviewer{$i}"],
                'body' => "Review body {$i}",
                'state' => 'COMMENT',
                'submitted_at' => '2025-01-15T12:00:00Z',
            ];
        }

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())->method('request')->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestReviews($pullNumber);

        $this->assertCount(50, $result);
    }

    public function testGetPullRequestFeedbackConversationsReturnsThreadedMetadata(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueResponse = $this->createGithubGraphqlResponse('comments', [[
            'id' => 'issue-node',
            'databaseId' => 10,
            'author' => ['login' => 'alice'],
            'body' => 'Top level',
            'createdAt' => '2025-01-15T10:00:00Z',
            'isMinimized' => false,
            'viewerCanMinimize' => true,
        ]]);
        $reviewResponse = $this->createGithubGraphqlResponse('reviews', [[
            'id' => 'review-node',
            'databaseId' => 20,
            'author' => ['login' => 'reviewer'],
            'body' => 'Review body',
            'state' => 'CHANGES_REQUESTED',
            'submittedAt' => '2025-01-15T11:00:00Z',
            'isMinimized' => false,
        ], [
            'id' => 'empty-review-node',
            'databaseId' => 21,
            'author' => ['login' => 'reviewer'],
            'body' => ' ',
            'state' => 'COMMENTED',
            'submittedAt' => '2025-01-15T11:30:00Z',
        ]]);
        $threadResponse = $this->createGithubGraphqlResponse('reviewThreads', [[
            'id' => 'thread-node',
            'isResolved' => false,
            'isOutdated' => true,
            'path' => 'src/File.php',
            'line' => 12,
            'viewerCanResolve' => true,
            'comments' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [[
                    'id' => 'comment-node',
                    'databaseId' => 30,
                    'author' => ['login' => 'bob'],
                    'body' => 'Inline',
                    'createdAt' => '2025-01-15T12:00:00Z',
                    'path' => 'src/File.php',
                    'line' => 12,
                    'pullRequestReview' => [
                        'id' => 'review-node',
                        'databaseId' => 20,
                        'author' => ['login' => 'reviewer'],
                        'body' => 'Review body',
                        'state' => 'COMMENTED',
                        'submittedAt' => '2025-01-15T11:00:00Z',
                    ],
                ]],
            ],
        ], [
            'id' => 'thread-without-review',
            'isResolved' => true,
            'isOutdated' => false,
            'viewerCanUnresolve' => true,
            'comments' => [
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor'],
                'nodes' => [[
                    'id' => 'comment-without-review',
                    'databaseId' => 31,
                    'author' => null,
                    'body' => 'Inline without review',
                    'createdAt' => '2025-01-15T13:00:00Z',
                    'isMinimized' => true,
                    'viewerCanMinimize' => true,
                ]],
            ],
        ]]);

        $this->httpClientMock->expects($this->exactly(3))
            ->method('request')
            ->with('POST', '/graphql', $this->anything())
            ->willReturnOnConsecutiveCalls($issueResponse, $reviewResponse, $threadResponse);

        $result = $this->githubProvider->getPullRequestFeedbackConversations(123);

        $this->assertCount(4, $result);
        $this->assertSame('pr_comment', $result[0]->type);
        $this->assertSame('review', $result[1]->type);
        $this->assertSame('review_thread', $result[2]->type);
        $this->assertSame('thread-node', $result[2]->ids->threadId);
        $this->assertSame('github:review_thread:thread-node', $result[2]->ids->target);
        $this->assertTrue($result[2]->actions->canResolve);
        $this->assertSame('src/File.php', $result[2]->location?->path);
        $this->assertTrue($result[3]->truncated);
        $this->assertNull($result[3]->location);
    }

    public function testGetPullRequestFeedbackConversationsThrowsOnGraphqlErrors(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn(['errors' => [['message' => 'Bad query']]]);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->with('POST', '/graphql', $this->anything())
            ->willReturn($responseMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bad query');

        $this->githubProvider->getPullRequestFeedbackConversations(123);
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
        $this->expectExceptionMessage("Failed to get comments for issue #{$issueNumber}.");

        $this->githubProvider->getPullRequestComments($issueNumber);
    }

    public function testGetPullRequestCommentsCapsAtPageSize(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $issueNumber = 123;
        $manyComments = [];
        for ($i = 0; $i < 55; ++$i) {
            $manyComments[] = [
                'user' => ['login' => 'user' . $i],
                'body' => 'Comment ' . $i,
                'created_at' => '2025-01-15T10:00:00Z',
            ];
        }

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($manyComments);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestComments($issueNumber);

        $this->assertCount(50, $result);
    }

    public function testGetPullRequestReviewCommentsWithMissingPathAndLine(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bindSubject();

        $pullNumber = 123;
        $apiResponse = [
            [
                'user' => ['login' => 'bob'],
                'body' => 'Review without path/line',
                'created_at' => '2025-01-15T11:00:00Z',
            ],
        ];

        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn($apiResponse);

        $this->httpClientMock->expects($this->once())
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->githubProvider->getPullRequestReviewComments($pullNumber);

        $this->assertCount(1, $result);
        $this->assertSame('bob', $result[0]->author);
        $this->assertNull($result[0]->path);
        $this->assertNull($result[0]->line);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function createGithubGraphqlResponse(string $connection, array $nodes): ResponseInterface
    {
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('toArray')->willReturn([
            'data' => [
                'repository' => [
                    'pullRequest' => [
                        $connection => [
                            'nodes' => $nodes,
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ],
            ],
        ]);

        return $responseMock;
    }

    private function bindSubject(): void
    {
        $this->githubProvider = new GithubGitHostingAdapter(new GithubApiClient(
            self::GITHUB_TOKEN,
            self::GITHUB_OWNER,
            self::GITHUB_REPO,
            $this->httpClientMock
        ));
    }
}
