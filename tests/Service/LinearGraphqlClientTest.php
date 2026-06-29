<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Service\LinearGraphqlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinearGraphqlClientTest extends TestCase
{
    public function testQueryReturnsDataNodeOnSuccess(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'viewer' => ['id' => 'user-1', 'name' => 'Test User'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $graphql = new LinearGraphqlClient($client);

        $this->assertSame(
            ['viewer' => ['id' => 'user-1', 'name' => 'Test User']],
            $graphql->query('query { viewer { id name } }'),
        );
    }

    public function testQueryPostsQueryAndVariables(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->assertSame('POST', $method);
            $body = json_decode($options['body'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('query Team($key: String!) { team(id: $key) { id } }', $body['query']);
            $this->assertSame(['key' => 'SCI'], $body['variables']);

            return new MockResponse(json_encode(['data' => ['team' => ['id' => 'team-1']]], JSON_THROW_ON_ERROR));
        });

        $graphql = new LinearGraphqlClient($client);
        $data = $graphql->query(
            'query Team($key: String!) { team(id: $key) { id } }',
            ['key' => 'SCI'],
        );

        $this->assertSame(['team' => ['id' => 'team-1']], $data);
    }

    public function testQueryThrowsOn401WithClearMessage(): void
    {
        $client = new MockHttpClient([
            new MockResponse('Unauthorized', ['http_code' => 401]),
        ]);

        $graphql = new LinearGraphqlClient($client);

        try {
            $graphql->query('query { viewer { id } }');
            $this->fail('Expected IssueTrackerException was not thrown.');
        } catch (IssueTrackerException $exception) {
            $this->assertSame('work_item_provider.missing_linear_api_key', $exception->messageRef->key);
        }
    }

    public function testQueryThrowsOnNon200Response(): void
    {
        $client = new MockHttpClient([
            new MockResponse('Service unavailable', ['http_code' => 503]),
        ]);

        $graphql = new LinearGraphqlClient($client);

        try {
            $graphql->query('query { viewer { id } }');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request failed.', $exception->getMessage());
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('Service unavailable', $exception->getTechnicalDetails());
        }
    }

    public function testQueryThrowsOnGraphQlErrorsWithMessage(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'errors' => [['message' => 'Team not found']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $graphql = new LinearGraphqlClient($client);

        try {
            $graphql->query('query { team { id } }');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request failed.', $exception->getMessage());
            $this->assertSame('Team not found', $exception->getTechnicalDetails());
        }
    }

    public function testQueryThrowsOnGraphQlErrorsWithExtensions(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'errors' => [[
                    'message' => 'Forbidden',
                    'extensions' => ['code' => 'FORBIDDEN'],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $graphql = new LinearGraphqlClient($client);

        try {
            $graphql->query('query { viewer { id } }');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Forbidden ({"code":"FORBIDDEN"})', $exception->getTechnicalDetails());
        }
    }

    public function testQueryThrowsOnGraphQlErrorWithoutMessage(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'errors' => [[]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $graphql = new LinearGraphqlClient($client);

        try {
            $graphql->query('query { viewer { id } }');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Unknown GraphQL error', $exception->getTechnicalDetails());
        }
    }

    public function testQueryThrowsOnMalformedJsonBody(): void
    {
        $client = new MockHttpClient([
            new MockResponse('not-json'),
        ]);

        $graphql = new LinearGraphqlClient($client);

        try {
            $graphql->query('query { viewer { id } }');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request returned invalid JSON.', $exception->getMessage());
            $this->assertSame('not-json', $exception->getTechnicalDetails());
        }
    }

    public function testQueryThrowsWhenDataNodeMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['extensions' => []], JSON_THROW_ON_ERROR)),
        ]);

        $graphql = new LinearGraphqlClient($client);

        try {
            $graphql->query('query { viewer { id } }');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request returned no data.', $exception->getMessage());
        }
    }

    public function testExtractTechnicalDetailsFallsBackToStatusCodeWhenBodyUnreadable(): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(502);
        $response->method('getContent')->with(false)->willThrowException(new \RuntimeException('broken stream'));

        $graphql = new LinearGraphqlClient(new MockHttpClient());
        $method = new \ReflectionMethod(LinearGraphqlClient::class, 'extractTechnicalDetails');
        $method->setAccessible(true);

        $this->assertSame('HTTP 502', $method->invoke($graphql, $response));
    }

    public function testFormatGraphQlErrorsReturnsFallbackWhenErrorsAreEmpty(): void
    {
        $graphql = new LinearGraphqlClient(new MockHttpClient());
        $method = new \ReflectionMethod(LinearGraphqlClient::class, 'formatGraphQlErrors');
        $method->setAccessible(true);

        $this->assertSame('Linear GraphQL request failed.', $method->invoke($graphql, ['not-an-array']));
    }
}
