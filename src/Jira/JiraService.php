<?php

declare(strict_types=1);

namespace App\Jira;

use App\DTO\Project;
use App\DTO\WorkItem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraService
{
    public function __construct(
        private HttpClientInterface $client,
    ) {}

    public function getIssue(string $key): WorkItem
    {
        $response = $this->client->request('GET', "/rest/api/3/issue/{$key}", [
            'query' => [
                'fields' => '*all',
            ],
        ]);

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

    private function mapToProject(array $data): Project
    {
        return new Project(
            key: $data['key'],
            name: $data['name'],
        );
    }

    private function mapToWorkItem(array $data): WorkItem
    {
        $fields = $data['fields'];

        $description = 'No description provided.';
        if (!empty($fields['description'])) {
            $descText = [];
            if (is_array($fields['description']['content'])) {
                foreach ($fields['description']['content'] as $content) {
                    if ($content['type'] === 'paragraph') {
                        foreach ($content['content'] as $textItem) {
                            if ($textItem['type'] === 'text') {
                                $descText[] = $textItem['text'];
                            }
                        }
                    }
                }
            }
            if ($descText) {
                $description = implode("\n", $descText);
            }
        }

        $components = array_map(fn ($component) => $component['name'], $fields['components'] ?? []);

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
        );
    }
}
