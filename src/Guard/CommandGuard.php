<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\Capability\GitRepositoryAware;

/**
 * Matches handler capabilities against a CommandContext snapshot.
 */
class CommandGuard
{
    public function __construct(
        private readonly MissingConfigKeyCollector $missingConfigKeys = new MissingConfigKeyCollector(),
    ) {
    }

    public function check(CapabilitySet $capabilities, CommandContext $context): CommandGuardResult
    {
        if ($capabilities->isEmpty()) {
            return new CommandGuardResult(canProceed: true);
        }

        if ($context->providerOverrideError !== null) {
            return new CommandGuardResult(
                canProceed: false,
                providerOverrideError: $context->providerOverrideError,
            );
        }

        if ($context->providerResolutionBlock !== null) {
            return new CommandGuardResult(
                canProceed: false,
                providerResolutionBlock: $context->providerResolutionBlock,
            );
        }

        $environmentFailures = $capabilities->has(GitRepositoryAware::class) && ! $context->hasGitRepository
            ? ['git_repository']
            : [];
        $missingGlobal = $this->missingConfigKeys->missingGlobalKeys($capabilities, $context);
        $missingProject = $this->missingConfigKeys->missingProjectKeys($capabilities, $context);
        $canProceed = $environmentFailures === [] && $missingGlobal === [] && $missingProject === [];

        return new CommandGuardResult(
            $missingGlobal,
            $missingProject,
            $canProceed,
            $environmentFailures,
            ambiguousIssueTrackerProvider: $context->issueTrackerProviderAmbiguous && ! $canProceed,
        );
    }
}
