<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Unified DTO for PR/MR comments (issue-level and review/inline).
 * For review comments, path and line are set when the API provides them.
 */
final class PullRequestComment
{
    public function __construct(
        public readonly string $author,
        public readonly \DateTimeInterface $date,
        public readonly string $body,
        public readonly ?string $path = null,
        public readonly ?int $line = null,
    ) {
    }
}
