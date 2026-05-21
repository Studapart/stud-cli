<?php

declare(strict_types=1);

namespace App\DTO;

final class PullRequestFeedbackConversation
{
    /**
     * @param PullRequestFeedbackComment[] $comments
     */
    public function __construct(
        public readonly PullRequestFeedbackIds $ids,
        public readonly string $type,
        public readonly PullRequestFeedbackState $state,
        public readonly array $comments,
        public readonly PullRequestFeedbackActions $actions,
        public readonly ?PullRequestFeedbackLocation $location = null,
        public readonly ?PullRequestFeedbackReview $review = null,
        public readonly bool $truncated = false,
    ) {
    }
}
