<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Project;
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

    private const ISSUE_ID_QUERY = <<<'GQL'
        query IssueId($identifier: String!) {
          issue(id: $identifier) {
            id
            identifier
            team {
              key
            }
          }
        }
        GQL;

    private const ISSUE_CREATE_MUTATION = <<<'GQL'
        mutation IssueCreate($input: IssueCreateInput!) {
          issueCreate(input: $input) {
            success
            issue {
              id
              identifier
              url
            }
          }
        }
        GQL;

    private const ISSUE_UPDATE_MUTATION = <<<'GQL'
        mutation IssueUpdate($id: String!, $input: IssueUpdateInput!) {
          issueUpdate(id: $id, input: $input) {
            success
            issue {
              id
              identifier
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

    public function resolveIssueId(string $identifier): string
    {
        $data = $this->graphqlClient->query(self::ISSUE_ID_QUERY, ['identifier' => $identifier]);
        $issueId = $data['issue']['id'] ?? null;
        if (! is_string($issueId) || $issueId === '') {
            throw new ApiException(
                sprintf('Could not resolve Linear issue id for key "%s".', $identifier),
                'Issue lookup returned no id.',
                404,
            );
        }

        return $issueId;
    }

    public function resolveTeamKeyFromIssue(string $identifier): string
    {
        $data = $this->graphqlClient->query(self::ISSUE_ID_QUERY, ['identifier' => $identifier]);
        $teamKey = $data['issue']['team']['key'] ?? null;
        if (! is_string($teamKey) || $teamKey === '') {
            throw new ApiException(
                sprintf('Could not resolve Linear team for issue "%s".', $identifier),
                'Issue lookup returned no team key.',
                404,
            );
        }

        return $teamKey;
    }

    /**
     * @param list<string> $labelNames
     *
     * @return list<string>
     */
    public function resolveLabelIds(string $teamKey, array $labelNames, ?string $typeGroupId = null): array
    {
        if ($labelNames === []) {
            return [];
        }

        $groups = $this->getTeamLabelGroups($teamKey, false);
        $nameIndex = $this->buildLabelNameIndex($groups, $typeGroupId);
        $ids = [];

        foreach ($labelNames as $name) {
            $normalized = strtolower(trim($name));
            if ($normalized === '' || ! isset($nameIndex[$normalized])) {
                continue;
            }
            $ids[] = $nameIndex[$normalized];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array{teamId: string, title: string, description: ?string, labelIds: list<string>, priority: ?int, parentId: ?string} $input
     *
     * @return array{identifier: string, url: string}
     */
    public function issueCreate(array $input): array
    {
        $payload = [
            'teamId' => $input['teamId'],
            'title' => $input['title'],
        ];

        if ($input['description'] !== null && $input['description'] !== '') {
            $payload['description'] = $input['description'];
        }
        if ($input['labelIds'] !== []) {
            $payload['labelIds'] = $input['labelIds'];
        }
        if ($input['priority'] !== null) {
            $payload['priority'] = $input['priority'];
        }
        if ($input['parentId'] !== null && $input['parentId'] !== '') {
            $payload['parentId'] = $input['parentId'];
        }

        $data = $this->graphqlClient->query(self::ISSUE_CREATE_MUTATION, ['input' => $payload]);
        $result = $data['issueCreate'] ?? null;
        if (! is_array($result) || ! ($result['success'] ?? false)) {
            throw new ApiException(
                'Could not create Linear issue.',
                json_encode($result, JSON_UNESCAPED_UNICODE) ?: 'issueCreate returned success=false',
            );
        }

        $issue = $result['issue'] ?? null;
        if (! is_array($issue) || ! isset($issue['identifier'], $issue['url'])) {
            throw new ApiException(
                'Could not create Linear issue.',
                'issueCreate response missing issue identifier or url.',
            );
        }

        return [
            'identifier' => (string) $issue['identifier'],
            'url' => (string) $issue['url'],
        ];
    }

    /**
     * @param array{title?: string, description?: ?string, labelIds?: list<string>, priority?: ?int} $input
     */
    public function issueUpdate(string $issueId, array $input): void
    {
        if ($input === []) {
            return;
        }

        $payload = [];
        if (isset($input['title'])) {
            $payload['title'] = $input['title'];
        }
        if (array_key_exists('description', $input)) {
            $payload['description'] = $input['description'];
        }
        if (isset($input['labelIds'])) {
            $payload['labelIds'] = $input['labelIds'];
        }
        if (array_key_exists('priority', $input)) {
            $payload['priority'] = $input['priority'];
        }

        $data = $this->graphqlClient->query(self::ISSUE_UPDATE_MUTATION, [
            'id' => $issueId,
            'input' => $payload,
        ]);
        $result = $data['issueUpdate'] ?? null;
        if (! is_array($result) || ! ($result['success'] ?? false)) {
            throw new ApiException(
                'Could not update Linear issue.',
                json_encode($result, JSON_UNESCAPED_UNICODE) ?: 'issueUpdate returned success=false',
            );
        }
    }

    /**
     * @param list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}> $groups
     *
     * @return array<string, string>
     */
    protected function buildLabelNameIndex(array $groups, ?string $typeGroupId): array
    {
        $index = [];
        foreach ($groups as $group) {
            $groupId = $group['id'];
            if ($typeGroupId !== null && $typeGroupId !== '' && $groupId !== $typeGroupId) {
                continue;
            }

            foreach ($group['labels'] as $label) {
                $name = strtolower(trim($label['name']));
                if ($name !== '') {
                    $index[$name] = (string) $label['id'];
                }
            }
        }

        return $index;
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
