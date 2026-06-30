<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Project;
use App\Exception\ApiException;
use App\Service\Linear\LinearAttachmentMutationKeys;
use App\Service\Linear\LinearIssueMutationKeys;

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

    private const ISSUE_SHOW_QUERY = <<<'GQL'
        query IssueShow($identifier: String!) {
          issue(id: $identifier) {
            id
            identifier
            title
            description
            priority
            url
            state {
              name
            }
            assignee {
              name
            }
            labels {
              nodes {
                id
                name
                parent {
                  id
                }
              }
            }
            attachments {
              nodes {
                id
                title
                url
                size
                contentType
              }
            }
          }
        }
        GQL;

    private const ISSUES_LIST_QUERY = <<<'GQL'
        query AssignedIssues($filter: IssueFilter) {
          issues(filter: $filter, first: 50) {
            nodes {
              id
              identifier
              title
              description
              priority
              url
              state {
                name
              }
              assignee {
                name
              }
              labels {
                nodes {
                  id
                  name
                  parent {
                    id
                  }
                }
              }
            }
          }
        }
        GQL;

    private const SEARCH_ISSUES_QUERY = <<<'GQL'
        query SearchIssues($term: String!, $first: Int, $after: String) {
          searchIssues(term: $term, first: $first, after: $after) {
            nodes {
              id
              identifier
              title
              description
              priority
              url
              state {
                name
              }
              assignee {
                name
              }
              labels {
                nodes {
                  id
                  name
                  parent {
                    id
                  }
                }
              }
            }
            pageInfo {
              hasNextPage
              endCursor
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

    private const FILE_UPLOAD_MUTATION = <<<'GQL'
        mutation FileUpload($filename: String!, $contentType: String!, $size: Int!) {
          fileUpload(filename: $filename, contentType: $contentType, size: $size) {
            success
            uploadFile {
              uploadUrl
              assetUrl
              headers {
                key
                value
              }
            }
          }
        }
        GQL;

    private const ATTACHMENT_CREATE_MUTATION = <<<'GQL'
        mutation AttachmentCreate($input: AttachmentCreateInput!) {
          attachmentCreate(input: $input) {
            success
            attachment {
              id
              url
            }
          }
        }
        GQL;

    private const UNGROUPED_GROUP_ID = '_ungrouped';

    public function __construct(
        private readonly LinearGraphqlClient $graphqlClient,
        private readonly ?Logger $logger = null,
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
            $this->logVerboseFallback(sprintf(
                'Linear teams filter lookup failed for "%s"; falling back to team(id) query.',
                $teamKey,
            ));
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

        $this->logVerboseFallback(sprintf(
            'Linear team(id) lookup failed for "%s"; falling back to teams filter query.',
            $teamKey,
        ));

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
     * @return array<string, mixed>
     */
    public function getIssue(string $identifier): array
    {
        $data = $this->graphqlClient->query(self::ISSUE_SHOW_QUERY, ['identifier' => $identifier]);
        $issue = $data['issue'] ?? null;
        if (! is_array($issue) || ! isset($issue['identifier'])) {
            throw new ApiException(
                sprintf('Could not fetch Linear issue "%s".', $identifier),
                'Issue lookup returned no issue node.',
                404,
            );
        }

        return $issue;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAssignedActiveIssues(?string $teamKey, bool $onlyMine): array
    {
        return $this->fetchIssueNodes(
            $this->buildAssignedActiveFilter($teamKey, $onlyMine),
        );
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    public function listIssuesByFilter(array $filter): array
    {
        return $this->fetchIssueNodes($filter);
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

    /**
     * @return list<array<string, mixed>>
     */
    public function searchIssues(string $term, int $first = 50, ?string $after = null): array
    {
        $variables = [
            'term' => $term,
            'first' => $first,
        ];
        if ($after !== null && $after !== '') {
            $variables['after'] = $after;
        }

        $data = $this->graphqlClient->query(self::SEARCH_ISSUES_QUERY, $variables);
        $nodes = $data['searchIssues']['nodes'] ?? null;
        if (! is_array($nodes)) {
            return [];
        }

        $issues = [];
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $issues[] = $node;
            }
        }

        return $issues;
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

    public function ping(): void
    {
        $this->getViewerId();
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

    public function assignIssue(string $issueKey, ?string $assigneeId = null): void
    {
        $issueId = $this->resolveIssueId($issueKey);
        $this->issueUpdate($issueId, [
            LinearIssueMutationKeys::ASSIGNEE_ID => $assigneeId ?? $this->getViewerId(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildAssignedActiveFilter(?string $teamKey, bool $onlyMine): array
    {
        $filter = [
            'state' => ['type' => ['nin' => ['completed', 'canceled']]],
        ];

        if ($onlyMine) {
            $filter['assignee'] = ['isMe' => ['eq' => true]];
        }

        if ($teamKey !== null && trim($teamKey) !== '') {
            $filter['team'] = ['key' => ['eq' => trim($teamKey)]];
        }

        return $filter;
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
            LinearIssueMutationKeys::TEAM_ID => $input[LinearIssueMutationKeys::TEAM_ID],
            LinearIssueMutationKeys::TITLE => $input[LinearIssueMutationKeys::TITLE],
        ];

        if ($input[LinearIssueMutationKeys::DESCRIPTION] !== null && $input[LinearIssueMutationKeys::DESCRIPTION] !== '') {
            $payload[LinearIssueMutationKeys::DESCRIPTION] = $input[LinearIssueMutationKeys::DESCRIPTION];
        }
        if ($input[LinearIssueMutationKeys::LABEL_IDS] !== []) {
            $payload[LinearIssueMutationKeys::LABEL_IDS] = $input[LinearIssueMutationKeys::LABEL_IDS];
        }
        if ($input[LinearIssueMutationKeys::PRIORITY] !== null) {
            $payload[LinearIssueMutationKeys::PRIORITY] = $input[LinearIssueMutationKeys::PRIORITY];
        }
        if ($input[LinearIssueMutationKeys::PARENT_ID] !== null && $input[LinearIssueMutationKeys::PARENT_ID] !== '') {
            $payload[LinearIssueMutationKeys::PARENT_ID] = $input[LinearIssueMutationKeys::PARENT_ID];
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
     * @param array{title?: string, description?: ?string, labelIds?: list<string>, priority?: ?int, stateId?: string, assigneeId?: string} $input
     */
    public function issueUpdate(string $issueId, array $input): void
    {
        if ($input === []) {
            return;
        }

        $payload = [];
        if (isset($input[LinearIssueMutationKeys::TITLE])) {
            $payload[LinearIssueMutationKeys::TITLE] = $input[LinearIssueMutationKeys::TITLE];
        }
        if (array_key_exists(LinearIssueMutationKeys::DESCRIPTION, $input)) {
            $payload[LinearIssueMutationKeys::DESCRIPTION] = $input[LinearIssueMutationKeys::DESCRIPTION];
        }
        if (isset($input[LinearIssueMutationKeys::LABEL_IDS])) {
            $payload[LinearIssueMutationKeys::LABEL_IDS] = $input[LinearIssueMutationKeys::LABEL_IDS];
        }
        if (array_key_exists(LinearIssueMutationKeys::PRIORITY, $input)) {
            $payload[LinearIssueMutationKeys::PRIORITY] = $input[LinearIssueMutationKeys::PRIORITY];
        }
        if (isset($input[LinearIssueMutationKeys::STATE_ID])) {
            $payload[LinearIssueMutationKeys::STATE_ID] = $input[LinearIssueMutationKeys::STATE_ID];
        }
        if (isset($input[LinearIssueMutationKeys::ASSIGNEE_ID])) {
            $payload[LinearIssueMutationKeys::ASSIGNEE_ID] = $input[LinearIssueMutationKeys::ASSIGNEE_ID];
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
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchIssueNodes(array $filter): array
    {
        $data = $this->graphqlClient->query(self::ISSUES_LIST_QUERY, ['filter' => $filter]);
        $nodes = $data['issues']['nodes'] ?? null;
        if (! is_array($nodes)) {
            return [];
        }

        $issues = [];
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $issues[] = $node;
            }
        }

        return $issues;
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

    protected function logVerboseFallback(string $message): void
    {
        $this->logger?->writeln(Logger::VERBOSITY_VERBOSE, $message);
    }

    /**
     * @return array{uploadUrl: string, assetUrl: string, headers: list<array{key: string, value: string}>}
     */
    public function fileUpload(string $filename, string $contentType, int $size): array
    {
        $data = $this->graphqlClient->query(self::FILE_UPLOAD_MUTATION, [
            LinearAttachmentMutationKeys::FILENAME => $filename,
            LinearAttachmentMutationKeys::CONTENT_TYPE => $contentType,
            LinearAttachmentMutationKeys::SIZE => $size,
        ]);
        $result = $data['fileUpload'] ?? null;
        if (! is_array($result) || ! ($result['success'] ?? false)) {
            throw new ApiException(
                'Linear file upload request failed.',
                json_encode($result, JSON_UNESCAPED_UNICODE) ?: 'fileUpload returned success=false',
            );
        }

        $uploadFile = $result['uploadFile'] ?? null;
        if (! is_array($uploadFile)) {
            throw new ApiException(
                'Linear file upload request failed.',
                'fileUpload response missing uploadFile.',
            );
        }

        $uploadUrl = $uploadFile['uploadUrl'] ?? null;
        $assetUrl = $uploadFile['assetUrl'] ?? null;
        if (! is_string($uploadUrl) || $uploadUrl === '' || ! is_string($assetUrl) || $assetUrl === '') {
            throw new ApiException(
                'Linear file upload request failed.',
                'fileUpload response missing uploadUrl or assetUrl.',
            );
        }

        return [
            'uploadUrl' => $uploadUrl,
            'assetUrl' => $assetUrl,
            'headers' => $this->parseFileUploadHeaders($uploadFile['headers'] ?? null),
        ];
    }

    public function attachmentCreate(string $issueId, string $title, string $url): void
    {
        $data = $this->graphqlClient->query(self::ATTACHMENT_CREATE_MUTATION, [
            'input' => [
                LinearAttachmentMutationKeys::ISSUE_ID => $issueId,
                LinearIssueMutationKeys::TITLE => $title,
                LinearAttachmentMutationKeys::URL => $url,
            ],
        ]);
        $result = $data['attachmentCreate'] ?? null;
        if (! is_array($result) || ! ($result['success'] ?? false)) {
            throw new ApiException(
                'Could not attach file to Linear issue.',
                json_encode($result, JSON_UNESCAPED_UNICODE) ?: 'attachmentCreate returned success=false',
            );
        }
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function parseFileUploadHeaders(mixed $rawHeaders): array
    {
        if (! is_array($rawHeaders)) {
            return [];
        }

        $headers = [];
        foreach ($rawHeaders as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['key'] ?? null;
            $value = $row['value'] ?? null;
            if (! is_string($key) || $key === '' || ! is_string($value)) {
                continue;
            }

            $headers[] = ['key' => $key, 'value' => $value];
        }

        return $headers;
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
