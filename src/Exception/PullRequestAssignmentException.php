<?php

declare(strict_types=1);

namespace App\Exception;

class PullRequestAssignmentException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $pullRequestUrl,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function getPullRequestUrl(): string
    {
        return $this->pullRequestUrl;
    }
}
