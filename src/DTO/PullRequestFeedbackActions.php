<?php

declare(strict_types=1);

namespace App\DTO;

final class PullRequestFeedbackActions
{
    public function __construct(
        public readonly bool $canReply = false,
        public readonly bool $canResolve = false,
        public readonly bool $canReopen = false,
        public readonly bool $canHide = false,
        public readonly bool $canUnhide = false,
        public readonly bool $canDismissReview = false,
    ) {
    }
}
