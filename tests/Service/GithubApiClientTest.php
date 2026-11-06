<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\GithubApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GithubApiClientTest extends TestCase
{
    public function testGetOwnerAndRepoReturnConstructorValues(): void
    {
        $client = new GithubApiClient('token', 'test_owner', 'test_repo', $this->createStub(HttpClientInterface::class));

        $this->assertSame('test_owner', $client->getOwner());
        $this->assertSame('test_repo', $client->getRepo());
    }

    public function testRequestReturnsResponseOnSuccess(): void
    {
        $response = $this->createResponse(200, ['ok' => true]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('POST', '/repos/test_owner/test_repo/labels', ['json' => ['name' => 'bug']])
            ->willReturn($response);

        $result = $this->createClient($httpClient)->request('POST', '/repos/test_owner/test_repo/labels', 'Failed.', ['json' => ['name' => 'bug']]);

        $this->assertSame($response, $result);
    }

    public function testRequestThrowsApiExceptionOnNon2xxResponse(): void
    {
        $response = $this->createResponse(404, [], 'Not Found');
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        try {
            $this->createClient($httpClient)->request('GET', '/user', 'Failed to identify user.');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('Failed to identify user.', $e->getMessage());
            $this->assertSame(404, $e->getStatusCode());
            $this->assertStringContainsString("GitHub API Error (Status: 404) when calling 'GET /user'.", $e->getTechnicalDetails());
            $this->assertStringContainsString('Response: Not Found', $e->getTechnicalDetails());
        }
    }

    public function testGraphqlReturnsPayloadWhenNoErrors(): void
    {
        $payload = ['data' => ['thread' => ['id' => 'T1']]];
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('POST', '/graphql', ['json' => ['query' => 'query Q { x }', 'variables' => ['id' => 1]]])
            ->willReturn($this->createResponse(200, $payload));

        $this->assertSame($payload, $this->createClient($httpClient)->graphql('query Q { x }', ['id' => 1], 'Failed.'));
    }

    public function testGraphqlThrowsWithFirstErrorMessage(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->createResponse(200, ['errors' => [['message' => 'Thread not found']]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to resolve. Thread not found');

        $this->createClient($httpClient)->graphql('mutation M { x }', [], 'Failed to resolve.');
    }

    public function testGraphqlThrowsUnknownErrorWhenMessageMissing(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->createResponse(200, ['errors' => [['type' => 'NOT_FOUND']]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to resolve. Unknown GraphQL error');

        $this->createClient($httpClient)->graphql('mutation M { x }', [], 'Failed to resolve.');
    }

    public function testGetAllPagesFollowsLinkHeaderUntilLastPage(): void
    {
        $firstPage = $this->createResponse(200, [['number' => 1]], '', ['link' => ['<https://api.github.com/x?page=2>; rel="next"']]);
        $secondPage = $this->createResponse(200, [['number' => 2]]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(fn (string $method, string $url): ResponseInterface => match ($url) {
                '/pulls?state=open&page=1' => $firstPage,
                '/pulls?state=open&page=2' => $secondPage,
            });

        $result = $this->createClient($httpClient)->getAllPages('/pulls?state=open', 'Failed.');

        $this->assertSame([['number' => 1], ['number' => 2]], $result);
    }

    public function testGetAllPagesStopsOnEmptyPage(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($this->createResponse(200, [], '', ['link' => ['<https://api.github.com/x?page=2>; rel="next"']]));

        $this->assertSame([], $this->createClient($httpClient)->getAllPages('/pulls?state=open', 'Failed.'));
    }

    public function testHasNextPageWithNonArrayLinkHeader(): void
    {
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getHeaders')->willReturn([
            'link' => '<https://api.github.com/repos/test_owner/test_repo/pulls?state=open&per_page=100&page=2>; rel="next"',
        ]);

        $client = $this->createClient($this->createStub(HttpClientInterface::class));
        $method = new \ReflectionMethod($client, 'hasNextPage');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertTrue($method->invoke($client, $responseMock));
    }

    public function testHasNextPageWithEmptyLinkArray(): void
    {
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getHeaders')->willReturn([
            'link' => [],
        ]);

        $client = $this->createClient($this->createStub(HttpClientInterface::class));
        $method = new \ReflectionMethod($client, 'hasNextPage');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertFalse($method->invoke($client, $responseMock));
    }

    public function testGetClientCreatesClientWhenNotProvided(): void
    {
        $client = new GithubApiClient('test_token', 'test_owner', 'test_repo');
        $method = new \ReflectionMethod($client, 'getClient');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $httpClient = $method->invoke($client);

        $this->assertInstanceOf(HttpClientInterface::class, $httpClient);
        $this->assertSame($httpClient, $method->invoke($client));
    }

    public function testExtractTechnicalDetailsIncludesOwnerRepo(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(404);
        $responseMock->expects($this->once())->method('getContent')->with(false)->willReturn('Not Found');

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'POST', '/repos/test_owner/test_repo/pulls');

        $this->assertStringContainsString('Owner: test_owner', $result);
        $this->assertStringContainsString('Repo: test_repo', $result);
        $this->assertStringContainsString('Full URL: https://api.github.com/repos/test_owner/test_repo/pulls', $result);
    }

    public function testExtractTechnicalDetailsTruncatesLongBody(): void
    {
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getContent')->willReturn(str_repeat('a', 600));

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'GET', '/user');

        $this->assertStringContainsString('Response: ' . str_repeat('a', 500) . '... (truncated)', $result);
    }

    public function testExtractTechnicalDetailsReportsUnreadableBody(): void
    {
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getContent')->willThrowException(new \RuntimeException('stream closed'));

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'GET', '/user');

        $this->assertStringContainsString('Response: Unable to read response body: stream closed', $result);
    }

    public function testExtractTechnicalDetailsReportsEmptyBody(): void
    {
        $responseMock = $this->createStub(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getContent')->willReturn('');

        $result = $this->invokeExtractTechnicalDetails($responseMock, 'GET', '/user');

        $this->assertStringContainsString('Response: No response body', $result);
    }

    private function createClient(HttpClientInterface $httpClient): GithubApiClient
    {
        return new GithubApiClient('test_token', 'test_owner', 'test_repo', $httpClient);
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
