<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\GitProvider;

/**
 * Detects globally configured providers that lack stored credentials.
 *
 * Used by config:validate to surface warnings without failing connectivity checks
 * for the effective provider in the current repository.
 */
class ConfigProviderCredentialWarnings
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @return list<ResponseMessage>
     */
    public function collect(array $globalConfig): array
    {
        $warnings = [];
        $configuredGitProviders = $this->providerResolver->resolveGitProviders($globalConfig);
        $configuredWorkItemProviders = $this->providerResolver->resolveWorkItemProviders($globalConfig);

        if ($this->providerResolver->collectsGithub($configuredGitProviders) && ! $this->hasGithubToken($globalConfig)) {
            $warnings[] = ResponseMessage::warning(MessageRef::key('config.validate.warn_github_token_missing'));
        }

        if ($this->providerResolver->collectsGitlab($configuredGitProviders) && ! $this->hasGitlabToken($globalConfig)) {
            $warnings[] = ResponseMessage::warning(MessageRef::key('config.validate.warn_gitlab_token_missing'));
        }

        if ($this->providerResolver->collectsJira($configuredWorkItemProviders) && ! $this->hasJiraCredentials($globalConfig)) {
            $warnings[] = ResponseMessage::warning(MessageRef::key('config.validate.warn_jira_credentials_missing'));
        }

        if ($this->providerResolver->collectsLinear($configuredWorkItemProviders) && ! $this->hasLinearApiKey($globalConfig)) {
            $warnings[] = ResponseMessage::warning(MessageRef::key('config.validate.warn_linear_api_key_missing'));
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function hasGithubToken(array $globalConfig): bool
    {
        return $this->nonEmptyString($globalConfig['GITHUB_TOKEN'] ?? null)
            || $this->hasLegacyGitToken($globalConfig, GitProvider::Github->value);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function hasGitlabToken(array $globalConfig): bool
    {
        return $this->nonEmptyString($globalConfig['GITLAB_TOKEN'] ?? null)
            || $this->hasLegacyGitToken($globalConfig, GitProvider::Gitlab->value);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function hasJiraCredentials(array $globalConfig): bool
    {
        return $this->nonEmptyString($globalConfig['JIRA_URL'] ?? null)
            && $this->nonEmptyString($globalConfig['JIRA_EMAIL'] ?? null)
            && $this->nonEmptyString($globalConfig['JIRA_API_TOKEN'] ?? null);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function hasLinearApiKey(array $globalConfig): bool
    {
        return $this->nonEmptyString($globalConfig['LINEAR_API_KEY'] ?? null);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    protected function hasLegacyGitToken(array $globalConfig, string $provider): bool
    {
        if (! $this->nonEmptyString($globalConfig['GIT_TOKEN'] ?? null)) {
            return false;
        }

        $legacyProvider = $globalConfig['GIT_PROVIDER'] ?? null;
        if (! is_string($legacyProvider)) {
            return false;
        }

        return strtolower(trim($legacyProvider)) === $provider;
    }

    protected function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
