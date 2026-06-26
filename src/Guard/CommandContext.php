<?php

declare(strict_types=1);

namespace App\Guard;

/**
 * Immutable snapshot of runtime context for command readiness checks.
 */
class CommandContext
{
    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @param list<string> $workItemProviders Effective work-item providers for this command
     * @param list<string> $gitProviders Effective git providers for this command
     */
    public function __construct(
        public readonly array $globalConfig,
        public readonly ?array $projectConfig,
        public readonly bool $hasGitRepository,
        public readonly array $workItemProviders,
        public readonly array $gitProviders,
        public readonly bool $isInteractive,
        public readonly bool $isQuiet,
        public readonly bool $isAgent,
        public readonly bool $workItemProviderAmbiguous = false,
    ) {
    }
}
