<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerException;
use App\Exception\IssueTrackerResolutionException;

class IssueTrackerFactory
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function resolveType(
        ?string $cliOverride,
        array $globalConfig,
        array $projectConfig,
        ?string $issueKey = null,
    ): string {
        $normalizedOverride = $this->normalizeOverride($cliOverride);
        if ($normalizedOverride !== null) {
            $this->assertCredentials($normalizedOverride->value, $globalConfig);

            return $normalizedOverride->value;
        }

        $projectProvider = $this->readProjectProvider($projectConfig);
        if ($projectProvider !== null) {
            $this->assertCredentials($projectProvider->value, $globalConfig);

            return $projectProvider->value;
        }

        if ($this->shouldResolveByIssueKeyPrefix($globalConfig, $projectConfig)) {
            return $this->resolveByIssueKeyPrefix($globalConfig, $projectConfig, $issueKey);
        }

        return $this->resolveSingleConfiguredProvider($globalConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function getEffectiveProvider(
        ?string $cliOverride,
        array $globalConfig,
        array $projectConfig,
        ?string $issueKey = null,
    ): IssueTrackerProvider {
        return IssueTrackerProvider::fromResolved(
            $this->resolveType($cliOverride, $globalConfig, $projectConfig, $issueKey),
        );
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function assertCredentials(string $type, array $globalConfig): void
    {
        $provider = IssueTrackerProvider::fromResolved($type);
        if (GlobalStudConfigKeys::hasCredentialsFor($provider, $globalConfig)) {
            return;
        }

        throw match ($provider) {
            IssueTrackerProvider::Jira => IssueTrackerException::missingJiraConfiguration(),
            IssueTrackerProvider::Linear => IssueTrackerException::missingLinearApiKey(),
            IssueTrackerProvider::Auto => IssueTrackerException::notConfigured(),
        };
    }

    public function create(
        string $type,
        ?JiraApiClient $jiraService = null,
        ?JiraAttachmentService $attachmentService = null,
        ?LinearApiClient $linearApiClient = null,
        ?GitRepository $gitRepository = null,
        ?LinearAttachmentService $linearAttachmentService = null,
    ): IssueTrackerPort {
        return match ($type) {
            IssueTrackerProvider::Jira->value => new JiraIssueTrackerAdapter(
                $jiraService ?? throw new \InvalidArgumentException('Jira service is required for the jira work-item provider'),
                $attachmentService ?? throw new \InvalidArgumentException('Jira attachment service is required for the jira work-item provider'),
            ),
            IssueTrackerProvider::Linear->value => new LinearIssueTrackerAdapter(
                $linearApiClient ?? throw new \InvalidArgumentException('Linear API client is required for the linear work-item provider'),
                gitRepository: $gitRepository,
                linearAttachmentService: $linearAttachmentService,
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown work-item provider type: %s', $type)),
        };
    }

    /**
     * @param 'jira'|'linear' $type
     *
     * @throws IssueTrackerException
     */
    public function createForProvider(
        string $type,
        ?JiraApiClient $jiraApiClient,
        ?JiraAttachmentService $attachmentService,
        ?LinearApiClient $linearApiClient,
        ?GitRepository $gitRepository = null,
        ?LinearAttachmentService $linearAttachmentService = null,
    ): IssueTrackerPort {
        if ($type === IssueTrackerProvider::Jira->value) {
            if ($jiraApiClient === null || $attachmentService === null) {
                throw IssueTrackerException::missingJiraConfiguration();
            }

            return $this->create(IssueTrackerProvider::Jira->value, $jiraApiClient, $attachmentService);
        }

        if ($linearApiClient === null) {
            throw IssueTrackerException::missingLinearApiKey();
        }

        return $this->create(
            IssueTrackerProvider::Linear->value,
            linearApiClient: $linearApiClient,
            gitRepository: $gitRepository,
            linearAttachmentService: $linearAttachmentService,
        );
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function resolveByIssueKeyPrefix(
        array $globalConfig,
        array $projectConfig,
        ?string $issueKey,
    ): string {
        $trimmedKey = $issueKey !== null ? trim($issueKey) : '';
        if ($trimmedKey === '') {
            throw IssueTrackerResolutionException::autoRequiresIssueKey();
        }

        try {
            $prefix = GitProjectConfigService::extractIssueKeyPrefix($trimmedKey);
        } catch (\RuntimeException) {
            throw IssueTrackerResolutionException::unknownPrefix(
                $trimmedKey,
                $this->formatConfiguredKeyPrefixes($projectConfig),
            );
        }

        $provider = $this->resolveProviderForPrefix($prefix, $projectConfig);
        if ($provider === null) {
            throw IssueTrackerResolutionException::unknownPrefix(
                $prefix,
                $this->formatConfiguredKeyPrefixes($projectConfig),
            );
        }

        $this->assertCredentials($provider->vendorSlug(), $globalConfig);

        return $provider->vendorSlug();
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function resolveProviderForPrefix(string $prefix, array $projectConfig): ?IssueTrackerProvider
    {
        $matchesJira = $this->prefixMatchesJira($prefix, $projectConfig);
        $matchesLinear = $this->prefixMatchesLinear($prefix, $projectConfig);

        if ($matchesJira && $matchesLinear) {
            throw IssueTrackerResolutionException::ambiguousPrefix($prefix);
        }

        if ($matchesJira) {
            return IssueTrackerProvider::Jira;
        }

        if ($matchesLinear) {
            return IssueTrackerProvider::Linear;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    private function resolveSingleConfiguredProvider(array $globalConfig): string
    {
        $sole = $this->soleConfiguredVendor($globalConfig);
        if ($sole === null) {
            throw IssueTrackerException::notConfigured();
        }

        return $sole->vendorSlug();
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    private function hasDualConfiguredProviders(array $globalConfig): bool
    {
        foreach (IssueTrackerProvider::vendors() as $vendor) {
            if (! GlobalStudConfigKeys::hasCredentialsFor($vendor, $globalConfig)) {
                return false;
            }
        }

        $globalProviders = $this->globalResolver->resolveIssueTrackerProviders($globalConfig);

        foreach (IssueTrackerProvider::vendors() as $vendor) {
            if (! $this->globalResolver->collectsIssueTracker($vendor, $globalProviders)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    private function soleConfiguredVendor(array $globalConfig): ?IssueTrackerProvider
    {
        $globalProviders = $this->globalResolver->resolveIssueTrackerProviders($globalConfig);
        $listed = $this->vendorsListedInConfig($globalProviders);
        if (count($listed) === 1) {
            return $listed[0];
        }

        $withCredentials = $this->vendorsWithCredentials($globalConfig);
        if (count($withCredentials) === 1) {
            return $withCredentials[0];
        }

        return null;
    }

    /**
     * @param list<string> $providerSlugs
     * @return list<IssueTrackerProvider>
     */
    private function vendorsListedInConfig(array $providerSlugs): array
    {
        $vendors = [];
        foreach (IssueTrackerProvider::vendors() as $vendor) {
            if ($this->globalResolver->collectsIssueTracker($vendor, $providerSlugs)) {
                $vendors[] = $vendor;
            }
        }

        return $vendors;
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @return list<IssueTrackerProvider>
     */
    private function vendorsWithCredentials(array $globalConfig): array
    {
        $vendors = [];
        foreach (IssueTrackerProvider::vendors() as $vendor) {
            if (GlobalStudConfigKeys::hasCredentialsFor($vendor, $globalConfig)) {
                $vendors[] = $vendor;
            }
        }

        return $vendors;
    }

    private function normalizeOverride(?string $cliOverride): ?IssueTrackerProvider
    {
        if ($cliOverride === null || trim($cliOverride) === '') {
            return null;
        }

        $normalized = strtolower(trim($cliOverride));
        if ($normalized === IssueTrackerProvider::Auto->value) {
            throw IssueTrackerResolutionException::invalidOverride($cliOverride);
        }

        $provider = IssueTrackerProvider::tryFrom($normalized);
        if ($provider === null) {
            throw IssueTrackerResolutionException::invalidOverride($cliOverride);
        }

        return $provider;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function readProjectProvider(array $projectConfig): ?IssueTrackerProvider
    {
        $stored = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);
        if ($stored === null) {
            return null;
        }

        $provider = IssueTrackerProvider::tryFromNormalized($stored);
        if ($provider === null || $provider->isAuto()) {
            return null;
        }

        return $provider;
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function shouldResolveByIssueKeyPrefix(array $globalConfig, array $projectConfig): bool
    {
        if (! $this->hasDualConfiguredProviders($globalConfig)) {
            return false;
        }

        $projectSetting = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);
        if ($projectSetting === null) {
            return true;
        }

        return $projectSetting === IssueTrackerProvider::Auto->value;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function prefixMatchesJira(string $prefix, array $projectConfig): bool
    {
        foreach ($this->jiraKeyPrefixes($projectConfig) as $configuredPrefix) {
            if (strcasecmp($prefix, $configuredPrefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function prefixMatchesLinear(string $prefix, array $projectConfig): bool
    {
        $linearTeamKey = $projectConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] ?? null;
        if (! is_string($linearTeamKey) || trim($linearTeamKey) === '') {
            return false;
        }

        return strcasecmp($prefix, trim($linearTeamKey)) === 0;
    }

    /**
     * @param array<string, mixed> $projectConfig
     * @return list<string>
     */
    private function jiraKeyPrefixes(array $projectConfig): array
    {
        $prefixes = [];
        foreach ([ProjectStudConfigKeys::PROJECT_KEY, ProjectStudConfigKeys::JIRA_DEFAULT_PROJECT] as $key) {
            $value = $projectConfig[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $prefixes[] = strtoupper(trim($value));
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function formatConfiguredKeyPrefixes(array $projectConfig): string
    {
        $parts = [];
        foreach ($this->jiraKeyPrefixes($projectConfig) as $prefix) {
            $parts[] = $prefix . ' (' . IssueTrackerProvider::Jira->value . ')';
        }

        $linearTeamKey = $projectConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] ?? null;
        if (is_string($linearTeamKey) && trim($linearTeamKey) !== '') {
            $parts[] = strtoupper(trim($linearTeamKey)) . ' (' . IssueTrackerProvider::Linear->value . ')';
        }

        if ($parts === []) {
            return '(none)';
        }

        return implode(', ', $parts);
    }
}
