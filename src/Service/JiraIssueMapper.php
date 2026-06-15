<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Filter;
use App\DTO\IssueAttachment;
use App\DTO\Project;
use App\DTO\WorkItem;

class JiraIssueMapper
{
    public function __construct(
        private readonly CanConvertToPlainTextInterface $htmlConverter,
    ) {
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
            attachments: $this->mapIssueAttachments($fields),
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<IssueAttachment>
     */
    public function mapIssueAttachments(array $fields): array
    {
        $raw = $fields['attachment'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $entry = $this->normalizeAttachmentRow($row);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function normalizeAttachmentRow(array $row): ?IssueAttachment
    {
        $id = isset($row['id']) ? (string) $row['id'] : '';
        $filename = isset($row['filename']) && is_string($row['filename']) ? $row['filename'] : '';
        $content = $row['content'] ?? null;
        if ($id === '' || $filename === '' || ! is_string($content) || $content === '') {
            return null;
        }

        $sizeRaw = $row['size'] ?? 0;
        $size = is_int($sizeRaw) ? $sizeRaw : (is_numeric($sizeRaw) ? (int) $sizeRaw : 0);

        $mimeType = null;
        if (isset($row['mimeType']) && is_string($row['mimeType']) && $row['mimeType'] !== '') {
            $mimeType = $row['mimeType'];
        }

        return new IssueAttachment(
            id: $id,
            filename: $filename,
            size: $size,
            contentUrl: $content,
            mimeType: $mimeType,
        );
    }
}
