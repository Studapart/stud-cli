<?php

declare(strict_types=1);

namespace App\DTO;

final class PullRequestFeedbackReview
{
    public function __construct(
        public readonly PullRequestFeedbackIds $ids,
        public readonly string $author,
        public readonly \DateTimeInterface $date,
        public readonly string $body,
        public readonly PullRequestFeedbackState $state,
        public readonly PullRequestFeedbackActions $actions,
    ) {
    }
}
