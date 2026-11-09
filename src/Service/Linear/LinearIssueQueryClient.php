<?php

declare(strict_types=1);

namespace App\Service\Linear;

use App\Exception\ApiException;
use App\Service\LinearGraphqlClient;

/**
 * Linear issue reads: id resolution, single-issue fetch, filtered lists, and search.
 */
class LinearIssueQueryClient
{
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

    public function __construct(
        private readonly LinearGraphqlClient $graphqlClient,
    ) {
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
        return $this->listIssuesByFilter(
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
        $data = $this->graphqlClient->query(self::ISSUES_LIST_QUERY, ['filter' => $filter]);

        return $this->filterIssueNodes($data['issues']['nodes'] ?? null);
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

        return $this->filterIssueNodes($data['searchIssues']['nodes'] ?? null);
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
     * @return list<array<string, mixed>>
     */
    protected function filterIssueNodes(mixed $nodes): array
    {
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
}
