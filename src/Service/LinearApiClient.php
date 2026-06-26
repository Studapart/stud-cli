<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;

/**
 * Read-only Linear GraphQL client for project metadata discovery (workflow states, labels).
 */
class LinearApiClient
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
        private readonly LinearGraphqlClient $graphqlClient,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, type: string}>
     */
    public function getTeamWorkflowStates(string $teamKey): array
    {
        $data = $this->queryForTeam($teamKey, 'workflow states', self::TEAM_STATES_QUERY);

        $nodes = $data['team']['states']['nodes'] ?? [];
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
        $data = $this->queryForTeam($teamKey, 'label groups', self::TEAM_LABEL_GROUPS_QUERY);

        $nodes = $data['team']['labels']['nodes'] ?? [];
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
        $data = $this->queryForTeam($teamKey, 'labels', self::TEAM_ORPHAN_LABELS_QUERY);

        $nodes = $data['team']['labels']['nodes'] ?? [];
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
     * @return array<string, mixed>
     */
    private function queryForTeam(string $teamKey, string $resource, string $query): array
    {
        try {
            return $this->graphqlClient->query($query, ['teamKey' => $teamKey]);
        } catch (ApiException $e) {
            throw new ApiException(
                "Could not fetch Linear {$resource} for team \"{$teamKey}\".",
                $e->getTechnicalDetails(),
                $e->getStatusCode(),
                $e,
            );
        }
    }
}
