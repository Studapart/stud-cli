<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\GitLabProject;
use App\Exception\ApiException;
use App\Service\GitLabApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitLabApiClientTest extends TestCase
{
    public function testGetProjectPathEncodesNestedGroups(): void
    {
        $client = new GitLabApiClient('token', new GitLabProject('group/subgroup', 'test_repo'), $this->createStub(HttpClientInterface::class));

        $this->assertSame('group%2Fsubgroup%2Ftest_repo', $client->getProjectPath());
    }

    public function testRequestReturnsResponseOnSuccess(): void
    {
        $response = $this->createResponse(201, ['iid' => 1]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('POST', '/projects/test_owner%2Ftest_repo/labels', ['json' => ['name' => 'bug']])
            ->willReturn($response);

        $result = $this->createClient($httpClient)->request('POST', '/projects/test_owner%2Ftest_repo/labels', 'Failed.', ['json' => ['name' => 'bug']]);

        $this->assertSame($response, $result);
    }

    public function testRequestThrowsApiExceptionOnNon2xxResponse(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->createResponse(403, [], 'Forbidden'));

        try {
            $this->createClient($httpClient)->request('GET', '/user', 'Failed to identify user.');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('Failed to identify user.', $e->getMessage());
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString("GitLab API Error (Status: 403) when calling 'GET /user'.", $e->getTechnicalDetails());
            $this->assertStringContainsString('Full URL: https://gitlab.com/api/v4/user', $e->getTechnicalDetails());
        }
    }

    public function testRequestErrorUsesCustomInstanceUrl(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->createResponse(500, [], 'Boom'));
        $client = new GitLabApiClient('token', new GitLabProject('test_owner', 'test_repo', 'https://git.example.com'), $httpClient);

        try {
            $client->request('GET', '/user', 'Failed.');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('Full URL: https://git.example.com/api/v4/user', $e->getTechnicalDetails());
        }
    }

    public function testGetAllPagesFollowsTotalPagesHeader(): void
    {
        $firstPage = $this->createResponse(200, [['iid' => 1]], '', ['x-total-pages' => ['2']]);
        $secondPage = $this->createResponse(200, [['iid' => 2]], '', ['x-total-pages' => ['2']]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(fn (string $method, string $url): ResponseInterface => match ($url) {
                '/merge_requests?state=opened&page=1' => $firstPage,
                '/merge_requests?state=opened&page=2' => $secondPage,
            });

        $result = $this->createClient($httpClient)->getAllPages('/merge_requests?state=opened', 'Failed.');

        $this->assertSame([['iid' => 1], ['iid' => 2]], $result);
    }

    public function testGetAllPagesStopsOnEmptyPage(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($this->createResponse(200, [], '', ['x-total-pages' => ['5']]));

        $this->assertSame([], $this->createClient($httpClient)->getAllPages('/merge_requests?state=opened', 'Failed.'));
    }

    public function testHasNextPageWithXTotalPagesHeader(): void
    {
        $this->assertTrue($this->invokeHasNextPage(['x-total-pages' => ['2']], 1));
    }

    public function testHasNextPageWithScalarXTotalPagesHeader(): void
    {
        $this->assertFalse($this->invokeHasNextPage(['x-total-pages' => '1'], 1));
    }

    public function testHasNextPageWithXNextPageHeader(): void
    {
        $this->assertTrue($this->invokeHasNextPage(['x-next-page' => ['2']], 1));
    }

    public function testHasNextPageWithEmptyScalarXNextPageHeader(): void
    {
        $this->assertFalse($this->invokeHasNextPage(['x-next-page' => ''], 1));
    }

    public function testHasNextPageReturnsFalseWhenNoNextPage(): void
    {
        $this->assertFalse($this->invokeHasNextPage(['x-total-pages' => ['1']], 1));
    }

    public function testHasNextPageReturnsFalseWhenNoHeaders(): void
    {
        $this->assertFalse($this->invokeHasNextPage([], 1));
    }

    public function testGetClientCreatesClientWhenNotProvided(): void
    {
        $client = new GitLabApiClient('test_token', new GitLabProject('test_owner', 'test_repo'));
        $method = new \ReflectionMethod($client, 'getClient');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $httpClient = $method->invoke($client);

        $this->assertInstanceOf(HttpClientInterface::class, $httpClient);
        $this->assertSame($httpClient, $method->invoke($client));
    }

    public function testExtractTechnicalDetails(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('Error message');

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('GitLab API Error', $result);
        $this->assertStringContainsString('Status: 404', $result);
        $this->assertStringContainsString('GET', $result);
        $this->assertStringContainsString('/test/endpoint', $result);
        $this->assertStringContainsString('Error message', $result);
    }

    public function testExtractTechnicalDetailsWithTruncatedResponse(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn(str_repeat('A', 600));

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('... (truncated)', $result);
    }

    public function testExtractTechnicalDetailsWithException(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willThrowException(new \Exception('Connection timeout'));

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('Unable to read response body: Connection timeout', $result);
    }

    public function testExtractTechnicalDetailsWithEmptyResponse(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('');

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'GET', '/test/endpoint');

        $this->assertStringContainsString('No response body', $result);
    }

    public function testExtractTechnicalDetailsIncludesOwnerRepoAndProjectPath(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('Not Found');

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'POST', '/projects/test_owner%2Ftest_repo/merge_requests');

        $this->assertStringContainsString('Owner: test_owner', $result);
        $this->assertStringContainsString('Repo: test_repo', $result);
        $this->assertStringContainsString('Project Path: test_owner%2Ftest_repo', $result);
    }

    private function createClient(HttpClientInterface $httpClient): GitLabApiClient
    {
        return new GitLabApiClient('test_token', new GitLabProject('test_owner', 'test_repo'), $httpClient);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function invokeHasNextPage(array $headers, int $currentPage): bool
    {
        $client = $this->createClient($this->createStub(HttpClientInterface::class));
        $method = new \ReflectionMethod($client, 'hasNextPage');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        return $method->invoke($client, $this->createResponse(200, [], '', $headers), $currentPage);
    }

    private function invokeExtractTechnicalDetails(ResponseInterface $response, string $method, string $apiUrl): string
    {
        $client = $this->createClient($this->createStub(HttpClientInterface::class));
        $reflection = new \ReflectionMethod($client, 'extractTechnicalDetails');
        \App\Util\ReflectionAccessor::ensureAccessible($reflection);

        return $reflection->invoke($client, $response, $method, $apiUrl);
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<string, string|list<string>> $headers
     */
    private function createResponse(int $statusCode, array $data, string $content = '', array $headers = []): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->willReturn($data);
        $response->method('getContent')->willReturn($content);
        $response->method('getHeaders')->willReturn($headers);

        return $response;
    }
}
