<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GithubProvider;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchListHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?GithubProvider $githubProvider,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.list.section'));

        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.list.fetching_local')}</>");
        $branches = $this->gitRepository->getAllLocalBranches();

        if (empty($branches)) {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.list.no_branches'));

            return 0;
        }

        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.list.fetching_remote', ['count' => count($branches)])}</>");
        $remoteBranches = $this->gitRepository->getAllRemoteBranches('origin');
        $remoteBranchesSet = array_flip($remoteBranches);

        $this->logger->note(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.list.note_origin')}</>");

        // Fetch all PRs once and build PR map for optimized lookups
        $prMap = $this->buildPrMap();

        $currentBranch = $this->gitRepository->getCurrentBranchName();

        // Build table data
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

            $rows[] = [
                'branch' => $branchDisplay,
                'status' => $this->translator->trans("branches.list.status.{$status}"),
                'remote' => $remoteExists ? '✓' : '✗',
                'pr' => $hasPr ? '✓' : '✗',
            ];
        }

        // Display table
        $this->displayTable($rows);

        return 0;
    }

    /**
     * Determines the status of a branch.
     *
     * @param string $branch The branch name
     * @param bool $remoteExists Whether the branch exists on remote
     * @param array<string, array<string, mixed>>|null $prMap Optional PR map for optimized lookups
     * @return string The status: 'merged', 'stale', 'active-pr', or 'active'
     */
    protected function determineBranchStatus(string $branch, bool $remoteExists, ?array $prMap = null): string
    {
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Checking merge status for {$branch}...</>");
        $isMerged = $this->gitRepository->isBranchMergedInto($branch, $this->baseBranch);
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Branch {$branch} merged into {$this->baseBranch}: " . ($isMerged ? 'yes' : 'no') . "</>");

        $hasPr = $this->hasPullRequest($branch, $prMap);
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Branch {$branch} has PR: " . ($hasPr ? 'yes' : 'no') . "</>");

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
     * @param string $branch The branch name
     * @param array<string, array<string, mixed>>|null $prMap Optional PR map for optimized lookups
     * @return bool True if branch has a PR, false otherwise
     */
    protected function hasPullRequest(string $branch, ?array $prMap = null): bool
    {
        if (! $this->githubProvider) {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>No GitHub provider available, skipping PR check for {$branch}</>");

            return false;
        }

        // Use PR map if provided (optimized path)
        if ($prMap !== null) {
            $hasPr = isset($prMap[$branch]);
            if ($hasPr) {
                $pr = $prMap[$branch];
                $prState = $pr['state'] ?? 'unknown';
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Found PR #{$pr['number']} for {$branch} (state: {$prState})</>");
            }

            return $hasPr;
        }

        // Fallback to per-branch API call (backward compatibility)
        try {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Checking PR for branch {$branch}...</>");
            $pr = $this->githubProvider->findPullRequestByBranchName($branch, 'all');
            $hasPr = $pr !== null;
            if ($hasPr) {
                $prState = $pr['state'] ?? 'unknown';
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Found PR #{$pr['number']} for {$branch} (state: {$prState})</>");
            }

            return $hasPr;
        } catch (\Exception $e) {
            // Log error at verbose level but don't fail
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Error checking PR for {$branch}: {$e->getMessage()}</>");
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>PR check exception: {$e->getMessage()}</>");

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
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Fetching all PRs for optimized lookups...</>");
            $allPrs = $this->githubProvider->getAllPullRequests('all');
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Fetched " . count($allPrs) . " PRs</>");

            $prMap = [];
            foreach ($allPrs as $pr) {
                // Extract branch name from head.ref
                if (! isset($pr['head']['ref'])) {
                    continue;
                }

                $branchName = $pr['head']['ref'];

                // Only map PRs from the same repository (exclude fork PRs)
                // PRs from the same repo have head.repo.full_name === base.repo.full_name
                $headRepoFullName = $pr['head']['repo']['full_name'] ?? null;
                $baseRepoFullName = $pr['base']['repo']['full_name'] ?? null;
                if ($headRepoFullName === null || $baseRepoFullName === null || $headRepoFullName !== $baseRepoFullName) {
                    continue;
                }

                $prMap[$branchName] = $pr;
            }

            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Built PR map with " . count($prMap) . " entries</>");

            return $prMap;
        } catch (\Exception $e) {
            // Log warning and return empty map (will fall back to per-branch calls)
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Warning: Failed to fetch all PRs, falling back to per-branch lookups: {$e->getMessage()}</>");
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>PR map build exception: {$e->getMessage()}</>");

            return [];
        }
    }

    /**
     * Displays the branch list as a table.
     *
     * @param array<int, array{branch: string, status: string, remote: string, pr: string}> $rows The table rows
     */
    protected function displayTable(array $rows): void
    {
        $headers = [
            $this->translator->trans('branches.list.column.branch'),
            $this->translator->trans('branches.list.column.status'),
            $this->translator->trans('branches.list.column.remote'),
            $this->translator->trans('branches.list.column.pr'),
        ];

        $tableData = [];
        foreach ($rows as $row) {
            $tableData[] = [
                $row['branch'],
                $row['status'],
                $row['remote'],
                $row['pr'],
            ];
        }

        $this->logger->table(Logger::VERBOSITY_NORMAL, $headers, $tableData);
    }
}
