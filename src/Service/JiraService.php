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
    protected function getCurrentUserAccountId(): string
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
