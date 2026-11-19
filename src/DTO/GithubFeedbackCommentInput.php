<?php

declare(strict_types=1);

namespace App\DTO;

final class GithubFeedbackCommentInput
{
    /**
     * @param array<string, mixed>|null $review
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $threadId,
        public readonly ?PullRequestFeedbackLocation $fallbackLocation,
        public readonly ?array $review = null,
    ) {
    }
}
