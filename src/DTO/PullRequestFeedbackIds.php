<?php

declare(strict_types=1);

namespace App\DTO;

final class PullRequestFeedbackIds
{
    public function __construct(
        public readonly string $provider,
        public readonly string $kind,
        public readonly ?string $id = null,
        public readonly ?string $nodeId = null,
        public readonly ?string $threadId = null,
        public readonly ?string $reviewId = null,
        public readonly ?string $discussionId = null,
        public readonly ?string $noteId = null,
        public readonly ?string $target = null,
    ) {
    }
}
