<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\LinearApiClient;
use App\Service\LinearGraphqlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinearApiClientTest extends TestCase
{
    private function createService(MockHttpClient $client): LinearApiClient
    {
        return new LinearApiClient(new LinearGraphqlClient($client));
    }

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

        $service = $this->createService($client);
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

        $service = $this->createService($client);

        try {
            $service->getTeamWorkflowStates('SCI');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Unknown GraphQL error', $exception->getTechnicalDetails());
        }
    }

    public function testGetTeamWorkflowStatesThrowsOnGraphQlError(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'errors' => [['message' => 'Team not found']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);

        $this->expectException(ApiException::class);
        $service->getTeamWorkflowStates('SCI');
    }

    public function testGetTeamWorkflowStatesThrowsOnNon200Response(): void
    {
        $client = new MockHttpClient([
            new MockResponse('Service unavailable', ['http_code' => 503]),
        ]);

        $service = $this->createService($client);

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

        $service = $this->createService($client);

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

        $service = $this->createService($client);

        $this->assertSame([], $service->getTeamWorkflowStates('SCI'));
    }

    public function testGetTeamLabelGroupsReturnsGroupsWithChildren(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('TeamLabelGroups', $body['query']);
            $this->assertStringContainsString('isGroup: { eq: true }', $body['query']);

            return new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [
                                [
                                    'id' => 'group-1',
                                    'name' => 'Type',
                                    'color' => '#111111',
                                    'children' => [
                                        'nodes' => [
                                            ['id' => 'label-1', 'name' => 'Bug', 'color' => '#ff0000'],
                                            ['id' => 'label-2', 'name' => 'Story'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $service = $this->createService($client);
        $groups = $service->getTeamLabelGroups('SCI', true);

        $this->assertSame([
            [
                'id' => 'group-1',
                'name' => 'Type',
                'labels' => [
                    ['id' => 'label-1', 'name' => 'Bug', 'color' => '#ff0000'],
                    ['id' => 'label-2', 'name' => 'Story'],
                ],
            ],
        ], $groups);
    }

    public function testGetTeamLabelGroupsIncludesOrphansWhenGroupsOnlyIsFalse(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [
                                [
                                    'id' => 'group-1',
                                    'name' => 'Type',
                                    'children' => [
                                        'nodes' => [
                                            ['id' => 'label-1', 'name' => 'Bug'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [
                                ['id' => 'orphan-1', 'name' => 'DX', 'color' => '#00ff00'],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $groups = $service->getTeamLabelGroups('SCI', false);

        $this->assertCount(2, $groups);
        $this->assertSame('group-1', $groups[0]['id']);
        $this->assertSame('_ungrouped', $groups[1]['id']);
        $this->assertSame('orphan-1', $groups[1]['labels'][0]['id']);
    }

    public function testGetTeamLabelGroupsThrowsOnLabelGroupGraphQlError(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'errors' => [['message' => 'Team not found']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);

        $this->expectException(ApiException::class);
        $service->getTeamLabelGroups('SCI', true);
    }

    public function testGetTeamLabelGroupsThrowsOnOrphanFetchFailure(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse('Service unavailable', ['http_code' => 503]),
        ]);

        $service = $this->createService($client);

        try {
            $service->getTeamLabelGroups('SCI', false);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('labels', $exception->getMessage());
        }
    }

    public function testGetTeamLabelGroupsThrowsOnNon200GroupResponse(): void
    {
        $client = new MockHttpClient([
            new MockResponse('Service unavailable', ['http_code' => 503]),
        ]);

        $service = $this->createService($client);

        try {
            $service->getTeamLabelGroups('SCI', true);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('label groups', $exception->getMessage());
        }
    }

    public function testGetTeamLabelGroupsSkipsMalformedGroupNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [
                                ['name' => 'Missing id'],
                                [
                                    'id' => 'group-1',
                                    'name' => 'Type',
                                    'children' => [
                                        'nodes' => [
                                            ['id' => 'label-1'],
                                            ['id' => 'label-2', 'name' => 'Bug'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);

        $this->assertSame([
            [
                'id' => 'group-1',
                'name' => 'Type',
                'labels' => [
                    ['id' => 'label-2', 'name' => 'Bug'],
                ],
            ],
        ], $service->getTeamLabelGroups('SCI', true));
    }

    public function testGetTeamLabelGroupsReturnsEmptyListWhenGroupNodesAreNotArray(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => 'invalid',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);

        $this->assertSame([], $service->getTeamLabelGroups('SCI', true));
    }

    public function testGetTeamLabelGroupsDoesNotAddUngroupedBucketWhenNoOrphans(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [
                                [
                                    'id' => 'group-1',
                                    'name' => 'Type',
                                    'children' => [
                                        'nodes' => [
                                            ['id' => 'label-1', 'name' => 'Bug'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);

        $this->assertCount(1, $service->getTeamLabelGroups('SCI', false));
    }

    public function testGetTeamLabelGroupsReturnsEmptyOrphansWhenNodesAreNotArray(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => 'invalid',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);

        $this->assertSame([], $service->getTeamLabelGroups('SCI', false));
    }

    public function testGetTeamLabelGroupsThrowsOnOrphanGraphQlError(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'errors' => [['message' => 'Team not found']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);

        $this->expectException(ApiException::class);
        $service->getTeamLabelGroups('SCI', false);
    }

    public function testGetTeamLabelGroupsSkipsMalformedOrphanNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => [
                    'team' => [
                        'labels' => [
                            'nodes' => [
                                ['name' => 'Missing id'],
                                ['id' => 'orphan-1', 'name' => 'DX'],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $groups = $service->getTeamLabelGroups('SCI', false);

        $this->assertCount(1, $groups);
        $this->assertSame('_ungrouped', $groups[0]['id']);
        $this->assertSame('orphan-1', $groups[0]['labels'][0]['id']);
    }
}
