<?php

declare(strict_types=1);

namespace App\Service\Linear;

use App\DTO\Project;
use App\Exception\ApiException;
use App\Service\LinearGraphqlClient;

/**
 * Linear workspace lookups: teams, custom views, and the authenticated viewer.
 */
class LinearWorkspaceClient
{
    private const TEAM_ID_QUERY = <<<'GQL'
        query TeamId($teamKey: String!) {
          team(id: $teamKey) {
            id
            key
            name
          }
        }
        GQL;

    private const TEAM_BY_KEY_QUERY = <<<'GQL'
        query TeamByKey($teamKey: String!) {
          teams(filter: { key: { eq: $teamKey } }, first: 1) {
            nodes {
              id
              key
              name
            }
          }
        }
        GQL;

    private const CUSTOM_VIEWS_QUERY = <<<'GQL'
        query CustomViews {
          customViews {
            nodes {
              id
              name
              description
              filterData
            }
          }
        }
        GQL;

    private const TEAMS_LIST_QUERY = <<<'GQL'
        query TeamsList {
          teams {
            nodes {
              id
              key
              name
            }
          }
        }
        GQL;

    private const VIEWER_PING_QUERY = <<<'GQL'
        query ViewerPing {
          viewer {
            id
            name
          }
        }
        GQL;

    public function __construct(
        private readonly LinearGraphqlClient $graphqlClient,
    ) {
    }

    public function getTeamByKey(string $teamKey): ?Project
    {
        try {
            $data = $this->graphqlClient->query(self::TEAM_BY_KEY_QUERY, ['teamKey' => $teamKey]);
        } catch (ApiException) {
            $data = $this->graphqlClient->query(self::TEAM_ID_QUERY, ['teamKey' => $teamKey]);
            $team = $data['team'] ?? null;
            if (! is_array($team) || ! isset($team['id'], $team['key'], $team['name'])) {
                return null;
            }

            return new Project(key: (string) $team['key'], name: (string) $team['name']);
        }

        $nodes = $data['teams']['nodes'] ?? [];
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $team = $nodes[0];
        if (! is_array($team) || ! isset($team['key'], $team['name'])) {
            return null;
        }

        return new Project(key: (string) $team['key'], name: (string) $team['name']);
    }

    public function resolveTeamId(string $teamKey): string
    {
        try {
            $data = $this->graphqlClient->query(self::TEAM_ID_QUERY, ['teamKey' => $teamKey]);
            $teamId = $data['team']['id'] ?? null;
            if (is_string($teamId) && $teamId !== '') {
                return $teamId;
            }
        } catch (ApiException) {
            // fall through to teams filter lookup
        }

        $data = $this->graphqlClient->query(self::TEAM_BY_KEY_QUERY, ['teamKey' => $teamKey]);
        $nodes = $data['teams']['nodes'] ?? [];
        if (is_array($nodes) && isset($nodes[0]['id']) && is_string($nodes[0]['id']) && $nodes[0]['id'] !== '') {
            return $nodes[0]['id'];
        }

        throw new ApiException(
            sprintf('Could not resolve Linear team id for key "%s".', $teamKey),
            'Team lookup returned no nodes.',
            404,
        );
    }

    /**
     * @return list<array{key: string, name: string}>
     */
    public function listTeams(): array
    {
        $data = $this->graphqlClient->query(self::TEAMS_LIST_QUERY);
        $nodes = $data['teams']['nodes'] ?? null;
        if (! is_array($nodes)) {
            return [];
        }

        $teams = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['key'], $node['name'])) {
                continue;
            }
            $teams[] = [
                'key' => (string) $node['key'],
                'name' => (string) $node['name'],
            ];
        }

        return $teams;
    }

    /**
     * @return list<array{id: string, name: string, description: ?string, filterData: array<string, mixed>}>
     */
    public function listCustomViews(): array
    {
        $data = $this->graphqlClient->query(self::CUSTOM_VIEWS_QUERY);
        $nodes = $data['customViews']['nodes'] ?? null;
        if (! is_array($nodes)) {
            return [];
        }

        $views = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['id'], $node['name'])) {
                continue;
            }

            $filterData = $node['filterData'] ?? [];
            if (! is_array($filterData)) {
                $filterData = [];
            }

            $description = $node['description'] ?? null;

            $views[] = [
                'id' => (string) $node['id'],
                'name' => (string) $node['name'],
                'description' => is_string($description) && trim($description) !== '' ? $description : null,
                'filterData' => $filterData,
            ];
        }

        return $views;
    }

    /**
     * @return array{id: string, name: string, description: ?string, filterData: array<string, mixed>}|null
     */
    public function resolveCustomViewByName(string $name): ?array
    {
        $views = $this->listCustomViews();
        foreach ($views as $view) {
            if ($view['name'] === $name) {
                return $view;
            }
        }

        foreach ($views as $view) {
            if (strcasecmp($view['name'], $name) === 0) {
                return $view;
            }
        }

        return null;
    }

    public function getViewerId(): string
    {
        $data = $this->graphqlClient->query(self::VIEWER_PING_QUERY);
        $viewerId = $data['viewer']['id'] ?? null;
        if (! is_string($viewerId) || $viewerId === '') {
            throw new ApiException(
                'Linear viewer ping failed.',
                'Viewer query returned no id.',
            );
        }

        return $viewerId;
    }
}
