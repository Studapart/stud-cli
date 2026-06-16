<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Filter;
use App\DTO\Project;
use App\DTO\WorkItem;
use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraService
{
    /**
     * Explicit field ids when requesting rendered HTML plus attachment metadata for {@see getIssue()} (renderFields=true).
     *
     * @internal
     */
    private const ISSUE_DETAIL_WITH_RENDERED_FIELDS =
        'summary,status,assignee,description,labels,issuetype,components,priority,attachment';

    private ?string $currentUserAccountId = null;

    public function __construct(
        private HttpClientInterface $client,
        private readonly JiraIssueMapper $issueMapper,
        private readonly JiraFieldMetadataService $fieldMetadataService,
        private readonly JiraUserSearchService $userSearchService,
    ) {
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        $url = "/rest/api/3/issue/{$key}";
        if ($renderFields) {
            $url .= '?expand=renderedFields&fields=' . self::ISSUE_DETAIL_WITH_RENDERED_FIELDS;
        }
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not find Jira issue with key \"{$key}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        return $this->issueMapper->mapToWorkItem($response->toArray());
    }

    /**
     * @return WorkItem[]
     */
    public function searchIssues(string $jql): array
    {
        $response = $this->client->request('POST', '/rest/api/3/search/jql', [
            'json' => [
                'jql' => $jql,
                'fields' => ['key', 'summary', 'status', 'description', 'assignee', 'labels', 'issuetype', 'components', 'priority'],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                'Failed to search for issues.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $issuesData = $data['issues'] ?? [];

        return array_map(fn ($issue) => $this->issueMapper->mapToWorkItem($issue), $issuesData);
    }

    /**
     * @return Project[]
     */
    public function getProjects(): array
    {
        $response = $this->client->request('GET', '/rest/api/3/project/search');

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                'Failed to fetch projects.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $paginatedResponse = $response->toArray(false);
        $projectsData = $paginatedResponse['values'] ?? [];

        return array_map(fn ($project) => $this->issueMapper->mapToProject($project), $projectsData);
    }

    public function getProject(string $projectIdOrKey): Project
    {
        $response = $this->client->request('GET', '/rest/api/3/project/' . $projectIdOrKey);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Project \"{$projectIdOrKey}\" not found.",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        return $this->issueMapper->mapToProject($response->toArray());
    }

    /**
     * @return Filter[]
     */
    public function getFilters(): array
    {
        $response = $this->client->request('GET', '/rest/api/3/filter/search');

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                'Failed to fetch filters.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $paginatedResponse = $response->toArray(false);
        $filtersData = $paginatedResponse['values'] ?? [];

        return array_map(fn ($filter) => $this->issueMapper->mapToFilter($filter), $filtersData);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function mapToWorkItem(array $data): WorkItem
    {
        return $this->issueMapper->mapToWorkItem($data);
    }

    /**
     * @return array<int, array{id: int, name: string, to: array{name: string, statusCategory: array{key: string, name: string}}}>
     */
    public function getTransitions(string $key): array
    {
        $url = "/rest/api/3/issue/{$key}/transitions";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch transitions for issue \"{$key}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();

        return $data['transitions'] ?? [];
    }

    public function transitionIssue(string $key, int $transitionId): void
    {
        $url = "/rest/api/3/issue/{$key}/transitions";
        $response = $this->client->request('POST', $url, [
            'json' => [
                'transition' => [
                    'id' => $transitionId,
                ],
            ],
        ]);

        if ($response->getStatusCode() !== 204) {
            throw new ApiException(
                "Could not execute transition {$transitionId} for issue \"{$key}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }
    }

    public function getCurrentUserAccountId(): string
    {
        if ($this->currentUserAccountId !== null) {
            return $this->currentUserAccountId;
        }

        $response = $this->client->request('GET', '/rest/api/3/myself');

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                'Could not retrieve current user information.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $accountId = $data['accountId'] ?? null;

        if ($accountId === null) {
            throw new \RuntimeException('Could not find accountId in current user information.');
        }

        $this->currentUserAccountId = $accountId;

        return $this->currentUserAccountId;
    }

    /**
     * @return array{accountId: string, displayName: string}|null
     */
    public function findUserByEmail(string $email): ?array
    {
        return $this->userSearchService->findUserByEmail($email);
    }

    /**
     * @param array<int, array<string, mixed>> $users
     *
     * @return array{accountId: string, displayName: string}|null
     */
    protected function findExactEmailMatchInUsers(array $users, string $query): ?array
    {
        return $this->userSearchService->findExactEmailMatchInUsers($users, $query);
    }

    /**
     * @param array<int, array<string, mixed>> $users
     *
     * @return array<int, array{accountId: string, displayName: string}>
     */
    protected function collectUserCandidatesWithAt(array $users): array
    {
        return $this->userSearchService->collectUserCandidatesWithAt($users);
    }

    protected function extractTechnicalDetails(\Symfony\Contracts\HttpClient\ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $responseBody = 'No response body';

        try {
            $content = $response->getContent(false);
            if (! empty($content)) {
                $responseBody = mb_strlen($content) > 500
                    ? mb_substr($content, 0, 500) . '... (truncated)'
                    : $content;
            }
        } catch (\Exception $e) {
            $responseBody = 'Unable to read response body: ' . $e->getMessage();
        }

        return sprintf('HTTP %d: %s', $statusCode, $responseBody);
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getCreateMetaIssueTypes(string $projectIdOrKey): array
    {
        $url = "/rest/api/3/issue/createmeta/{$projectIdOrKey}/issuetypes";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch create metadata for project \"{$projectIdOrKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $values = $data['values'] ?? $data['issueTypes'] ?? [];

        return array_map(static fn (array $item): array => [
            'id' => (string) ($item['id'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
        ], $values);
    }

    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function getCreateMetaFields(string $projectIdOrKey, string $issueTypeId): array
    {
        return $this->fieldMetadataService->getCreateMetaFields($projectIdOrKey, $issueTypeId);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{key: string, self: string}
     */
    public function createIssue(array $fields): array
    {
        $payload = ['fields' => $fields];
        $response = $this->client->request('POST', '/rest/api/3/issue', [
            'json' => $payload,
        ]);

        if ($response->getStatusCode() !== 201) {
            throw new ApiException(
                'Could not create issue.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();

        return [
            'key' => (string) ($data['key'] ?? ''),
            'self' => (string) ($data['self'] ?? ''),
        ];
    }

    /**
     * @return array{type: string, version: int, content: array<int, mixed>}
     */
    public function plainTextToDescriptionAdf(string $plainText): array
    {
        return \App\Service\JiraAdfHelper::plainTextToAdf($plainText);
    }

    /**
     * @return array{type: string, version: int, content: array<int, mixed>}
     */
    public function descriptionToAdf(string $text, string $format = 'plain'): array
    {
        if ($format === 'markdown') {
            return (new \App\Service\MarkdownToAdfConverter())->convert($text);
        }

        return \App\Service\JiraAdfHelper::plainTextToAdf($text);
    }

    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function getEditMetaFields(string $issueIdOrKey): array
    {
        return $this->fieldMetadataService->getEditMetaFields($issueIdOrKey);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateIssue(string $issueIdOrKey, array $fields): void
    {
        $url = "/rest/api/3/issue/{$issueIdOrKey}";
        $response = $this->client->request('PUT', $url, [
            'json' => ['fields' => $fields],
        ]);

        if ($response->getStatusCode() !== 204) {
            throw new ApiException(
                "Could not update issue \"{$issueIdOrKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }
    }

    public function assignIssue(string $key, string $accountId = 'currentUser()'): void
    {
        $url = "/rest/api/3/issue/{$key}/assignee";
        $actualAccountId = $accountId === 'currentUser()'
            ? $this->getCurrentUserAccountId()
            : $accountId;

        $response = $this->client->request('PUT', $url, [
            'json' => ['accountId' => $actualAccountId],
        ]);

        if ($response->getStatusCode() !== 204) {
            throw new ApiException(
                "Could not assign issue \"{$key}\" to user.",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }
    }
}
