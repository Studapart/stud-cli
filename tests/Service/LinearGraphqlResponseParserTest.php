<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Service\LinearGraphqlResponseParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

class LinearGraphqlResponseParserTest extends TestCase
{
    private LinearGraphqlResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LinearGraphqlResponseParser();
    }

    public function testParseReturnsDataNode(): void
    {
        $response = $this->response(json_encode(['data' => ['viewer' => ['id' => 'u-1']]], JSON_THROW_ON_ERROR));

        $this->assertSame(['viewer' => ['id' => 'u-1']], $this->parser->parse($response));
    }

    public function testParseThrowsMissingApiKeyOn401(): void
    {
        try {
            $this->parser->parse($this->response('Unauthorized', 401));
            $this->fail('Expected IssueTrackerException was not thrown.');
        } catch (IssueTrackerException $exception) {
            $this->assertSame('issue_tracker_provider.missing_linear_api_key', $exception->messageRef->key);
        }
    }

    public function testParseThrowsOnNon200WithBodyAsDetails(): void
    {
        try {
            $this->parser->parse($this->response('Service unavailable', 503));
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request failed.', $exception->getMessage());
            $this->assertSame('Service unavailable', $exception->getTechnicalDetails());
            $this->assertSame(503, $exception->getStatusCode());
        }
    }

    public function testParseThrowsOnInvalidJson(): void
    {
        try {
            $this->parser->parse($this->response('not-json'));
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request returned invalid JSON.', $exception->getMessage());
            $this->assertSame('not-json', $exception->getTechnicalDetails());
        }
    }

    public function testParseThrowsWhenDataIsNotAnArray(): void
    {
        $body = json_encode(['data' => 'nope'], JSON_THROW_ON_ERROR);

        try {
            $this->parser->parse($this->response($body));
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request returned no data.', $exception->getMessage());
            $this->assertSame($body, $exception->getTechnicalDetails());
        }
    }

    public function testParseIgnoresEmptyErrorsListWhenDataPresent(): void
    {
        $response = $this->response(json_encode(['errors' => [], 'data' => ['ok' => true]], JSON_THROW_ON_ERROR));

        $this->assertSame(['ok' => true], $this->parser->parse($response));
    }

    /**
     * @param list<mixed> $errors
     */
    #[DataProvider('graphQlErrorsProvider')]
    public function testParseFormatsGraphQlErrors(array $errors, string $expectedDetails): void
    {
        $response = $this->response(json_encode(['errors' => $errors], JSON_THROW_ON_ERROR));

        try {
            $this->parser->parse($response);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request failed.', $exception->getMessage());
            $this->assertSame($expectedDetails, $exception->getTechnicalDetails());
        }
    }

    /**
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function graphQlErrorsProvider(): iterable
    {
        yield 'message only' => [[['message' => 'Team not found']], 'Team not found'];
        yield 'missing message' => [[[]], 'Unknown GraphQL error'];
        yield 'non-string message' => [[['message' => 42]], 'Unknown GraphQL error'];
        yield 'with extensions' => [
            [['message' => 'Forbidden', 'extensions' => ['code' => 'FORBIDDEN']]],
            'Forbidden ({"code":"FORBIDDEN"})',
        ];
        yield 'empty extensions' => [[['message' => 'Oops', 'extensions' => []]], 'Oops'];
        yield 'multiple errors' => [
            [['message' => 'A'], 'skipped', ['message' => 'B']],
            'A; B',
        ];
        yield 'only non-array errors' => [['not-an-array'], 'Linear GraphQL request failed.'];
    }

    public function testParseOmitsExtensionsThatEncodeToEmptyObject(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'errors' => [['message' => 'Oops', 'extensions' => new \stdClass()]],
        ]);

        try {
            $this->parser->parse($response);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Oops', $exception->getTechnicalDetails());
        }
    }

    public function testParseFallsBackToStatusCodeWhenBodyUnreadable(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(502);
        $response->method('getContent')->willThrowException(new \RuntimeException('broken stream'));

        try {
            $this->parser->parse($response);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('HTTP 502', $exception->getTechnicalDetails());
        }
    }

    private function response(string $body, int $statusCode = 200): ResponseInterface
    {
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => $statusCode])]);

        return $client->request('POST', 'https://api.linear.app/graphql');
    }
}
