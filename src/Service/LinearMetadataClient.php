<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Read-only Linear GraphQL client for project metadata discovery (workflow states, labels).
 */
class LinearMetadataClient
{
    private const TEAM_STATES_QUERY = <<<'GQL'
        query TeamStates($teamKey: String!) {
          team(id: $teamKey) {
            states {
              nodes {
                id
                name
                type
              }
            }
          }
        }
        GQL;

    private const TEAM_LABEL_GROUPS_QUERY = <<<'GQL'
        query TeamLabelGroups($teamKey: String!) {
          team(id: $teamKey) {
            labels(filter: { isGroup: { eq: true } }) {
              nodes {
                id
                name
                color
                children {
                  nodes {
                    id
                    name
                    color
                  }
                }
              }
            }
          }
        }
        GQL;

    private const TEAM_ORPHAN_LABELS_QUERY = <<<'GQL'
        query TeamOrphanLabels($teamKey: String!) {
          team(id: $teamKey) {
            labels(filter: { isGroup: { eq: false } }) {
              nodes {
                id
                name
                color
              }
            }
          }
        }
        GQL;

    private const UNGROUPED_GROUP_ID = '_ungrouped';

    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, type: string}>
     */
    public function getTeamWorkflowStates(string $teamKey): array
    {
        $response = $this->client->request('POST', 'graphql', [
            'json' => [
                'query' => self::TEAM_STATES_QUERY,
                'variables' => ['teamKey' => $teamKey],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch Linear workflow states for team \"{$teamKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $this->assertNoGraphQlErrors($data, $teamKey, 'workflow states');

        $nodes = $data['data']['team']['states']['nodes'] ?? [];
        if (! is_array($nodes)) {
            return [];
        }

        $states = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['id'], $node['name'], $node['type'])) {
                continue;
            }
            $states[] = [
                'id' => (string) $node['id'],
                'name' => (string) $node['name'],
                'type' => (string) $node['type'],
            ];
        }

        return $states;
    }

    /**
     * @return list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}>
     */
    public function getTeamLabelGroups(string $teamKey, bool $groupsOnly): array
    {
        $groups = $this->fetchLabelGroupNodes($teamKey);

        if (! $groupsOnly) {
            $orphans = $this->fetchOrphanLabelNodes($teamKey);
            if ($orphans !== []) {
                $groups[] = [
                    'id' => self::UNGROUPED_GROUP_ID,
                    'name' => 'Ungrouped',
                    'labels' => $orphans,
                ];
            }
        }

        return $groups;
    }

    /**
     * @return list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}>
     */
    protected function fetchLabelGroupNodes(string $teamKey): array
    {
        $response = $this->client->request('POST', 'graphql', [
            'json' => [
                'query' => self::TEAM_LABEL_GROUPS_QUERY,
                'variables' => ['teamKey' => $teamKey],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch Linear label groups for team \"{$teamKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $this->assertNoGraphQlErrors($data, $teamKey, 'label groups');

        $nodes = $data['data']['team']['labels']['nodes'] ?? [];
        if (! is_array($nodes)) {
            return [];
        }

        $groups = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['id'], $node['name'])) {
                continue;
            }

            $childNodes = $node['children']['nodes'] ?? [];
            $labels = [];
            if (is_array($childNodes)) {
                foreach ($childNodes as $child) {
                    if (! is_array($child) || ! isset($child['id'], $child['name'])) {
                        continue;
                    }
                    $labels[] = $this->normalizeLabelNode($child);
                }
            }

            $groups[] = [
                'id' => (string) $node['id'],
                'name' => (string) $node['name'],
                'labels' => $labels,
            ];
        }

        return $groups;
    }

    /**
     * @return list<array{id: string, name: string, color?: string}>
     */
    protected function fetchOrphanLabelNodes(string $teamKey): array
    {
        $response = $this->client->request('POST', 'graphql', [
            'json' => [
                'query' => self::TEAM_ORPHAN_LABELS_QUERY,
                'variables' => ['teamKey' => $teamKey],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch Linear labels for team \"{$teamKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $this->assertNoGraphQlErrors($data, $teamKey, 'labels');

        $nodes = $data['data']['team']['labels']['nodes'] ?? [];
        if (! is_array($nodes)) {
            return [];
        }

        $labels = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['id'], $node['name'])) {
                continue;
            }
            $labels[] = $this->normalizeLabelNode($node);
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{id: string, name: string, color?: string}
     */
    protected function normalizeLabelNode(array $node): array
    {
        $label = [
            'id' => (string) $node['id'],
            'name' => (string) $node['name'],
        ];

        if (isset($node['color']) && is_string($node['color']) && $node['color'] !== '') {
            $label['color'] = $node['color'];
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function assertNoGraphQlErrors(array $data, string $teamKey, string $resource): void
    {
        if (! isset($data['errors']) || ! is_array($data['errors']) || $data['errors'] === []) {
            return;
        }

        $first = $data['errors'][0] ?? [];
        $message = is_array($first) && isset($first['message']) && is_string($first['message'])
            ? $first['message']
            : 'Linear GraphQL request failed.';

        throw new ApiException(
            "Could not fetch Linear {$resource} for team \"{$teamKey}\".",
            $message,
        );
    }

    protected function extractTechnicalDetails(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return 'HTTP ' . $response->getStatusCode();
        }
    }
}
