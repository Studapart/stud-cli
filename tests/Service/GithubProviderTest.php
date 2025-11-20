<?php

namespace App\Tests\Service;

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
                    ],
                ]
            )
            ->willReturn($responseMock);

        $result = $this->githubProvider->createPullRequest($title, $head, $base, $body);

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

        $this->githubProvider->createPullRequest($title, $head, $base, $body);
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
}