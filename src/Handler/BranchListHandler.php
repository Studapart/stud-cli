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

        $currentBranch = $this->gitRepository->getCurrentBranchName();

        // Build table data
        $rows = [];
        foreach ($branches as $branch) {
            $isCurrent = $branch === $currentBranch;
            $remoteExists = isset($remoteBranchesSet[$branch]);
            $status = $this->determineBranchStatus($branch, $remoteExists);
            $hasPr = $this->hasPullRequest($branch);

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
     * @return string The status: 'merged', 'stale', 'active-pr', or 'active'
     */
    protected function determineBranchStatus(string $branch, bool $remoteExists): string
    {
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Checking merge status for {$branch}...</>");
        $isMerged = $this->gitRepository->isBranchMergedInto($branch, $this->baseBranch);
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Branch {$branch} merged into {$this->baseBranch}: " . ($isMerged ? 'yes' : 'no') . "</>");

        $hasPr = $this->hasPullRequest($branch);
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
     * @return bool True if branch has a PR, false otherwise
     */
    protected function hasPullRequest(string $branch): bool
    {
        if (! $this->githubProvider) {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>No GitHub provider available, skipping PR check for {$branch}</>");

            return false;
        }

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
