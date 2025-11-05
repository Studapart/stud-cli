<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Project;
use App\DTO\WorkItem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraService
{
    public function __construct(
        private HttpClientInterface $client,
    ) {}

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
        if (!empty($fields['description'])) {
            $description = $this->_render_doc_node($fields['description']);
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
     * Recursively renders an Atlassian Document Format node to a string.
     */
    private function _render_doc_node(array $node, int $listLevel = 0): string
    {
        $text = '';
        $type = $node['type'] ?? 'text';

        // Handle block-level nodes
        switch ($type) {
            case 'doc':
                break; // Just process content
            case 'paragraph':
                $text .= "\n";
                break;
            case 'heading':
                $level = $node['attrs']['level'] ?? 1;
                $text .= "\n" . str_repeat('#', $level) . ' ';
                break;
            case 'text':
                $text .= $node['text'];
                break;
            case 'bulletList':
            case 'orderedList':
                $text .= "\n";
                break;
            case 'listItem':
                $indent = str_repeat('  ', $listLevel);
                $prefix = ($node['parentType'] ?? 'bulletList') === 'bulletList' ? '* ' : '1. ';
                $text .= "{$indent}{$prefix}";
                break;
            case 'codeBlock':
                $text .= "\n```\n";
                break;
            default:
                // For unknown types, just try to render their content
                break;
        }

        // Recursively render content if it exists
        if (isset($node['content'])) {
            foreach ($node['content'] as $i => $childNode) {
                // Pass parent type to children for context (e.g., for list items)
                $childNode['parentType'] = $type;
                if ($type === 'orderedList') {
                    $childNode['orderedListIndex'] = $i + 1;
                }
                $text .= $this->_render_doc_node($childNode, ($type === 'bulletList' || $type === 'orderedList') ? $listLevel + 1 : $listLevel);
            }
        }

        // Add closing tags for block-level nodes
        if ($type === 'codeBlock') {
            $text .= "\n```\n";
        }

        return $text;
    }
}
