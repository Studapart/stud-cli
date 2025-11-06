<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Project;
use App\DTO\WorkItem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Stevebauman\Hypertext\Transformer;

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
        if (isset($data['renderedFields']['description'])) {
            $description = $this->_convertHtmlToPlainText($data['renderedFields']['description']);
        } elseif (!empty($fields['description'])) {
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
        if (!empty($fields['components'])) {
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
     * Converts HTML content to plain text suitable for terminal display.
     * Uses Stevebauman\Hypertext library for robust conversion.
     */
    private function _convertHtmlToPlainText(string $html): string
    {
        $text = $this->transformer
            ->keepLinks()
            ->keepNewLines()
            ->toText($html);

        // Replace any occurrence of 4 or more hyphens with a newline, ---, newline
        $text = preg_replace('/\-{4,}/', "\n---\n", $text);



        return $text;
    }
}
