<?php

declare(strict_types=1);

namespace App\Guard;

use App\Config\EffectiveIssueTrackerCredentials;
use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitHosting\GithubAware;
use App\Guard\Capability\GitHosting\GitlabAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Service\GlobalConfigProviderResolver;

/**
 * Lists the global and project config keys a capability set needs but the context lacks.
 */
class MissingConfigKeyCollector
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function missingGlobalKeys(CapabilitySet $capabilities, CommandContext $context): array
    {
        $trackerConfig = (new EffectiveIssueTrackerCredentials())->overlay($context->globalConfig, $context->projectConfig);
        $missing = [];

        if ($this->resolvedProvidersInclude($context, IssueTrackerProvider::Jira->value)) {
            $missing = $this->findMissingKeys(GlobalStudConfigKeys::requiredJiraCredentialKeys(), $trackerConfig);
        }

        if ($this->resolvedProvidersInclude($context, IssueTrackerProvider::Linear->value)) {
            $missing = array_merge($missing, $this->findMissingKeys([GlobalStudConfigKeys::LINEAR_API_KEY], $trackerConfig));
        }

        if ($capabilities->has(ConfluenceAware::class)) {
            $missing = array_merge($missing, $this->findMissingKeys(GlobalStudConfigKeys::requiredJiraCredentialKeys(), $trackerConfig));
        }

        return array_values(array_unique(array_merge($missing, $this->missingGitTokens($capabilities, $context))));
    }

    /**
     * @return list<string>
     */
    public function missingProjectKeys(CapabilitySet $capabilities, CommandContext $context): array
    {
        $missing = [];

        if ($context->issueTrackerProviderAmbiguous
            && ! $this->resolvedProvidersInclude($context, IssueTrackerProvider::Jira->value)
            && ! $this->resolvedProvidersInclude($context, IssueTrackerProvider::Linear->value)
            && ($capabilities->has(JiraAware::class) || $capabilities->has(LinearAware::class))) {
            $missing[] = ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER;
        }

        if ($capabilities->has(ProjectBaseBranchAware::class)) {
            $missing = array_merge(
                $missing,
                $this->findMissingKeys([ProjectStudConfigKeys::BASE_BRANCH], $context->projectConfig ?? []),
            );
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return list<string>
     */
    protected function missingGitTokens(CapabilitySet $capabilities, CommandContext $context): array
    {
        $missing = [];

        if ($capabilities->has(GithubAware::class)
            && $this->providerResolver->collectsGithub($context->gitProviders)
            && ! $this->hasGitToken($context, 'github', GlobalStudConfigKeys::GITHUB_TOKEN, ProjectStudConfigKeys::GITHUB_TOKEN)) {
            $missing[] = GlobalStudConfigKeys::GITHUB_TOKEN;
        }

        if ($capabilities->has(GitlabAware::class)
            && $this->providerResolver->collectsGitlab($context->gitProviders)
            && ! $this->hasGitToken($context, 'gitlab', GlobalStudConfigKeys::GITLAB_TOKEN, ProjectStudConfigKeys::GITLAB_TOKEN)) {
            $missing[] = GlobalStudConfigKeys::GITLAB_TOKEN;
        }

        return $missing;
    }

    private function resolvedProvidersInclude(CommandContext $context, string $provider): bool
    {
        return in_array($provider, $context->issueTrackerProviders, true);
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

    protected function hasGitToken(CommandContext $context, string $provider, string $globalKey, string $projectKey): bool
    {
        return $this->hasNonEmptyConfigValue($context->globalConfig, $globalKey)
            || $this->hasNonEmptyConfigValue($context->projectConfig ?? [], $projectKey)
            || $this->hasLegacyGitToken($context, $provider);
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
