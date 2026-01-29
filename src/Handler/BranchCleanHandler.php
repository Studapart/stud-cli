<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitProviderInterface;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchCleanHandler
{
    /** @var array<string> Protected branches that should never be deleted */
    private const PROTECTED_BRANCHES = ['develop', 'main', 'master'];

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?GitProviderInterface $githubProvider,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, bool $quiet = false): int
    {
        $this->displayHeader();

        $result = $this->findBranchesToClean();
        $branchesToCleanLocal = $result['local_only'];
        $branchesToCleanRemote = $result['with_remote'];
        $currentBranchSkipped = $result['current_branch_skipped'];

        if ($this->shouldExitEarly($branchesToCleanLocal, $branchesToCleanRemote, $currentBranchSkipped)) {
            return 0;
        }

        $this->notifyCurrentBranchSkipped($currentBranchSkipped);
        $this->displayBranchesToClean($branchesToCleanLocal, $branchesToCleanRemote, $quiet);

        if (! $this->confirmDeletion($branchesToCleanLocal, $branchesToCleanRemote, $quiet)) {
            return 0;
        }

        $deletedCount = $this->deleteBranches($branchesToCleanLocal, $branchesToCleanRemote, $quiet);
        $this->displayDeletionResult($deletedCount);

        return 0;
    }

    /**
     * Displays the header section.
     */
    protected function displayHeader(): void
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.section'));
        $this->logger->note(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.note_origin')}</>");
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.scanning'));
        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.fetching_local')}</>");
    }

    /**
     * Checks if we should exit early (no branches to clean).
     *
     * @param array<string> $branchesToCleanLocal Local-only branches
     * @param array<string> $branchesToCleanRemote Branches with remote
     * @param bool $currentBranchSkipped Whether current branch was skipped
     * @return bool True if should exit early, false otherwise
     */
    protected function shouldExitEarly(array $branchesToCleanLocal, array $branchesToCleanRemote, bool $currentBranchSkipped): bool
    {
        $totalBranches = count($branchesToCleanLocal) + count($branchesToCleanRemote);

        if ($totalBranches === 0 && ! $currentBranchSkipped) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.none'));

            return true;
        }

        if ($totalBranches === 0) {
            return true;
        }

        return false;
    }

    /**
     * Notifies user if current branch was skipped.
     *
     * @param bool $currentBranchSkipped Whether current branch was skipped
     */
    protected function notifyCurrentBranchSkipped(bool $currentBranchSkipped): void
    {
        if ($currentBranchSkipped) {
            $currentBranch = $this->gitRepository->getCurrentBranchName();
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.current_branch_skipped', ['branch' => $currentBranch]));
        }
    }

    /**
     * Displays the list of branches to be cleaned.
     *
     * @param array<string> $branchesToCleanLocal Local-only branches
     * @param array<string> $branchesToCleanRemote Branches with remote
     * @param bool $quiet Whether in quiet mode
     */
    protected function displayBranchesToClean(array $branchesToCleanLocal, array $branchesToCleanRemote, bool $quiet): void
    {
        $totalBranches = count($branchesToCleanLocal) + count($branchesToCleanRemote);
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.found', ['count' => $totalBranches]));

        if (! empty($branchesToCleanLocal)) {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.local_only_header', ['count' => count($branchesToCleanLocal)]));
            $this->displayBranchesList($branchesToCleanLocal);
        }

        if (! empty($branchesToCleanRemote)) {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.with_remote_header', ['count' => count($branchesToCleanRemote)]));
            $this->displayBranchesList($branchesToCleanRemote);
            if (! $quiet) {
                $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.with_remote_note'));
            }
        }
    }

    /**
     * Confirms deletion with user (if not in quiet mode).
     *
     * @param array<string> $branchesToCleanLocal Local-only branches
     * @param array<string> $branchesToCleanRemote Branches with remote
     * @param bool $quiet Whether in quiet mode
     * @return bool True if confirmed or quiet mode, false if cancelled
     */
    protected function confirmDeletion(array $branchesToCleanLocal, array $branchesToCleanRemote, bool $quiet): bool
    {
        if ($quiet) {
            return true;
        }

        $totalBranches = count($branchesToCleanLocal) + count($branchesToCleanRemote);
        $confirmed = $this->logger->confirm(
            $this->translator->trans('branches.clean.confirm', ['count' => $totalBranches]),
            true
        );

        if (! $confirmed) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.cancelled'));

            return false;
        }

        return true;
    }

    /**
     * Displays the deletion result.
     *
     * @param int $deletedCount Number of branches deleted
     */
    protected function displayDeletionResult(int $deletedCount): void
    {
        if ($deletedCount > 0) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.deleted', ['count' => $deletedCount]));
        }
    }

    /**
     * Finds branches that are safe to clean (merged, not protected, not current).
     *
     * @return array{local_only: array<string>, with_remote: array<string>, current_branch_skipped: bool}
     */
    protected function findBranchesToClean(): array
    {
        $allBranches = $this->fetchAllBranches();
        $remoteBranchesSet = $this->fetchRemoteBranchesSet();
        $currentBranch = $this->gitRepository->getCurrentBranchName();
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Current branch: {$currentBranch}</>");

        // Fetch all PRs once and build PR map for optimized lookups
        $prMap = $this->buildPrMap();

        $branchesToCleanLocal = [];
        $branchesToCleanRemote = [];
        $currentBranchSkipped = false;

        foreach ($allBranches as $branch) {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Evaluating branch: {$branch}</>");

            if ($this->shouldSkipBranch($branch, $currentBranch, $currentBranchSkipped)) {
                continue;
            }

            if (! $this->isBranchMerged($branch)) {
                continue;
            }

            if ($this->hasOpenPullRequest($branch, $prMap)) {
                continue;
            }

            $remoteExists = isset($remoteBranchesSet[$branch]);
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Exists on remote: " . ($remoteExists ? 'yes' : 'no') . "</>");

            // Branch is merged and safe to delete
            if ($remoteExists) {
                $branchesToCleanRemote[] = $branch;
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=green>Added to remote deletion list</>");
            } else {
                $branchesToCleanLocal[] = $branch;
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=green>Added to local-only deletion list</>");
            }
        }

        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Summary: " . count($branchesToCleanLocal) . " local-only, " . count($branchesToCleanRemote) . " with remote</>");

        return [
            'local_only' => $branchesToCleanLocal,
            'with_remote' => $branchesToCleanRemote,
            'current_branch_skipped' => $currentBranchSkipped,
        ];
    }

    /**
     * Fetches all local branches.
     *
     * @return array<string> All local branch names
     */
    protected function fetchAllBranches(): array
    {
        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.fetching_local')}</>");
        $allBranches = $this->gitRepository->getAllLocalBranches();
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($allBranches) . " local branches</>");

        return $allBranches;
    }

    /**
     * Fetches remote branches as a set for fast lookup.
     *
     * @return array<string, int> Remote branch names as keys (flipped array)
     */
    protected function fetchRemoteBranchesSet(): array
    {
        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.fetching_remote')}</>");
        $remoteBranches = $this->gitRepository->getAllRemoteBranches('origin');
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($remoteBranches) . " remote branches on origin</>");

        return array_flip($remoteBranches);
    }

    /**
     * Checks if a branch should be skipped (protected or current).
     *
     * @param string $branch Branch name to check
     * @param string $currentBranch Current branch name
     * @param bool $currentBranchSkipped Reference to track if current branch was skipped
     * @return bool True if branch should be skipped, false otherwise
     */
    protected function shouldSkipBranch(string $branch, string $currentBranch, bool &$currentBranchSkipped): bool
    {
        if ($this->isProtectedBranch($branch)) {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=yellow>Protected branch, skipping</>");

            return true;
        }

        if ($branch === $currentBranch) {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=yellow>Current branch, will skip</>");
            $currentBranchSkipped = true;

            return true;
        }

        return false;
    }

    /**
     * Checks if a branch is merged into the base branch.
     *
     * @param string $branch Branch name to check
     * @return bool True if branch is merged, false otherwise
     */
    protected function isBranchMerged(string $branch): bool
    {
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Checking if merged into {$this->baseBranch}...</>");

        try {
            $isMerged = $this->gitRepository->isBranchMergedInto($branch, $this->baseBranch);
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Merged into {$this->baseBranch}: " . ($isMerged ? 'yes' : 'no') . "</>");

            return $isMerged;
        } catch (\Exception $e) {
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=yellow>Warning: Could not check merge status for {$branch}: {$e->getMessage()}</>");
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=red>Exception: {$e->getMessage()}</>");

            return false;
        }
    }

    /**
     * Checks if a branch has an open pull request.
     *
     * @param string $branch Branch name to check
     * @param array<string, array<string, mixed>>|null $prMap Optional PR map for optimized lookups
     * @return bool True if branch has an open PR, false otherwise
     */
    protected function hasOpenPullRequest(string $branch, ?array $prMap = null): bool
    {
        if ($this->githubProvider === null) {
            return false;
        }

        // Use PR map if provided (optimized path)
        if ($prMap !== null) {
            if (! isset($prMap[$branch])) {
                return false;
            }

            $pr = $prMap[$branch];
            $prState = $pr['state'] ?? 'unknown';
            $prNumber = $pr['number'] ?? '?';
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>PR #{$prNumber} found (state: {$prState})</>");

            if ($prState === 'open') {
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=yellow>Open PR found, skipping deletion</>");

                return true;
            }

            return false;
        }

        // Fallback to per-branch API call (backward compatibility)
        try {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Checking PR status...</>");
            $pr = $this->githubProvider->findPullRequestByBranchName($branch, 'all');
            if ($pr === null) {
                return false;
            }

            $prState = $pr['state'] ?? 'unknown';
            $prNumber = $pr['number'] ?? '?';
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>PR #{$prNumber} found (state: {$prState})</>");

            if ($prState === 'open') {
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=yellow>Open PR found, skipping deletion</>");

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Warning: Could not check PR for {$branch}: {$e->getMessage()}</>");
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=red>PR check exception: {$e->getMessage()}</>");

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
     * Checks if a branch is protected (should never be deleted).
     *
     * @param string $branch The branch name
     * @return bool True if branch is protected, false otherwise
     */
    protected function isProtectedBranch(string $branch): bool
    {
        return in_array($branch, self::PROTECTED_BRANCHES, true);
    }

    /**
     * Displays the list of branches to be cleaned.
     *
     * @param array<string> $branches The branches to display
     */
    protected function displayBranchesList(array $branches): void
    {
        foreach ($branches as $branch) {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, "  - {$branch}");
        }
    }

    /**
     * Deletes the specified branches, handling errors gracefully.
     *
     * @param array<string> $localOnlyBranches Branches to delete locally only
     * @param array<string> $withRemoteBranches Branches that exist on remote (delete local, suggest remote)
     * @param bool $quiet Whether to skip remote deletion prompts
     * @return int Number of successfully deleted branches
     */
    protected function deleteBranches(array $localOnlyBranches, array $withRemoteBranches, bool $quiet): int
    {
        $deletedCount = 0;

        $deletedCount += $this->deleteLocalOnlyBranches($localOnlyBranches);
        $deletedCount += $this->deleteBranchesWithRemote($withRemoteBranches, $quiet);

        return $deletedCount;
    }

    /**
     * Deletes local-only branches.
     *
     * @param array<string> $branches Branches to delete locally only
     * @return int Number of successfully deleted branches
     */
    protected function deleteLocalOnlyBranches(array $branches): int
    {
        $deletedCount = 0;

        foreach ($branches as $branch) {
            if ($this->isProtectedBranch($branch)) {
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=yellow>{$this->translator->trans('branches.clean.protected', ['branch' => $branch])}</>");

                continue;
            }

            if ($this->deleteBranchWithFallback($branch, true)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /**
     * Deletes branches that exist on remote (delete local, suggest remote).
     *
     * @param array<string> $branches Branches that exist on remote
     * @param bool $quiet Whether to skip remote deletion prompts
     * @return int Number of successfully deleted branches
     */
    protected function deleteBranchesWithRemote(array $branches, bool $quiet): int
    {
        $deletedCount = 0;

        foreach ($branches as $branch) {
            if ($this->isProtectedBranch($branch)) {
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=yellow>{$this->translator->trans('branches.clean.protected', ['branch' => $branch])}</>");

                continue;
            }

            if ($this->deleteBranchWithFallback($branch, false)) {
                $deletedCount++;
                $this->handleRemoteBranchDeletion($branch, $quiet);
            }
        }

        return $deletedCount;
    }

    /**
     * Deletes a branch with force delete fallback if needed.
     *
     * @param string $branch Branch name to delete
     * @param bool $showPruningMessage Whether to show pruning message for local-only branches
     * @return bool True if branch was successfully deleted, false otherwise
     */
    protected function deleteBranchWithFallback(string $branch, bool $showPruningMessage): bool
    {
        try {
            // Verify remote state (might have changed or categorization might be based on stale refs)
            $remoteExists = $this->gitRepository->remoteBranchExists('origin', $branch);
            if ($showPruningMessage && ! $remoteExists) {
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.pruning_refs')}</>");
            }

            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.deleting', ['branch' => $branch]));
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Deleting local branch: {$branch}</>");
            $this->gitRepository->deleteBranch($branch, $remoteExists);
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted local branch: {$branch}</>");

            return true;
        } catch (\Exception $e) {
            return $this->handleDeleteFailure($branch, $e);
        }
    }

    /**
     * Handles branch deletion failure with force delete fallback.
     *
     * @param string $branch Branch name that failed to delete
     * @param \Exception $e Original exception
     * @return bool True if force delete succeeded, false otherwise
     */
    protected function handleDeleteFailure(string $branch, \Exception $e): bool
    {
        // If deletion fails and remote doesn't exist, try force delete as fallback
        $remoteExists = $this->gitRepository->remoteBranchExists('origin', $branch);
        if (! $remoteExists && str_contains($e->getMessage(), 'not fully merged')) {
            return $this->attemptForceDelete($branch);
        }

        $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.error', ['branch' => $branch, 'error' => $e->getMessage()]));
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>Deletion failed: {$e->getMessage()}</>");

        return false;
    }

    /**
     * Attempts to force delete a branch.
     *
     * @param string $branch Branch name to force delete
     * @return bool True if force delete succeeded, false otherwise
     */
    protected function attemptForceDelete(string $branch): bool
    {
        try {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.force_delete_warning', ['branch' => $branch]));
            $this->gitRepository->deleteBranchForce($branch);
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=green>Successfully force-deleted local branch: {$branch}</>");

            return true;
        } catch (\Exception $forceException) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.error', ['branch' => $branch, 'error' => $forceException->getMessage()]));
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>Force deletion also failed: {$forceException->getMessage()}</>");

            return false;
        }
    }

    /**
     * Handles remote branch deletion prompt and execution.
     *
     * @param string $branch Branch name
     * @param bool $quiet Whether to skip remote deletion prompts
     */
    protected function handleRemoteBranchDeletion(string $branch, bool $quiet): void
    {
        if ($quiet) {
            // In quiet mode, only delete local, don't prompt for remote
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.remote_kept_quiet', ['branch' => $branch])}</>");

            return;
        }

        $deleteRemote = $this->logger->confirm(
            $this->translator->trans('branches.clean.delete_remote_confirm', ['branch' => $branch]),
            false
        );

        if ($deleteRemote) {
            $this->deleteRemoteBranch($branch);
        } else {
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.remote_kept', ['branch' => $branch])}</>");
        }
    }

    /**
     * Deletes a remote branch.
     *
     * @param string $branch Branch name to delete from remote
     */
    protected function deleteRemoteBranch(string $branch): void
    {
        try {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Deleting remote branch: origin/{$branch}</>");
            $this->gitRepository->deleteRemoteBranch('origin', $branch);
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.deleted_remote', ['branch' => $branch]));
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted remote branch: origin/{$branch}</>");
        } catch (\Exception $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.error_remote', ['branch' => $branch, 'error' => $e->getMessage()]));
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>Remote deletion failed: {$e->getMessage()}</>");
        }
    }
}
