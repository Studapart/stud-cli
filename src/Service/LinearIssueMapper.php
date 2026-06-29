<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\IssueAttachment;
use App\DTO\WorkItem;

/**
 * Maps Linear GraphQL issue nodes to the shared WorkItem DTO.
 */
class LinearIssueMapper
{
    private const PRIORITY_LABELS = [
        0 => 'No priority',
        1 => 'Urgent',
        2 => 'High',
        3 => 'Medium',
        4 => 'Low',
    ];

    /** @var array<string, int> */
    private const PRIORITY_NAME_TO_VALUE = [
        'no priority' => 0,
        'urgent' => 1,
        'high' => 2,
        'medium' => 3,
        'low' => 4,
    ];

    public static function priorityNameToValue(string $name): ?int
    {
        $normalized = strtolower(trim($name));

        return self::PRIORITY_NAME_TO_VALUE[$normalized] ?? null;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function mapToWorkItem(array $node, ?string $configuredTypeGroupId = null): WorkItem
    {
        $description = $this->resolveDescription($node);
        $labelNodes = $this->extractLabelNodes($node);

        return new WorkItem(
            id: (string) ($node['id'] ?? ''),
            key: (string) ($node['identifier'] ?? ''),
            title: (string) ($node['title'] ?? ''),
            status: (string) ($node['state']['name'] ?? ''),
            assignee: $this->resolveAssignee($node),
            description: $description,
            labels: $this->mapLabelNames($labelNodes),
            issueType: $this->resolveIssueType($labelNodes, $configuredTypeGroupId),
            components: [],
            priority: $this->mapPriority($node['priority'] ?? null),
            renderedDescription: $description,
            attachments: $this->mapAttachments($node),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function resolveDescription(array $node): string
    {
        $description = $node['description'] ?? null;
        if (! is_string($description) || trim($description) === '') {
            return 'No description provided.';
        }

        return $description;
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function resolveAssignee(array $node): string
    {
        $assignee = $node['assignee'] ?? null;
        if (! is_array($assignee)) {
            return 'Unassigned';
        }

        $name = $assignee['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Unassigned';
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    protected function extractLabelNodes(array $node): array
    {
        $nodes = $node['labels']['nodes'] ?? null;
        if (! is_array($nodes)) {
            return [];
        }

        $labelNodes = [];
        foreach ($nodes as $labelNode) {
            if (is_array($labelNode)) {
                $labelNodes[] = $labelNode;
            }
        }

        return $labelNodes;
    }

    /**
     * @param list<array<string, mixed>> $labelNodes
     *
     * @return list<string>
     */
    protected function mapLabelNames(array $labelNodes): array
    {
        $labels = [];
        foreach ($labelNodes as $labelNode) {
            $name = $labelNode['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $labels[] = $name;
            }
        }

        return $labels;
    }

    /**
     * @param list<array<string, mixed>> $labelNodes
     */
    protected function resolveIssueType(array $labelNodes, ?string $configuredTypeGroupId): string
    {
        if ($configuredTypeGroupId === null || $configuredTypeGroupId === '') {
            return '';
        }

        foreach ($labelNodes as $labelNode) {
            $parent = $labelNode['parent'] ?? null;
            if (! is_array($parent)) {
                continue;
            }

            $parentId = $parent['id'] ?? null;
            if ((string) $parentId !== $configuredTypeGroupId) {
                continue;
            }

            $name = $labelNode['name'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return '';
    }

    protected function mapPriority(mixed $priority): ?string
    {
        if ($priority === null || $priority === '') {
            return null;
        }

        $value = is_int($priority) ? $priority : (is_numeric($priority) ? (int) $priority : null);
        if ($value === null) {
            return null;
        }

        if ($value === 0) {
            return null;
        }

        return self::PRIORITY_LABELS[$value] ?? null;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array{identifier: string, url: string}
     */
    public function mapCreateResponse(array $node): array
    {
        return [
            'identifier' => (string) ($node['identifier'] ?? ''),
            'url' => (string) ($node['url'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<IssueAttachment>
     */
    protected function mapAttachments(array $node): array
    {
        $nodes = $node['attachments']['nodes'] ?? null;
        if (! is_array($nodes)) {
            return [];
        }

        $attachments = [];
        foreach ($nodes as $attachmentNode) {
            if (! is_array($attachmentNode)) {
                continue;
            }

            $mapped = $this->mapAttachmentNode($attachmentNode);
            if ($mapped !== null) {
                $attachments[] = $mapped;
            }
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function mapAttachmentNode(array $node): ?IssueAttachment
    {
        $id = isset($node['id']) ? (string) $node['id'] : '';
        $filename = $this->resolveAttachmentFilename($node);
        $contentUrl = $node['url'] ?? null;
        if ($id === '' || $filename === '' || ! is_string($contentUrl) || $contentUrl === '') {
            return null;
        }

        $sizeRaw = $node['size'] ?? 0;
        $size = is_int($sizeRaw) ? $sizeRaw : (is_numeric($sizeRaw) ? (int) $sizeRaw : 0);

        $mimeType = null;
        $contentType = $node['contentType'] ?? $node['mimeType'] ?? null;
        if (is_string($contentType) && $contentType !== '') {
            $mimeType = $contentType;
        }

        return new IssueAttachment(
            id: $id,
            filename: $filename,
            size: $size,
            contentUrl: $contentUrl,
            mimeType: $mimeType,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function resolveAttachmentFilename(array $node): string
    {
        foreach (['title', 'filename', 'name'] as $field) {
            $value = $node[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
