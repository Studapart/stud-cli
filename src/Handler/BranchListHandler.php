<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\BranchListRow;
use App\Response\BranchListResponse;
use App\Service\GitProviderInterface;
use App\Service\GitRepository;
use App\Service\TranslationService;

class BranchListHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?GitProviderInterface $githubProvider,
        private readonly string $baseBranch,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(): BranchListResponse
    {
        $branches = $this->gitRepository->getAllLocalBranches();

        if (empty($branches)) {
            return BranchListResponse::success([]);
        }

        $remoteBranches = $this->gitRepository->getAllRemoteBranches('origin');
        $remoteBranchesSet = array_flip($remoteBranches);
        $prMap = $this->buildPrMap();
        $currentBranch = $this->gitRepository->getCurrentBranchName();

        $rows = [];
        foreach ($branches as $branch) {
            $isCurrent = $branch === $currentBranch;
            $remoteExists = isset($remoteBranchesSet[$branch]);
            $status = $this->determineBranchStatus($branch, $remoteExists, $prMap);
            $hasPr = $this->hasPullRequest($branch, $prMap);

            $branchDisplay = $branch;
            if ($isCurrent) {
                $branchDisplay = "{$branch} (current)";
            }

            $rows[] = new BranchListRow(
                $branchDisplay,
                $this->translator->trans("branches.list.status.{$status}"),
                $remoteExists ? '✓' : '✗',
                $hasPr ? '✓' : '✗'
            );
        }

        return BranchListResponse::success($rows);
    }

    /**
     * Determines the status of a branch.
     *
     * @param array<string, array<string, mixed>>|null $prMap Optional PR map for optimized lookups
     * @return string The status: 'merged', 'stale', 'active-pr', or 'active'
     */
    protected function determineBranchStatus(string $branch, bool $remoteExists, ?array $prMap = null): string
    {
        $isMerged = $this->gitRepository->isBranchMergedInto($branch, $this->baseBranch);
        $hasPr = $this->hasPullRequest($branch, $prMap);

        if ($hasPr) {
            return 'active-pr';
        }

        if ($isMerged) {
            if (! $remoteExists) {
                return 'stale';
            }

            return 'merged';
        }

        return 'active';
    }

    /**
     * Checks if a branch has an associated pull request.
     *
     * @param array<string, array<string, mixed>>|null $prMap Optional PR map for optimized lookups
     */
    protected function hasPullRequest(string $branch, ?array $prMap = null): bool
    {
        if (! $this->githubProvider) {
            return false;
        }

        if ($prMap !== null) {
            return isset($prMap[$branch]);
        }

        try {
            $pr = $this->githubProvider->findPullRequestByBranchName($branch, 'all');

            return $pr !== null;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Builds a map of branch names to PR data for optimized lookups.
     *
     * @return array<string, array<string, mixed>> Map of branch name => PR data, or empty array if fetch fails
     */
    protected function buildPrMap(): array
    {
        if (! $this->githubProvider) {
            return [];
        }

        try {
            $allPrs = $this->githubProvider->getAllPullRequests('all');
            $prMap = [];

            foreach ($allPrs as $pr) {
                if (! isset($pr['head']['ref'])) {
                    continue;
                }

                $branchName = $pr['head']['ref'];
                $headRepoFullName = $pr['head']['repo']['full_name'] ?? null;
                $baseRepoFullName = $pr['base']['repo']['full_name'] ?? null;
                if ($headRepoFullName === null || $baseRepoFullName === null || $headRepoFullName !== $baseRepoFullName) {
                    continue;
                }

                $prMap[$branchName] = $pr;
            }

            return $prMap;
        } catch (\Exception) {
            return [];
        }
    }
}
