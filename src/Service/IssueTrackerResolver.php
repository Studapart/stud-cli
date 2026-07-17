<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerException;
use App\Exception\IssueTrackerResolutionException;

/**
 * Resolves the active work-item provider for project-scoped commands
 * using global ISSUE_TRACKER_PROVIDERS and optional project issueTrackerProvider.
 */
final class IssueTrackerResolver
{
    private readonly IssueTrackerFactory $factory;

    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
        ?IssueTrackerFactory $factory = null,
    ) {
        $this->factory = $factory ?? new IssueTrackerFactory($this->globalResolver);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear'}|array{ok: false, error: MessageRef}
     */
    public function resolveActiveProvider(
        array $globalConfig,
        array $projectConfig,
        ?string $issueKey = null,
    ): array {
        try {
            $provider = IssueTrackerProvider::fromResolved(
                $this->factory->resolveType(null, $globalConfig, $projectConfig, $issueKey),
            );

            return ['ok' => true, 'provider' => $provider->vendorSlug()];
        } catch (IssueTrackerException|IssueTrackerResolutionException $e) {
            return ['ok' => false, 'error' => $e->messageRef];
        }
    }
}
