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
    private ?string $currentUserAccountId = null;

    public function __construct(
        private HttpClientInterface $client,
        private readonly CanConvertToPlainTextInterface $htmlConverter
    ) {
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        $url = "/rest/api/3/issue/{$key}";
        if ($renderFields) {
            $url .= '?expand=renderedFields';
        }
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not find Jira issue with key \"{$key}\".",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        return $this->mapToWorkItem($response->toArray());
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
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                'Failed to search for issues.',
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $issuesData = $data['issues'] ?? [];

        return array_map(fn ($issue) => $this->mapToWorkItem($issue), $issuesData);
    }

    /**
     * @return Project[]
     */
    public function getProjects(): array
    {
        $response = $this->client->request('GET', '/rest/api/3/project/search');

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                'Failed to fetch projects.',
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        $paginatedResponse = $response->toArray(false);
        $projectsData = $paginatedResponse['values'] ?? [];

        return array_map(fn ($project) => $this->mapToProject($project), $projectsData);
    }

    /**
     * Returns a single project by key or id. Use to validate that a project exists.
     *
     * @throws ApiException If the project is not found (e.g. 404) or the API request fails
     */
    public function getProject(string $projectIdOrKey): Project
    {
        $response = $this->client->request('GET', '/rest/api/3/project/' . $projectIdOrKey);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Project \"{$projectIdOrKey}\" not found.",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        return $this->mapToProject($response->toArray());
    }

    /**
     * @return Filter[]
     */
    public function getFilters(): array
    {
        $response = $this->client->request('GET', '/rest/api/3/filter/search');

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                'Failed to fetch filters.',
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        $paginatedResponse = $response->toArray(false);
        $filtersData = $paginatedResponse['values'] ?? [];

        return array_map(fn ($filter) => $this->mapToFilter($filter), $filtersData);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapToProject(array $data): Project
    {
        return new Project(
            key: $data['key'],
            name: $data['name'],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapToFilter(array $data): Filter
    {
        return new Filter(
            name: $data['name'],
            description: $data['description'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function mapToWorkItem(array $data): WorkItem
    {
        $fields = $data['fields'];

        $description = 'No description provided.';
        if (isset($data['renderedFields']['description'])) {
            $description = $this->htmlConverter->toPlainText($data['renderedFields']['description']);
        } elseif (! empty($fields['description'])) {
            // Fallback to raw ADF if renderedFields is not available, but we won't parse it.
            // For now, we'll just use a placeholder or the raw content if it's not ADF.
            // Given the new strategy, this path should ideally not be taken if renderFields=true was used.
            $description = 'ADF content not rendered: ' . json_encode($fields['description']);
        }

        $renderedDescription = null;
        if (isset($data['renderedFields']['description'])) {
            $renderedDescription = $data['renderedFields']['description'];
        }

        $components = [];
        if (! empty($fields['components'])) {
            $components = array_map(fn ($component) => $component['name'], $fields['components']);
        }

        $priority = null;
        if (isset($fields['priority']) && isset($fields['priority']['name'])) {
            $priority = $fields['priority']['name'];
        }

        return new WorkItem(
            id: $data['id'],
            key: $data['key'],
            title: $fields['summary'],
            status: $fields['status']['name'],
            assignee: ($fields['assignee'] ?? [])['displayName'] ?? 'Unassigned',
            description: $description,
            labels: $fields['labels'] ?? [],
            issueType: $fields['issuetype']['name'],
            components: $components,
            priority: $priority,
            renderedDescription: $renderedDescription,
        );
    }

    /**
     * Gets all available transitions for an issue.
     *
     * @return array<int, array{id: int, name: string, to: array{name: string, statusCategory: array{key: string, name: string}}}>
     */
    public function getTransitions(string $key): array
    {
        $url = "/rest/api/3/issue/{$key}/transitions";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not fetch transitions for issue \"{$key}\".",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();

        return $data['transitions'] ?? [];
    }

    /**
     * Executes a transition on an issue.
     */
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
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not execute transition {$transitionId} for issue \"{$key}\".",
                $technicalDetails,
                $response->getStatusCode()
            );
        }
    }

    /**
     * Gets the current authenticated user's account ID.
     * The result is cached to avoid repeated API calls.
     *
     * @return string The account ID of the current user
     * @throws \RuntimeException If the API call fails
     */
    public function getCurrentUserAccountId(): string
    {
        if ($this->currentUserAccountId !== null) {
            return $this->currentUserAccountId;
        }

        $response = $this->client->request('GET', '/rest/api/3/myself');

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                'Could not retrieve current user information.',
                $technicalDetails,
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
     * Find a user by email (Jira user search). Returns accountId and displayName for use in Confluence @mentions.
     *
     * @return array{accountId: string, displayName: string}|null null if not found
     */
    public function findUserByEmail(string $email): ?array
    {
        $query = trim($email);
        if ($query === '') {
            return null;
        }
        $response = $this->client->request('GET', '/rest/api/3/user/search', [
            'query' => ['query' => $query, 'maxResults' => 10],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $users = $response->toArray();
        $exact = $this->findExactEmailMatchInUsers($users, $query);
        if ($exact !== null) {
            return $exact;
        }
        if (str_contains($query, '@')) {
            $candidates = $this->collectUserCandidatesWithAt($users);
            if (count($candidates) === 1) {
                return $candidates[0];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return array{accountId: string, displayName: string}|null
     */
    protected function findExactEmailMatchInUsers(array $users, string $query): ?array
    {
        foreach ($users as $user) {
            $accountId = $user['accountId'] ?? null;
            if (! is_string($accountId) || $accountId === '') {
                continue;
            }
            $matchEmail = $user['emailAddress'] ?? null;
            if (! is_string($matchEmail) || strcasecmp(trim($matchEmail), $query) !== 0) {
                continue;
            }
            $displayName = $user['displayName'] ?? '';
            $safeDisplayName = $matchEmail;
            if (is_string($displayName)) {
                $safeDisplayName = $displayName;
            }

            return [
                'accountId' => $accountId,
                'displayName' => $safeDisplayName,
            ];
        }

        return null;
    }

    /**
     * Collect user candidates when query contains @ (e.g. for single-result fallback).
     *
     * @param array<int, array<string, mixed>> $users
     * @return array<int, array{accountId: string, displayName: string}>
     */
    protected function collectUserCandidatesWithAt(array $users): array
    {
        $candidates = [];
        foreach ($users as $user) {
            $accountId = $user['accountId'] ?? null;
            if (! is_string($accountId) || $accountId === '') {
                continue;
            }
            $displayName = $user['displayName'] ?? '';
            $safeDisplayName = '';
            if (is_string($displayName)) {
                $safeDisplayName = $displayName;
            }

            $candidates[] = [
                'accountId' => $accountId,
                'displayName' => $safeDisplayName,
            ];
        }

        return $candidates;
    }

    /**
     * Extracts technical details from an HTTP response for error reporting.
     * Truncates response body to 500 characters to avoid overwhelming output.
     *
     * @param \Symfony\Contracts\HttpClient\ResponseInterface $response
     * @return string Technical details including status code and response body
     */
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
     * Returns create metadata issue types for a project.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getCreateMetaIssueTypes(string $projectIdOrKey): array
    {
        $url = "/rest/api/3/issue/createmeta/{$projectIdOrKey}/issuetypes";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not fetch create metadata for project \"{$projectIdOrKey}\".",
                $technicalDetails,
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
     * Returns create field metadata for a project and issue type.
     * Keys of the returned array are field IDs; each value has 'required' and 'name'.
     *
     * @return array<string, array{required: bool, name: string}>
     */
    public function getCreateMetaFields(string $projectIdOrKey, string $issueTypeId): array
    {
        $url = "/rest/api/3/issue/createmeta/{$projectIdOrKey}/issuetypes/{$issueTypeId}";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not fetch create field metadata for project \"{$projectIdOrKey}\" and issue type \"{$issueTypeId}\".",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $fields = $data['fields'] ?? [];

        $result = [];
        foreach ($fields as $fieldId => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $result[$fieldId] = [
                'required' => (bool) ($meta['required'] ?? false),
                'name' => (string) ($meta['name'] ?? $fieldId),
            ];
        }

        return $result;
    }

    /**
     * Creates a Jira issue. Description must be ADF when provided.
     *
     * @param array<string, mixed> $fields
     * @return array{key: string, self: string}
     */
    public function createIssue(array $fields): array
    {
        $payload = ['fields' => $fields];
        $response = $this->client->request('POST', '/rest/api/3/issue', [
            'json' => $payload,
        ]);

        if ($response->getStatusCode() !== 201) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                'Could not create issue.',
                $technicalDetails,
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
     * Builds minimal ADF from plain text for issue description.
     *
     * @return array{type: string, version: int, content: array<int, mixed>}
     */
    public function plainTextToDescriptionAdf(string $plainText): array
    {
        return \App\Service\JiraAdfHelper::plainTextToAdf($plainText);
    }

    /**
     * Builds ADF for issue description from plain text or Markdown.
     *
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
     * Returns edit field metadata for an issue (editmeta).
     * Same normalized format as getCreateMetaFields(): fieldId => ['required' => bool, 'name' => string].
     *
     * @return array<string, array{required: bool, name: string}>
     */
    public function getEditMetaFields(string $issueIdOrKey): array
    {
        $url = "/rest/api/3/issue/{$issueIdOrKey}/editmeta";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not fetch edit metadata for issue \"{$issueIdOrKey}\".",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $fields = $data['fields'] ?? [];

        $result = [];
        foreach ($fields as $fieldId => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $result[$fieldId] = [
                'required' => (bool) ($meta['required'] ?? false),
                'name' => (string) ($meta['name'] ?? $fieldId),
            ];
        }

        return $result;
    }

    /**
     * Updates a Jira issue's fields.
     *
     * @param array<string, mixed> $fields
     * @throws ApiException
     */
    public function updateIssue(string $issueIdOrKey, array $fields): void
    {
        $url = "/rest/api/3/issue/{$issueIdOrKey}";
        $response = $this->client->request('PUT', $url, [
            'json' => ['fields' => $fields],
        ]);

        if ($response->getStatusCode() !== 204) {
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not update issue \"{$issueIdOrKey}\".",
                $technicalDetails,
                $response->getStatusCode()
            );
        }
    }

    /**
     * Assigns an issue to a user.
     *
     * @param string $key The issue key
     * @param string $accountId The Jira account ID of the user (use 'currentUser()' for current user)
     */
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
            $technicalDetails = $this->extractTechnicalDetails($response);

            throw new ApiException(
                "Could not assign issue \"{$key}\" to user.",
                $technicalDetails,
                $response->getStatusCode()
            );
        }
    }
}
