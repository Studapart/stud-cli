<?php

declare(strict_types=1);

namespace App\DTO;

use App\Guard\DTO\ProviderResolutionResult;

/**
 * Skip flags, validation scope, and project context for config:validate (SCI-218).
 */
final class ConfigValidateExecutionOptions
{
    public function __construct(
        public readonly bool $skipJira,
        public readonly bool $skipGit,
        public readonly bool $skipLinear,
        public readonly bool $validateJira,
        public readonly bool $validateGit,
        public readonly bool $validateLinear,
        public readonly bool $validateGithubGlobally,
        public readonly bool $validateGitlabGlobally,
        public readonly ProviderResolutionResult $providerResolution,
        public readonly bool $inRepo,
        public readonly bool $hasProjectConfig,
        public readonly ?string $projectGitProvider,
    ) {
    }
}
