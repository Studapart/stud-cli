<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\LinearMetadataClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinearMetadataClientTest extends TestCase
{
    public function testGetTeamWorkflowStatesReturnsNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'states' => [
                            'nodes' => [
                                ['id' => 'state-1', 'name' => 'Todo', 'type' => 'unstarted'],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new LinearMetadataClient($client);
        $states = $service->getTeamWorkflowStates('SCI');

        $this->assertSame([
            ['id' => 'state-1', 'name' => 'Todo', 'type' => 'unstarted'],
        ], $states);
    }

    public function testGetTeamWorkflowStatesThrowsOnGraphQlErrorWithoutMessage(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'errors' => [[]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new LinearMetadataClient($client);

        try {
            $service->getTeamWorkflowStates('SCI');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear GraphQL request failed.', $exception->getTechnicalDetails());
        }
    }

    public function testGetTeamWorkflowStatesThrowsOnGraphQlError(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'errors' => [['message' => 'Team not found']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new LinearMetadataClient($client);

        $this->expectException(ApiException::class);
        $service->getTeamWorkflowStates('SCI');
    }

    public function testGetTeamWorkflowStatesThrowsOnNon200Response(): void
    {
        $client = new MockHttpClient([
            new MockResponse('Service unavailable', ['http_code' => 503]),
        ]);

        $service = new LinearMetadataClient($client);

        try {
            $service->getTeamWorkflowStates('SCI');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('SCI', $exception->getMessage());
            $this->assertSame('Service unavailable', $exception->getTechnicalDetails());
        }
    }

    public function testGetTeamWorkflowStatesSkipsMalformedNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'states' => [
                            'nodes' => [
                                ['id' => 'state-1'],
                                ['id' => 'state-2', 'name' => 'Done', 'type' => 'completed'],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new LinearMetadataClient($client);

        $this->assertSame([
            ['id' => 'state-2', 'name' => 'Done', 'type' => 'completed'],
        ], $service->getTeamWorkflowStates('SCI'));
    }

    public function testGetTeamWorkflowStatesReturnsEmptyListWhenNodesAreNotArray(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'states' => [
                            'nodes' => 'invalid',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new LinearMetadataClient($client);

        $this->assertSame([], $service->getTeamWorkflowStates('SCI'));
    }

    public function testExtractTechnicalDetailsReturnsResponseBody(): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getContent')->with(false)->willReturn('linear error body');

        $service = new LinearMetadataClient(new MockHttpClient());
        $method = new \ReflectionMethod(LinearMetadataClient::class, 'extractTechnicalDetails');
        $method->setAccessible(true);

        $this->assertSame('linear error body', $method->invoke($service, $response));
    }

    public function testExtractTechnicalDetailsFallsBackToStatusCodeWhenBodyUnreadable(): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(502);
        $response->method('getContent')->with(false)->willThrowException(new \RuntimeException('broken stream'));

        $service = new LinearMetadataClient(new MockHttpClient());
        $method = new \ReflectionMethod(LinearMetadataClient::class, 'extractTechnicalDetails');
        $method->setAccessible(true);

        $this->assertSame('HTTP 502', $method->invoke($service, $response));
    }
}
