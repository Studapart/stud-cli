<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Jira issue attachment metadata (REST API v3 attachment object subset).
 */
final class IssueAttachment
{
    public function __construct(
        public readonly string $id,
        public readonly string $filename,
        public readonly int $size,
        public readonly string $contentUrl,
        public readonly ?string $mimeType = null,
    ) {
    }
}
