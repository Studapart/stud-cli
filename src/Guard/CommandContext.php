<?php

declare(strict_types=1);

namespace App\Guard;

use App\DTO\MessageRef;
use App\Guard\DTO\CommandInvocationContext;
use App\Guard\DTO\ProviderResolutionResult;

/**
 * Immutable snapshot of runtime context for command readiness checks.
 */
class CommandContext
{
    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @param list<string>              $issueTrackerProviders Effective issue-tracker providers for this command
     * @param list<string>              $gitProviders Effective git providers for this command
     */
    public function __construct(
        public readonly array $globalConfig,
        public readonly ?array $projectConfig,
        public readonly bool $hasGitRepository,
        public readonly array $issueTrackerProviders,
        public readonly array $gitProviders,
        public readonly bool $isInteractive,
        public readonly bool $isQuiet,
        public readonly bool $isAgent,
        public readonly bool $issueTrackerProviderAmbiguous = false,
        public readonly ?string $issueTrackerProviderOverride = null,
        public readonly ?MessageRef $providerOverrideError = null,
        public readonly ?MessageRef $providerResolutionBlock = null,
        public readonly ?CommandInvocationContext $invocationContext = null,
        public readonly ?ProviderResolutionResult $providerResolution = null,
    ) {
    }
}
