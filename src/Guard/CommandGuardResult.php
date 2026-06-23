<?php

declare(strict_types=1);

namespace App\Guard;

/**
 * Result of a command readiness guard check.
 */
class CommandGuardResult
{
    /**
     * @param array<string> $missingGlobalKeys
     * @param array<string> $missingProjectKeys
     * @param array<string> $environmentFailures Non-config blockers (e.g. git_repository)
     */
    public function __construct(
        public readonly array $missingGlobalKeys = [],
        public readonly array $missingProjectKeys = [],
        public readonly bool $canProceed = true,
        public readonly array $environmentFailures = [],
    ) {
    }

    public function hasMissingKeys(): bool
    {
        return ! empty($this->missingGlobalKeys) || ! empty($this->missingProjectKeys);
    }

    public function hasBlockingIssues(): bool
    {
        return ! $this->canProceed;
    }
}
