<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\PullRequestComment;

final class PrCommentsResponse extends AbstractResponse
{
    /**
     * @param PullRequestComment[] $issueComments
     * @param PullRequestComment[] $reviewComments
     * @param PullRequestComment[] $reviews
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $issueComments,
        public readonly array $reviewComments,
        public readonly array $reviews,
        public readonly int $pullNumber
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param PullRequestComment[] $issueComments
     * @param PullRequestComment[] $reviewComments
     * @param PullRequestComment[] $reviews
     */
    public static function success(array $issueComments, array $reviewComments, array $reviews, int $pullNumber): self
    {
        return new self(true, null, $issueComments, $reviewComments, $reviews, $pullNumber);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, [], [], [], 0);
    }
}
