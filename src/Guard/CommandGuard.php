<?php

declare(strict_types=1);

namespace App\Guard;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitProviderGithubAware;
use App\Guard\Capability\GitProviderGitlabAware;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\Capability\WorkItemLinearAware;
use App\Service\GlobalConfigProviderResolver;

/**
 * Matches handler capabilities against a CommandContext snapshot.
 */
class CommandGuard
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    public function check(CapabilitySet $capabilities, CommandContext $context): CommandGuardResult
    {
        if ($capabilities->isEmpty()) {
            return new CommandGuardResult(canProceed: true);
        }

        $missingGlobal = [];
        $missingProject = [];
        $environmentFailures = [];

        if ($capabilities->has(GitRepositoryAware::class) && ! $context->hasGitRepository) {
            $environmentFailures[] = 'git_repository';
        }

        if ($context->workItemProviderAmbiguous
            && ($capabilities->has(WorkItemJiraAware::class) || $capabilities->has(WorkItemLinearAware::class))) {
            $missingProject[] = ProjectStudConfigKeys::WORK_ITEM_PROVIDER;
        }

        if (! $context->workItemProviderAmbiguous
            && $capabilities->has(WorkItemJiraAware::class)
            && $this->providerResolver->collectsJira($context->workItemProviders)) {
            $missingGlobal = array_merge($missingGlobal, $this->findMissingKeys(GlobalStudConfigKeys::requiredJiraCredentialKeys(), $context->globalConfig));
        }

        if (! $context->workItemProviderAmbiguous
            && $capabilities->has(WorkItemLinearAware::class)
            && $this->providerResolver->collectsLinear($context->workItemProviders)) {
            $missingGlobal = array_merge($missingGlobal, $this->findMissingKeys([GlobalStudConfigKeys::LINEAR_API_KEY], $context->globalConfig));
        }

        if ($capabilities->has(ConfluenceAware::class)) {
            $missingGlobal = array_merge($missingGlobal, $this->findMissingKeys(GlobalStudConfigKeys::requiredJiraCredentialKeys(), $context->globalConfig));
        }

        if ($capabilities->has(GitProviderGithubAware::class)
            && $this->providerResolver->collectsGithub($context->gitProviders)
            && ! $this->hasGithubToken($context)) {
            $missingGlobal[] = GlobalStudConfigKeys::GITHUB_TOKEN;
        }

        if ($capabilities->has(GitProviderGitlabAware::class)
            && $this->providerResolver->collectsGitlab($context->gitProviders)
            && ! $this->hasGitlabToken($context)) {
            $missingGlobal[] = GlobalStudConfigKeys::GITLAB_TOKEN;
        }

        if ($capabilities->has(ProjectBaseBranchAware::class)) {
            $missingProject = array_merge(
                $missingProject,
                $this->findMissingKeys([ProjectStudConfigKeys::BASE_BRANCH], $context->projectConfig ?? [])
            );
        }

        $missingGlobal = array_values(array_unique($missingGlobal));
        $missingProject = array_values(array_unique($missingProject));
        $canProceed = $environmentFailures === [] && $missingGlobal === [] && $missingProject === [];

        return new CommandGuardResult($missingGlobal, $missingProject, $canProceed, $environmentFailures);
    }

    /**
     * @param array<string> $requiredKeys
     * @param array<string, mixed> $config
     * @return array<string>
     */
    protected function findMissingKeys(array $requiredKeys, array $config): array
    {
        $missing = [];

        foreach ($requiredKeys as $key) {
            if (! isset($config[$key]) || ! is_string($config[$key]) || trim($config[$key]) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    protected function hasGithubToken(CommandContext $context): bool
    {
        return $this->hasNonEmptyConfigValue($context->globalConfig, GlobalStudConfigKeys::GITHUB_TOKEN)
            || $this->hasNonEmptyConfigValue($context->projectConfig ?? [], ProjectStudConfigKeys::GITHUB_TOKEN)
            || $this->hasLegacyGitToken($context, 'github');
    }

    protected function hasGitlabToken(CommandContext $context): bool
    {
        return $this->hasNonEmptyConfigValue($context->globalConfig, GlobalStudConfigKeys::GITLAB_TOKEN)
            || $this->hasNonEmptyConfigValue($context->projectConfig ?? [], ProjectStudConfigKeys::GITLAB_TOKEN)
            || $this->hasLegacyGitToken($context, 'gitlab');
    }

    protected function hasLegacyGitToken(CommandContext $context, string $provider): bool
    {
        $legacyToken = $context->globalConfig[GlobalStudConfigKeys::GIT_TOKEN] ?? null;
        if (! is_string($legacyToken) || trim($legacyToken) === '') {
            return false;
        }

        $legacyProvider = $context->globalConfig[GlobalStudConfigKeys::GIT_PROVIDER] ?? null;
        if (! is_string($legacyProvider)) {
            return false;
        }

        return strtolower(trim($legacyProvider)) === $provider;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function hasNonEmptyConfigValue(array $config, string $key): bool
    {
        if (! isset($config[$key]) || ! is_string($config[$key])) {
            return false;
        }

        return trim($config[$key]) !== '';
    }
}
