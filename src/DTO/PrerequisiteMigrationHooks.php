<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Caller-supplied hooks for running prerequisite global migrations before an update.
 */
final class PrerequisiteMigrationHooks
{
    /**
     * @param \Closure(): string $getConfigPath
     * @param \Closure(): bool $isTestEnvironment
     * @param \Closure(string, string): MessageRef $buildErrorMessage
     */
    public function __construct(
        public readonly \Closure $getConfigPath,
        public readonly \Closure $isTestEnvironment,
        public readonly \Closure $buildErrorMessage,
    ) {
    }
}
