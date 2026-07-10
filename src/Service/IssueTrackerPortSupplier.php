<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerException;
use App\Exception\IssueTrackerResolutionException;

/**
 * Resolves the active work-item provider and builds the matching {@see IssueTrackerPort}.
 *
 * Keeps HTTP clients out of handlers; wired once in castor.php.
 */
class IssueTrackerPortSupplier
{
    public function __construct(
        private readonly IssueTrackerFactory $factory,
        private readonly IssueTrackerResolver $resolver,
        private readonly ?JiraApiClient $jiraApiClient,
        private readonly ?JiraAttachmentService $attachmentService,
        private readonly ?LinearApiClient $linearApiClient,
        private readonly ?LinearAttachmentService $linearAttachmentService = null,
        private readonly ProjectScopeKeyResolver $scopeKeyResolver = new ProjectScopeKeyResolver(),
        private readonly GlobalConfigProviderResolver $globalProviderResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear', port: IssueTrackerPort}|array{ok: false, error: MessageRef}
     */
    public function resolve(array $globalConfig, array $projectConfig): array
    {
        $resolution = $this->resolver->resolveActiveProvider($globalConfig, $projectConfig);
        if (! $resolution['ok']) {
            return $resolution;
        }

        return $this->buildPortResult($resolution['provider'], $globalConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear', port: IssueTrackerPort}|array{ok: false, error: MessageRef}
     */
    public function resolveForProvider(string $provider, array $globalConfig): array
    {
        $normalized = IssueTrackerProvider::tryFromNormalized($provider);
        if ($normalized === null || $normalized->isAuto()) {
            return [
                'ok' => false,
                'error' => MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => $provider]),
            ];
        }

        return $this->buildPortResult($normalized->vendorSlug(), $globalConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear', port: IssueTrackerPort}|array{ok: false, error: MessageRef}
     */
    public function resolveForDiscovery(string $scopeKey, array $globalConfig, array $projectConfig): array
    {
        $explicitProvider = $this->readExplicitProjectProvider($projectConfig);
        if ($explicitProvider !== null) {
            return $this->resolveForProvider($explicitProvider, $globalConfig);
        }

        if ($this->shouldInferProviderFromScope($globalConfig, $projectConfig)) {
            $inferred = $this->scopeKeyResolver->resolveProviderForDiscoveryScope($scopeKey, $projectConfig);
            if (! $inferred['ok']) {
                return $inferred;
            }

            return $this->resolveForProvider($inferred['provider'], $globalConfig);
        }

        return $this->resolve($globalConfig, $projectConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear', port: IssueTrackerPort}|array{ok: false, error: MessageRef}
     */
    private function buildPortResult(string $provider, array $globalConfig): array
    {
        try {
            $resolved = IssueTrackerProvider::fromResolved($provider);
        } catch (IssueTrackerResolutionException $e) {
            return ['ok' => false, 'error' => $e->messageRef];
        }

        $providerSlug = $resolved->vendorSlug();

        try {
            $this->factory->assertCredentials($providerSlug, $globalConfig);
        } catch (IssueTrackerException $e) {
            return ['ok' => false, 'error' => $e->messageRef];
        }

        try {
            return [
                'ok' => true,
                'provider' => $providerSlug,
                'port' => $this->factory->createForProvider(
                    $providerSlug,
                    $this->jiraApiClient,
                    $this->attachmentService,
                    $this->linearApiClient,
                    linearAttachmentService: $this->linearAttachmentService,
                ),
            ];
        } catch (IssueTrackerException $e) {
            return ['ok' => false, 'error' => $e->messageRef];
        }
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function readExplicitProjectProvider(array $projectConfig): ?string
    {
        $stored = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);
        if ($stored === null || ! IssueTrackerProvider::isProjectConfigValue($stored)) {
            return null;
        }

        if ($stored === IssueTrackerProvider::Auto->value) {
            return null;
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function shouldInferProviderFromScope(array $globalConfig, array $projectConfig): bool
    {
        if (! $this->isDualPmConfigured($globalConfig)) {
            return false;
        }

        $stored = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);

        return $stored === null || $stored === IssueTrackerProvider::Auto->value;
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    private function isDualPmConfigured(array $globalConfig): bool
    {
        $providers = $this->globalProviderResolver->resolveIssueTrackerProviders($globalConfig);
        if (! $this->globalProviderResolver->collectsJira($providers)
            || ! $this->globalProviderResolver->collectsLinear($providers)) {
            return false;
        }

        return GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, $globalConfig)
            && GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Linear, $globalConfig);
    }
}
