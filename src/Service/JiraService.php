<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Project;
use App\DTO\WorkItem;
use Stevebauman\Hypertext\Transformer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraService
{
    public function __construct(
        private HttpClientInterface $client,
        private ?Transformer $transformer = null,
    ) {
        $this->transformer = $transformer ?? new Transformer();
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        $url = "/rest/api/3/issue/{$key}";
        if ($renderFields) {
            $url .= '?expand=renderedFields';
        }
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Could not find Jira issue with key \"{$key}\".");
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
                'fields' => ['key', 'summary', 'status', 'description', 'assignee', 'labels', 'issuetype', 'components'],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to search for issues.');
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
            throw new \RuntimeException('Failed to fetch projects.');
        }

        $paginatedResponse = $response->toArray(false);
        $projectsData = $paginatedResponse['values'] ?? [];

        return array_map(fn ($project) => $this->mapToProject($project), $projectsData);
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
    protected function mapToWorkItem(array $data): WorkItem
    {
        $fields = $data['fields'];

        $description = 'No description provided.';
        if (isset($data['renderedFields']['description'])) {
            $description = $this->_convertHtmlToPlainText($data['renderedFields']['description']);
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
            throw new \RuntimeException("Could not fetch transitions for issue \"{$key}\".");
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
            throw new \RuntimeException("Could not execute transition {$transitionId} for issue \"{$key}\".");
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
        $payload = $accountId === 'currentUser()'
            ? ['accountId' => null] // null means assign to current user
            : ['accountId' => $accountId];

        $response = $this->client->request('PUT', $url, [
            'json' => $payload,
        ]);

        if ($response->getStatusCode() !== 204) {
            throw new \RuntimeException("Could not assign issue \"{$key}\" to user.");
        }
    }

    /**
     * Converts HTML content to plain text suitable for terminal display.
     * Uses Stevebauman\Hypertext library for robust conversion.
     *
     * Note: This method converts HTML to plain text and handles <hr> tags by
     * converting them to dividers. Any further formatting, sanitization, or
     * section parsing should be done by the handler/display layer.
     */
    protected function _convertHtmlToPlainText(string $html): string
    {
        // Convert <hr> tags to divider markers before transformer processes them
        // The transformer's HtmlPurifier removes <hr> tags, so we need to convert them first
        // We'll replace <hr> with a pattern that will become a divider line after transformation
        // Using a pattern that will be preserved: newline + dashes + newline
        $html = preg_replace('/<hr\s*\/?>/i', "\n---\n", $html);
        $html = preg_replace('/<hr\s+[^>]*\/?>/i', "\n---\n", $html);

        $text = $this->transformer
            ->keepLinks()
            ->keepNewLines()
            ->toText($html);

        // Don't remove leading whitespace here - let the handler do it
        // This prevents accidentally removing non-whitespace characters
        return $text;
    }
}
