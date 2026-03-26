<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\BranchDeletionEligibility;
use App\Enum\BranchAutoCleanDecision;

class BranchDeletionEligibilityResolver
{
    /** @var array<string> */
    private const PROTECTED_BRANCHES = ['develop', 'main', 'master'];

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly ?GitProviderInterface $gitProvider,
    ) {
    }

    /**
     * @return array{map: array<string, array<string, mixed>>, available: bool}
     */
    public function buildPullRequestSnapshot(): array
    {
        if ($this->gitProvider === null) {
            return ['map' => [], 'available' => false];
        }

        try {
            $allPrs = $this->gitProvider->getAllPullRequests('all');
        } catch (\Exception) {
            return ['map' => [], 'available' => false];
        }

        $prMap = [];
        foreach ($allPrs as $pr) {
            if (! isset($pr['head']['ref'])) {
                continue;
            }

            $branchName = (string) $pr['head']['ref'];
            $headRepoFullName = $pr['head']['repo']['full_name'] ?? null;
            $baseRepoFullName = $pr['base']['repo']['full_name'] ?? null;
            if ($headRepoFullName !== null && $baseRepoFullName !== null && $headRepoFullName !== $baseRepoFullName) {
                continue;
            }

            $prMap[$branchName] = $pr;
        }

        return ['map' => $prMap, 'available' => true];
    }

    public function resolveBaseBranch(?string $configuredBaseBranch): ?string
    {
        $candidates = [];
        if (is_string($configuredBaseBranch) && $configuredBaseBranch !== '') {
            $candidates[] = str_replace('origin/', '', $configuredBaseBranch);
        }

        foreach (['develop', 'main', 'master'] as $fallback) {
            if (! in_array($fallback, $candidates, true)) {
                $candidates[] = $fallback;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $this->gitRepository->remoteBranchExists('origin', $candidate)) {
                return 'origin/' . $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $prMap
     */
    public function evaluate(
        string $branch,
        string $currentBranch,
        bool $remoteExists,
        ?string $baseBranch,
        array $prMap,
        bool $providerDataAvailable
    ): BranchDeletionEligibility {
        $pr = $prMap[$branch] ?? null;
        $hasPullRequest = $pr !== null;
        $blockingDecision = $this->resolveBlockingDecision($branch, $currentBranch, $baseBranch, $pr, $hasPullRequest);
        if ($blockingDecision !== null) {
            return $blockingDecision;
        }

        $mergedByGit = $this->checkMergedByGit($branch, (string) $baseBranch);
        if ($mergedByGit === null) {
            return new BranchDeletionEligibility(BranchAutoCleanDecision::Manual, 'merge_check_failed', 'active', $hasPullRequest);
        }

        $mergedByProvider = $this->isProviderMergedPullRequest($pr);
        $isMerged = $mergedByGit || $mergedByProvider;
        if ($isMerged) {
            return new BranchDeletionEligibility(
                BranchAutoCleanDecision::Yes,
                'merged',
                $remoteExists ? 'merged' : 'stale',
                $hasPullRequest
            );
        }

        return $this->resolveUnmergedDecision($pr, $providerDataAvailable, $hasPullRequest);
    }

    protected function isProtectedBranch(string $branch): bool
    {
        return in_array($branch, self::PROTECTED_BRANCHES, true);
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function isOpenPullRequest(?array $pr): bool
    {
        if ($pr === null) {
            return false;
        }

        return ($pr['state'] ?? null) === 'open';
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function isProviderMergedPullRequest(?array $pr): bool
    {
        if ($pr === null) {
            return false;
        }

        if (! empty($pr['merged_at'])) {
            return true;
        }

        $gitlabRaw = $pr['_gitlab_data']['state'] ?? null;

        return $gitlabRaw === 'merged';
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function resolveBlockingDecision(
        string $branch,
        string $currentBranch,
        ?string $baseBranch,
        ?array $pr,
        bool $hasPullRequest
    ): ?BranchDeletionEligibility {
        if ($this->isProtectedBranch($branch)) {
            return new BranchDeletionEligibility(BranchAutoCleanDecision::No, 'protected_branch', 'active', false);
        }

        if ($branch === $currentBranch) {
            return new BranchDeletionEligibility(BranchAutoCleanDecision::No, 'current_branch', 'active', false);
        }

        if ($this->isOpenPullRequest($pr)) {
            return new BranchDeletionEligibility(BranchAutoCleanDecision::No, 'open_pull_request', 'active-pr', true);
        }

        if ($baseBranch === null) {
            return new BranchDeletionEligibility(BranchAutoCleanDecision::Manual, 'base_branch_unresolved', 'active', $hasPullRequest);
        }

        return null;
    }

    protected function checkMergedByGit(string $branch, string $baseBranch): ?bool
    {
        try {
            return $this->gitBranchService->isBranchMergedInto($branch, $baseBranch);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function resolveUnmergedDecision(?array $pr, bool $providerDataAvailable, bool $hasPullRequest): BranchDeletionEligibility
    {
        if ($hasPullRequest && $pr !== null && ($pr['state'] ?? null) === 'closed') {
            return new BranchDeletionEligibility(BranchAutoCleanDecision::Manual, 'closed_pull_request_unmerged', 'active', true);
        }

        if (! $providerDataAvailable) {
            return new BranchDeletionEligibility(BranchAutoCleanDecision::Manual, 'provider_unavailable', 'active', $hasPullRequest);
        }

        return new BranchDeletionEligibility(BranchAutoCleanDecision::No, 'not_merged', 'active', $hasPullRequest);
    }
}
