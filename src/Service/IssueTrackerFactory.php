<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\EffectiveIssueTrackerCredentials;
use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerException;
use App\Exception\IssueTrackerResolutionException;

class IssueTrackerFactory
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
        private readonly IssueKeyPrefixMatcher $issueKeyPrefixMatcher = new IssueKeyPrefixMatcher(),
        private readonly DualTrackerPolicy $dualTracker = new DualTrackerPolicy(),
        private readonly ?GitRepository $gitRepository = null,
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
        $globalConfig = (new EffectiveIssueTrackerCredentials())->overlay($globalConfig, $projectConfig);
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

        if ($this->dualTracker->isDualAuto($globalConfig, $projectConfig)) {
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
        IssueTrackerClients $clients,
        ?GitRepository $gitRepository = null,
    ): IssueTrackerPort {
        $gitRepository ??= $this->gitRepository;

        return match ($type) {
            IssueTrackerProvider::Jira->value => new JiraIssueTrackerAdapter(
                $clients->jira ?? throw new \InvalidArgumentException('Jira service is required for the jira work-item provider'),
                $clients->jiraAttachments ?? throw new \InvalidArgumentException('Jira attachment service is required for the jira work-item provider'),
            ),
            IssueTrackerProvider::Linear->value => new LinearIssueTrackerAdapter(
                $clients->linear ?? throw new \InvalidArgumentException('Linear API client is required for the linear work-item provider'),
                gitRepository: $gitRepository,
                linearAttachmentService: $clients->linearAttachments,
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown work-item provider type: %s', $type)),
        };
    }

    /**
     * @param 'jira'|'linear' $type
     *
     * @throws IssueTrackerException
     */
    public function createForProvider(string $type, IssueTrackerClients $clients): IssueTrackerPort
    {
        if ($type === IssueTrackerProvider::Jira->value) {
            if ($clients->jira === null || $clients->jiraAttachments === null) {
                throw IssueTrackerException::missingJiraConfiguration();
            }
        } elseif ($clients->linear === null) {
            throw IssueTrackerException::missingLinearApiKey();
        }

        return $this->create($type, $clients);
    }

    /**
     * Pinned project provider (`jira` / `linear`), or null when unset / `auto`.
     *
     * @param array<string, mixed> $projectConfig
     */
    public function readPinnedProvider(array $projectConfig): ?IssueTrackerProvider
    {
        return $this->readProjectProvider($projectConfig);
    }

    /**
     * Provider whose configured scope claims this issue-key prefix, or null when none / invalid key.
     *
     * @param array<string, mixed> $projectConfig
     */
    public function providerClaimingIssueKey(string $issueKey, array $projectConfig): ?IssueTrackerProvider
    {
        return $this->issueKeyPrefixMatcher->providerClaimingIssueKey($issueKey, $projectConfig);
    }

    public function issueKeyPrefixOrNull(string $issueKey): ?string
    {
        return $this->issueKeyPrefixMatcher->prefixOrNull($issueKey);
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
        $provider = $this->issueKeyPrefixMatcher->providerForIssueKey($issueKey, $projectConfig);
        $this->assertCredentials($provider->vendorSlug(), $globalConfig);

        return $provider->vendorSlug();
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
}
