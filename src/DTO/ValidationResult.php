<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Data Transfer Object for configuration validation results.
 */
class ValidationResult
{
    /**
     * @param array<string> $missingGlobalKeys List of missing global configuration keys
     * @param array<string> $missingProjectKeys List of missing project configuration keys
     * @param bool $canProceed Whether the command can proceed (true if no mandatory keys are missing)
     */
    public function __construct(
        public readonly array $missingGlobalKeys = [],
        public readonly array $missingProjectKeys = [],
        public readonly bool $canProceed = true
    ) {
    }

    /**
     * Checks if there are any missing keys.
     */
    public function hasMissingKeys(): bool
    {
        return ! empty($this->missingGlobalKeys) || ! empty($this->missingProjectKeys);
    }
}
