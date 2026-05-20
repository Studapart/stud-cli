<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\PullRequestComment;
use App\DTO\PullRequestFeedbackConversation;

final class PrCommentsResponse extends AbstractResponse
{
    /**
     * @param PullRequestComment[] $issueComments
     * @param PullRequestComment[] $reviewComments
     * @param PullRequestComment[] $reviews
     * @param PullRequestFeedbackConversation[] $conversations
     */
    private function __construct(
        bool $success = true,
        ?string $error = null,
        public readonly array $issueComments = [],
        public readonly array $reviewComments = [],
        public readonly array $reviews = [],
        public readonly int $pullNumber = 0,
        public readonly array $conversations = [],
        public readonly bool $threaded = false,
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param PullRequestComment[] $issueComments
     * @param PullRequestComment[] $reviewComments
     * @param PullRequestComment[] $reviews
     * @param PullRequestFeedbackConversation[] $conversations
     */
    public static function success(
        array $issueComments,
        array $reviewComments,
        array $reviews,
        int $pullNumber,
        array $conversations = [],
        bool $threaded = false,
    ): self {
        return new self(
            issueComments: $issueComments,
            reviewComments: $reviewComments,
            reviews: $reviews,
            pullNumber: $pullNumber,
            conversations: $conversations,
            threaded: $threaded,
        );
    }

    public static function error(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
