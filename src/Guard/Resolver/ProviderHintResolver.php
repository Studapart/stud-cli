<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Enum\WorkItemCommandProfile;
use App\Exception\IssueTrackerResolutionException;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\DTO\ProviderResolutionResult;
use App\Service\AttachmentUrlProviderGuesser;

/**
 * Infers the issue-tracker provider from invocation hints: issue key, attachment URL, or scope key.
 */
class ProviderHintResolver
{
    public function __construct(
        private readonly IssueTrackerResolutionDeps $deps = new IssueTrackerResolutionDeps(),
        private readonly AttachmentUrlProviderGuesser $attachmentUrlGuesser = new AttachmentUrlProviderGuesser(),
    ) {
    }

    /**
     * Returns null when no hint applies to the command profile.
     *
     * @param array<string, mixed> $projectConfig
     */
    public function resolve(ProviderResolutionRequest $request, array $projectConfig): ?ProviderResolutionResult
    {
        $issueKey = $this->normalizedKey($request->issueKey) ?? $this->normalizedKey($request->branchIssueKey);
        if ($issueKey !== null && $this->profileUsesIssueKey($request->commandProfile)) {
            return $this->resolveFromIssueKey($request->globalConfig, $projectConfig, $issueKey);
        }

        if ($request->attachmentUrl !== null
            && $request->commandProfile === WorkItemCommandProfile::KeySingle
            && $issueKey === null) {
            return $this->resolveFromAttachmentUrl($request->attachmentUrl);
        }

        $scopeKey = $this->normalizedKey($request->scopeKey);
        if ($scopeKey !== null && $this->profileUsesScopeKey($request->commandProfile)) {
            return $this->resolveFromScopeKey($scopeKey, $projectConfig);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    protected function resolveFromIssueKey(
        array $globalConfig,
        array $projectConfig,
        string $issueKey,
    ): ProviderResolutionResult {
        try {
            $provider = $this->deps->factory->resolveType(
                null,
                $globalConfig,
                $projectConfig,
                $issueKey,
            );

            return ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($provider)->vendorSlug()], 'issue_key');
        } catch (IssueTrackerResolutionException $e) {
            return ProviderResolutionResult::blocked($e->messageRef);
        }
    }

    protected function resolveFromAttachmentUrl(string $attachmentUrl): ProviderResolutionResult
    {
        $guessed = $this->attachmentUrlGuesser->guess($attachmentUrl);
        if ($guessed === null) {
            return ProviderResolutionResult::blocked(
                MessageRef::key('item.download.error_unknown_attachment_host'),
            );
        }

        return ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($guessed)->vendorSlug()], 'attachment_url');
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    protected function resolveFromScopeKey(string $scopeKey, array $projectConfig): ProviderResolutionResult
    {
        $scopeResult = $this->deps->scope->resolveProviderForDiscoveryScope($scopeKey, $projectConfig);
        if (! $scopeResult['ok']) {
            return ProviderResolutionResult::blocked($scopeResult['error']);
        }

        return ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($scopeResult['provider'])->vendorSlug()], 'scope_key');
    }

    protected function normalizedKey(?string $key): ?string
    {
        if (is_string($key) && trim($key) !== '') {
            return strtoupper(trim($key));
        }

        return null;
    }

    private function profileUsesIssueKey(WorkItemCommandProfile $profile): bool
    {
        return in_array($profile, [
            WorkItemCommandProfile::KeySingle,
            WorkItemCommandProfile::KeyOrBranch,
        ], true);
    }

    private function profileUsesScopeKey(WorkItemCommandProfile $profile): bool
    {
        return in_array($profile, [
            WorkItemCommandProfile::ScopeDiscovery,
            WorkItemCommandProfile::LinearOnly,
        ], true);
    }
}
