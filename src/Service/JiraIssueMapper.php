<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Filter;
use App\DTO\Project;
use App\DTO\WorkItem;

class JiraIssueMapper
{
    private readonly JiraIssueAttachmentMapper $attachmentMapper;

    public function __construct(
        private readonly CanConvertToPlainTextInterface $htmlConverter,
    ) {
        $this->attachmentMapper = new JiraIssueAttachmentMapper();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function mapToProject(array $data): Project
    {
        return new Project(
            key: $data['key'],
            name: $data['name'],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function mapToFilter(array $data): Filter
    {
        return new Filter(
            name: $data['name'],
            description: $data['description'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function mapToWorkItem(array $data): WorkItem
    {
        $fields = $data['fields'];

        $description = 'No description provided.';
        if (isset($data['renderedFields']['description'])) {
            $description = $this->htmlConverter->toPlainText($data['renderedFields']['description']);
        } elseif (! empty($fields['description'])) {
            $description = 'ADF content not rendered: ' . json_encode($fields['description']);
        }

        $components = empty($fields['components'])
            ? []
            : array_map(fn ($component) => $component['name'], $fields['components']);

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
            priority: $fields['priority']['name'] ?? null,
            renderedDescription: $data['renderedFields']['description'] ?? null,
            attachments: $this->attachmentMapper->mapIssueAttachments($fields),
        );
    }
}
