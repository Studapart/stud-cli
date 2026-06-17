<?php

declare(strict_types=1);

namespace App\Exception;

class GitTimeoutException extends GitException
{
    public function __construct(
        private readonly string $command,
        private readonly float $timeoutSeconds,
        string $technicalDetails,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Git command timed out after %d seconds: %s', (int) $timeoutSeconds, $command),
            $technicalDetails,
            $previous,
        );
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getTimeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }
}
