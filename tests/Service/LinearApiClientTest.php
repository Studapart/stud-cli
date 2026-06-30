<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\LinearApiClient;
use App\Service\LinearGraphqlClient;
use App\Service\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinearApiClientTest extends TestCase
{
    private function createService(MockHttpClient $client, ?Logger $logger = null): LinearApiClient
    {
        return new LinearApiClient(new LinearGraphqlClient($client), $logger);
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

    public function testIssueCreateReturnsMappedIssue(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issueCreate' => [
                        'success' => true,
                        'issue' => [
                            'id' => 'issue-new',
                            'identifier' => 'SCI-99',
                            'url' => 'https://linear.app/studapart/issue/SCI-99',
                            'title' => 'New',
                            'description' => 'Desc',
                            'priority' => 2,
                            'state' => ['id' => 's1', 'name' => 'Todo', 'type' => 'unstarted'],
                            'team' => ['id' => 't1', 'key' => 'SCI', 'name' => 'Team'],
                            'labels' => ['nodes' => []],
                            'parent' => null,
                            'attachments' => ['nodes' => []],
                            'createdAt' => '2026-01-01T00:00:00.000Z',
                            'updatedAt' => '2026-01-01T00:00:00.000Z',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $issue = $service->issueCreate(['teamId' => 't1', 'title' => 'New', 'labelIds' => [], 'description' => null, 'priority' => null, 'parentId' => null]);

        $this->assertSame('SCI-99', $issue['identifier']);
        $this->assertStringContainsString('SCI-99', $issue['url']);
    }

    public function testIssueUpdateSucceedsWhenMutationReturnsSuccess(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issueUpdate' => [
                        'success' => true,
                        'issue' => [
                            'id' => 'issue-1',
                            'identifier' => 'SCI-1',
                            'title' => 'Updated',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $service->issueUpdate('issue-1', ['title' => 'Updated']);

        $this->addToAssertionCount(1);
    }

    public function testGetTeamByKeyReturnsTeam(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'teams' => [
                        'nodes' => [
                            ['id' => 't1', 'key' => 'SCI', 'name' => 'Stud'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $team = $service->getTeamByKey('SCI');

        $this->assertSame('SCI', $team?->key);
        $this->assertSame('Stud', $team?->name);
    }

    public function testGetTeamByKeyFallbackReturnsNullWhenTeamIdQueryIncomplete(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['errors' => [['message' => 'fail']]], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['team' => ['id' => 't1']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertNull($this->createService($client)->getTeamByKey('ENG'));
    }

    public function testResolveIssueIdReturnsInternalId(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issue' => [
                        'id' => 'uuid-1',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $this->assertSame('uuid-1', $service->resolveIssueId('SCI-1'));
    }

    public function testResolveTeamKeyFromIssue(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issue' => [
                        'team' => ['key' => 'ENG'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $this->assertSame('ENG', $service->resolveTeamKeyFromIssue('ENG-5'));
    }

    public function testResolveTeamIdReturnsIdFromTeamQuery(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['team' => ['id' => 'team-uuid', 'key' => 'SCI', 'name' => 'Team']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame('team-uuid', $this->createService($client)->resolveTeamId('SCI'));
    }

    public function testResolveTeamIdFallsBackToTeamsFilterQuery(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['errors' => [['message' => 'not found']]], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['teams' => ['nodes' => [['id' => 'fallback-id', 'key' => 'SCI', 'name' => 'Team']]]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('writeln')
            ->with(
                Logger::VERBOSITY_VERBOSE,
                'Linear team(id) lookup failed for "SCI"; falling back to teams filter query.',
            );

        $this->assertSame('fallback-id', $this->createService($client, $logger)->resolveTeamId('SCI'));
    }

    public function testResolveTeamIdLogsVerboseWhenPrimaryLookupReturnsEmptyId(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['team' => ['id' => '']]], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['teams' => ['nodes' => [['id' => 'fallback-id', 'key' => 'SCI', 'name' => 'Team']]]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('writeln')
            ->with(
                Logger::VERBOSITY_VERBOSE,
                'Linear team(id) lookup failed for "SCI"; falling back to teams filter query.',
            );

        $this->assertSame('fallback-id', $this->createService($client, $logger)->resolveTeamId('SCI'));
    }

    public function testResolveTeamIdThrowsWhenTeamCannotBeResolved(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['team' => ['id' => '']]], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['data' => ['teams' => ['nodes' => []]]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->resolveTeamId('MISSING');
    }

    public function testGetTeamByKeyFallsBackToTeamIdQuery(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['errors' => [['message' => 'fail']]], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['team' => ['id' => 't1', 'key' => 'ENG', 'name' => 'Engineering']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('writeln')
            ->with(
                Logger::VERBOSITY_VERBOSE,
                'Linear teams filter lookup failed for "ENG"; falling back to team(id) query.',
            );

        $team = $this->createService($client, $logger)->getTeamByKey('ENG');

        $this->assertSame('ENG', $team?->key);
        $this->assertSame('Engineering', $team?->name);
    }

    public function testGetTeamByKeyReturnsNullWhenLookupFails(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['teams' => ['nodes' => []]]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertNull($this->createService($client)->getTeamByKey('NONE'));
    }

    public function testResolveIssueIdThrowsWhenIssueMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issue' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->resolveIssueId('SCI-404');
    }

    public function testResolveTeamKeyFromIssueThrowsWhenTeamMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issue' => ['id' => 'x', 'team' => null]]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->resolveTeamKeyFromIssue('SCI-1');
    }

    public function testIssueCreateIncludesOptionalPayloadFields(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issueCreate' => [
                        'success' => true,
                        'issue' => [
                            'identifier' => 'SCI-100',
                            'url' => 'https://linear.app/SCI-100',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issue = $this->createService($client)->issueCreate([
            'teamId' => 't1',
            'title' => 'Full',
            'description' => 'Body',
            'labelIds' => ['l1'],
            'priority' => 2,
            'parentId' => 'parent-1',
        ]);

        $this->assertSame('SCI-100', $issue['identifier']);
    }

    public function testIssueCreateThrowsWhenMutationFails(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueCreate' => ['success' => false]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->issueCreate([
            'teamId' => 't1',
            'title' => 'Fail',
            'labelIds' => [],
            'description' => null,
            'priority' => null,
            'parentId' => null,
        ]);
    }

    public function testIssueCreateThrowsWhenResponseMissingIssue(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueCreate' => ['success' => true, 'issue' => ['identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->issueCreate([
            'teamId' => 't1',
            'title' => 'Fail',
            'labelIds' => [],
            'description' => null,
            'priority' => null,
            'parentId' => null,
        ]);
    }

    public function testIssueUpdateNoOpsWhenInputEmpty(): void
    {
        $client = new MockHttpClient([]);

        $this->createService($client)->issueUpdate('issue-1', []);

        $this->addToAssertionCount(1);
    }

    public function testIssueUpdateThrowsWhenMutationFails(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => false]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->issueUpdate('issue-1', ['title' => 'X']);
    }

    public function testResolveLabelIdsFiltersByTypeGroup(): void
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
                                            ['id' => 'label-story', 'name' => 'Story'],
                                        ],
                                    ],
                                ],
                                [
                                    'id' => 'group-2',
                                    'name' => 'Other',
                                    'children' => [
                                        'nodes' => [
                                            ['id' => 'label-bug', 'name' => 'Bug'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['team' => ['labels' => ['nodes' => []]]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $ids = $this->createService($client)->resolveLabelIds('SCI', ['Story', 'Bug'], 'group-1');

        $this->assertSame(['label-story'], $ids);
    }

    public function testResolveLabelIdsReturnsEmptyArrayWhenNoNamesRequested(): void
    {
        $client = new MockHttpClient([]);

        $this->assertSame([], $this->createService($client)->resolveLabelIds('SCI', [], null));
    }

    public function testGetTeamByKeyReturnsNullWhenTeamNodeMalformed(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['teams' => ['nodes' => [['id' => 't1']]]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertNull($this->createService($client)->getTeamByKey('SCI'));
    }

    public function testResolveLabelIdsWithoutTypeGroupIncludesAllMatchingLabels(): void
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
                                    'children' => ['nodes' => [['id' => 'label-story', 'name' => 'Story']]],
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
                                ['id' => 'orphan-1', 'name' => 'DX'],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $ids = $this->createService($client)->resolveLabelIds('SCI', ['Story', 'DX'], null);

        $this->assertEqualsCanonicalizing(['label-story', 'orphan-1'], $ids);
    }

    public function testIssueUpdateSendsDescriptionAndPriorityFields(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'i1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->issueUpdate('issue-1', [
            'title' => 'T',
            'description' => null,
            'priority' => 3,
            'labelIds' => ['label-1'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testIssueUpdateSendsStateIdField(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'i1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->issueUpdate('issue-1', [
            'stateId' => 'state-started-uuid',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testGetIssueReturnsIssueNode(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issue' => [
                        'id' => 'issue-1',
                        'identifier' => 'SCI-123',
                        'title' => 'Show me',
                        'state' => ['name' => 'Todo'],
                        'assignee' => ['name' => 'Ada'],
                        'labels' => ['nodes' => []],
                        'attachments' => ['nodes' => []],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issue = $this->createService($client)->getIssue('SCI-123');

        $this->assertSame('SCI-123', $issue['identifier']);
    }

    public function testGetIssueThrowsWhenMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issue' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->getIssue('MISSING');
    }

    public function testListAssignedActiveIssuesReturnsNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issues' => [
                        'nodes' => [
                            ['id' => 'i1', 'identifier' => 'ENG-1', 'title' => 'One'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issues = $this->createService($client)->listAssignedActiveIssues('ENG', true);

        $this->assertCount(1, $issues);
        $this->assertSame('ENG-1', $issues[0]['identifier']);
    }

    public function testListAssignedActiveIssuesReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issues' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->listAssignedActiveIssues(null, false));
    }

    public function testSearchIssuesReturnsNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'searchIssues' => [
                        'nodes' => [
                            [
                                'id' => 'issue-1',
                                'identifier' => 'SCI-99',
                                'title' => 'Login bug',
                                'url' => 'https://linear.app/studapart/issue/SCI-99',
                                'state' => ['name' => 'Todo'],
                                'labels' => ['nodes' => []],
                            ],
                        ],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issues = $this->createService($client)->searchIssues('login bug');

        $this->assertCount(1, $issues);
        $this->assertSame('SCI-99', $issues[0]['identifier']);
    }

    public function testSearchIssuesReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['searchIssues' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->searchIssues('login bug'));
    }

    public function testSearchIssuesPassesAfterCursorWhenProvided(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'searchIssues' => [
                        'nodes' => [],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->searchIssues('login bug', 25, 'cursor-abc');

        $this->addToAssertionCount(1);
    }

    public function testListTeamsReturnsTeamRows(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'teams' => [
                        'nodes' => [
                            ['id' => 't1', 'key' => 'ENG', 'name' => 'Engineering'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $teams = $this->createService($client)->listTeams();

        $this->assertSame([['key' => 'ENG', 'name' => 'Engineering']], $teams);
    }

    public function testListTeamsSkipsIncompleteNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'teams' => [
                        'nodes' => [
                            ['id' => 't1'],
                            ['key' => 'SCI', 'name' => 'Stud'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([['key' => 'SCI', 'name' => 'Stud']], $this->createService($client)->listTeams());
    }

    public function testListTeamsReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['teams' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->listTeams());
    }

    public function testPingSucceedsWhenViewerReturned(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['viewer' => ['id' => 'viewer-1', 'name' => 'Test']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->ping();
        $this->addToAssertionCount(1);
    }

    public function testPingThrowsWhenViewerMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['viewer' => ['id' => '']]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->ping();
    }

    public function testGetViewerIdReturnsViewerUuid(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['viewer' => ['id' => 'viewer-uuid-1', 'name' => 'Test']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame('viewer-uuid-1', $this->createService($client)->getViewerId());
    }

    public function testAssignIssueUsesViewerWhenAssigneeOmitted(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issue' => ['id' => 'issue-uuid-1']],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['viewer' => ['id' => 'viewer-uuid-1', 'name' => 'Test']],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'issue-uuid-1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->assignIssue('SCI-1');
        $this->addToAssertionCount(1);
    }

    public function testAssignIssueUsesProvidedAssigneeId(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issue' => ['id' => 'issue-uuid-1']],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'issue-uuid-1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->assignIssue('SCI-1', 'other-user-uuid');
        $this->addToAssertionCount(1);
    }

    public function testIssueUpdateSendsAssigneeIdField(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'i1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->issueUpdate('issue-1', [
            'assigneeId' => 'viewer-uuid-1',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testListCustomViewsReturnsMappedNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'customViews' => [
                        'nodes' => [
                            [
                                'id' => 'view-1',
                                'name' => 'Active Bugs',
                                'description' => 'Open bugs',
                                'filterData' => ['state' => ['type' => ['in' => ['started']]]],
                            ],
                            [
                                'id' => 'view-2',
                                'name' => 'No description',
                                'description' => '   ',
                                'filterData' => ['number' => ['eq' => -1]],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $views = $this->createService($client)->listCustomViews();

        $this->assertCount(2, $views);
        $this->assertSame('view-1', $views[0]['id']);
        $this->assertSame('Active Bugs', $views[0]['name']);
        $this->assertSame('Open bugs', $views[0]['description']);
        $this->assertSame(['state' => ['type' => ['in' => ['started']]]], $views[0]['filterData']);
        $this->assertNull($views[1]['description']);
    }

    public function testListCustomViewsReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['customViews' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->listCustomViews());
    }

    public function testListCustomViewsSkipsIncompleteNodes(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'customViews' => [
                        'nodes' => [
                            ['name' => 'Missing id'],
                            ['id' => 'view-1', 'name' => 'Valid'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $views = $this->createService($client)->listCustomViews();

        $this->assertCount(1, $views);
        $this->assertSame('Valid', $views[0]['name']);
        $this->assertSame([], $views[0]['filterData']);
    }

    public function testListCustomViewsCoercesNonArrayFilterDataToEmptyArray(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'customViews' => [
                        'nodes' => [
                            ['id' => 'view-1', 'name' => 'Broken filter', 'filterData' => 'not-an-array'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $views = $this->createService($client)->listCustomViews();

        $this->assertCount(1, $views);
        $this->assertSame('Broken filter', $views[0]['name']);
        $this->assertSame([], $views[0]['filterData']);
    }

    public function testResolveCustomViewByNamePrefersExactMatch(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'customViews' => [
                        'nodes' => [
                            ['id' => 'view-1', 'name' => 'Active Bugs', 'filterData' => ['a' => 1]],
                            ['id' => 'view-2', 'name' => 'active bugs', 'filterData' => ['b' => 2]],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $view = $this->createService($client)->resolveCustomViewByName('Active Bugs');

        $this->assertSame('view-1', $view['id']);
    }

    public function testResolveCustomViewByNameFallsBackToCaseInsensitiveMatch(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'customViews' => [
                        'nodes' => [
                            ['id' => 'view-2', 'name' => 'active bugs', 'filterData' => ['b' => 2]],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $view = $this->createService($client)->resolveCustomViewByName('Active Bugs');

        $this->assertSame('view-2', $view['id']);
    }

    public function testResolveCustomViewByNameReturnsNullWhenMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['customViews' => ['nodes' => []]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertNull($this->createService($client)->resolveCustomViewByName('Missing'));
    }

    public function testListIssuesByFilterReturnsNodes(): void
    {
        $filter = ['labels' => ['some' => ['name' => ['eq' => 'Bug']]]];
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issues' => [
                        'nodes' => [
                            ['id' => 'i1', 'identifier' => 'SCI-9', 'title' => 'Bug fix'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issues = $this->createService($client)->listIssuesByFilter($filter);

        $this->assertCount(1, $issues);
        $this->assertSame('SCI-9', $issues[0]['identifier']);
    }

    public function testListIssuesByFilterReturnsEmptyWhenNodesMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['issues' => null]], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $this->createService($client)->listIssuesByFilter(['number' => ['eq' => -1]]));
    }
}
