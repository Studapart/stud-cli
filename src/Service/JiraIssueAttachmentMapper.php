<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\IssueAttachment;

/**
 * Normalizes Jira REST `fields.attachment` rows into IssueAttachment DTOs.
 */
class JiraIssueAttachmentMapper
{
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

        return new IssueAttachment(
            id: $id,
            filename: $filename,
            size: $this->normalizeSize($row['size'] ?? 0),
            contentUrl: $content,
            mimeType: $this->normalizeMimeType($row['mimeType'] ?? null),
        );
    }

    protected function normalizeSize(mixed $sizeRaw): int
    {
        if (is_int($sizeRaw)) {
            return $sizeRaw;
        }

        return is_numeric($sizeRaw) ? (int) $sizeRaw : 0;
    }

    protected function normalizeMimeType(mixed $mimeType): ?string
    {
        return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
    }
}
