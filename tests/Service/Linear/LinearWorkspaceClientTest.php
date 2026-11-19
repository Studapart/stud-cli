<?php

declare(strict_types=1);

namespace App\Tests\Service\Linear;

use App\Exception\ApiException;
use App\Service\Linear\LinearWorkspaceClient;
use App\Service\LinearGraphqlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinearWorkspaceClientTest extends TestCase
{
    private function createService(MockHttpClient $client): LinearWorkspaceClient
    {
        return new LinearWorkspaceClient(new LinearGraphqlClient($client));
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

        $this->assertSame('fallback-id', $this->createService($client)->resolveTeamId('SCI'));
    }

    public function testResolveTeamIdFallsBackWhenPrimaryLookupReturnsEmptyId(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['team' => ['id' => '']]], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'data' => ['teams' => ['nodes' => [['id' => 'fallback-id', 'key' => 'SCI', 'name' => 'Team']]]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame('fallback-id', $this->createService($client)->resolveTeamId('SCI'));
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

        $team = $this->createService($client)->getTeamByKey('ENG');

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

    public function testGetTeamByKeyReturnsNullWhenTeamNodeMalformed(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['teams' => ['nodes' => [['id' => 't1']]]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertNull($this->createService($client)->getTeamByKey('SCI'));
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

    public function testGetViewerIdFromPingSucceedsWhenViewerReturned(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['viewer' => ['id' => 'viewer-1', 'name' => 'Test']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->getViewerId();
        $this->addToAssertionCount(1);
    }

    public function testGetViewerIdFromPingThrowsWhenViewerMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['data' => ['viewer' => ['id' => '']]], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->getViewerId();
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
}
