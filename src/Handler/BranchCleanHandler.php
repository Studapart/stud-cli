<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GithubProvider;
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
        private readonly ?GithubProvider $githubProvider,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, bool $quiet = false): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.section'));

        $this->logger->note(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.note_origin')}</>");

        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.scanning'));
        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.fetching_local')}</>");

        $result = $this->findBranchesToClean();
        $branchesToCleanLocal = $result['local_only'];
        $branchesToCleanRemote = $result['with_remote'];
        $currentBranchSkipped = $result['current_branch_skipped'];

        $totalBranches = count($branchesToCleanLocal) + count($branchesToCleanRemote);

        if ($totalBranches === 0 && ! $currentBranchSkipped) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.none'));

            return 0;
        }

        // Notify about current branch if it was skipped
        if ($currentBranchSkipped) {
            $currentBranch = $this->gitRepository->getCurrentBranchName();
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.current_branch_skipped', ['branch' => $currentBranch]));
        }

        if ($totalBranches === 0) {
            return 0;
        }

        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.found', ['count' => $totalBranches]));

        // Display local-only branches
        if (! empty($branchesToCleanLocal)) {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.local_only_header', ['count' => count($branchesToCleanLocal)]));
            $this->displayBranchesList($branchesToCleanLocal);
        }

        // Display branches with remote (suggest deleting both)
        if (! empty($branchesToCleanRemote)) {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.with_remote_header', ['count' => count($branchesToCleanRemote)]));
            $this->displayBranchesList($branchesToCleanRemote);
            if (! $quiet) {
                $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.with_remote_note'));
            }
        }

        if (! $quiet) {
            $confirmed = $this->logger->confirm(
                $this->translator->trans('branches.clean.confirm', ['count' => $totalBranches]),
                true
            );

            if (! $confirmed) {
                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.cancelled'));

                return 0;
            }
        }

        $deletedCount = $this->deleteBranches($branchesToCleanLocal, $branchesToCleanRemote, $quiet);

        if ($deletedCount > 0) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.deleted', ['count' => $deletedCount]));
        }

        return 0;
    }

    /**
     * Finds branches that are safe to clean (merged, not protected, not current).
     *
     * @return array{local_only: array<string>, with_remote: array<string>, current_branch_skipped: bool}
     */
    protected function findBranchesToClean(): array
    {
        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.fetching_local')}</>");
        $allBranches = $this->gitRepository->getAllLocalBranches();
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($allBranches) . " local branches</>");

        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.fetching_remote')}</>");
        $remoteBranches = $this->gitRepository->getAllRemoteBranches('origin');
        $remoteBranchesSet = array_flip($remoteBranches);
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($remoteBranches) . " remote branches on origin</>");

        $currentBranch = $this->gitRepository->getCurrentBranchName();
        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Current branch: {$currentBranch}</>");

        $branchesToCleanLocal = [];
        $branchesToCleanRemote = [];
        $currentBranchSkipped = false;

        foreach ($allBranches as $branch) {
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Evaluating branch: {$branch}</>");

            if ($this->isProtectedBranch($branch)) {
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=yellow>Protected branch, skipping</>");

                continue;
            }

            if ($branch === $currentBranch) {
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=yellow>Current branch, will skip</>");
                $currentBranchSkipped = true;

                continue;
            }

            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Checking if merged into develop...</>");

            try {
                $isMerged = $this->gitRepository->isBranchMergedInto($branch, 'develop');
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Merged into develop: " . ($isMerged ? 'yes' : 'no') . "</>");
            } catch (\Exception $e) {
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=yellow>Warning: Could not check merge status for {$branch}: {$e->getMessage()}</>");
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=red>Exception: {$e->getMessage()}</>");

                continue;
            }

            if (! $isMerged) {
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Not merged, skipping</>");

                continue;
            }

            $remoteExists = isset($remoteBranchesSet[$branch]);
            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Exists on remote: " . ($remoteExists ? 'yes' : 'no') . "</>");

            // Check PR status
            $hasOpenPr = false;
            if ($this->githubProvider !== null) {
                try {
                    $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>Checking PR status...</>");
                    $pr = $this->githubProvider->findPullRequestByBranchName($branch, 'all');
                    if ($pr !== null) {
                        $prState = $pr['state'] ?? 'unknown';
                        $prNumber = $pr['number'] ?? '?';
                        $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=gray>PR #{$prNumber} found (state: {$prState})</>");
                        if ($prState === 'open') {
                            $hasOpenPr = true;
                            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=yellow>Open PR found, skipping deletion</>");
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Warning: Could not check PR for {$branch}: {$e->getMessage()}</>");
                    $this->logger->writeln(Logger::VERBOSITY_DEBUG, "      <fg=red>PR check exception: {$e->getMessage()}</>");
                    // Continue with merge-based logic if PR check fails
                }
            }

            if ($hasOpenPr) {
                continue;
            }

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

        // Delete local-only branches
        foreach ($localOnlyBranches as $branch) {
            if ($this->isProtectedBranch($branch)) {
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=yellow>{$this->translator->trans('branches.clean.protected', ['branch' => $branch])}</>");

                continue;
            }

            try {
                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.deleting', ['branch' => $branch]));
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Deleting local branch: {$branch}</>");
                $this->gitRepository->deleteBranch($branch);
                $deletedCount++;
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted local branch: {$branch}</>");
            } catch (\Exception $e) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.error', ['branch' => $branch, 'error' => $e->getMessage()]));
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>Deletion failed: {$e->getMessage()}</>");
            }
        }

        // Handle branches that exist on remote
        foreach ($withRemoteBranches as $branch) {
            if ($this->isProtectedBranch($branch)) {
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=yellow>{$this->translator->trans('branches.clean.protected', ['branch' => $branch])}</>");

                continue;
            }

            try {
                // Delete local branch
                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.deleting', ['branch' => $branch]));
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Deleting local branch: {$branch}</>");
                $this->gitRepository->deleteBranch($branch);
                $deletedCount++;
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted local branch: {$branch}</>");

                // Suggest deleting remote branch
                if (! $quiet) {
                    $deleteRemote = $this->logger->confirm(
                        $this->translator->trans('branches.clean.delete_remote_confirm', ['branch' => $branch]),
                        false
                    );

                    if ($deleteRemote) {
                        try {
                            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=gray>Deleting remote branch: origin/{$branch}</>");
                            $this->gitRepository->deleteRemoteBranch('origin', $branch);
                            $this->logger->writeln(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.deleted_remote', ['branch' => $branch]));
                            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted remote branch: origin/{$branch}</>");
                        } catch (\Exception $e) {
                            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.error_remote', ['branch' => $branch, 'error' => $e->getMessage()]));
                            $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>Remote deletion failed: {$e->getMessage()}</>");
                        }
                    } else {
                        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.remote_kept', ['branch' => $branch])}</>");
                    }
                } else {
                    // In quiet mode, only delete local, don't prompt for remote
                    $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('branches.clean.remote_kept_quiet', ['branch' => $branch])}</>");
                }
            } catch (\Exception $e) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.error', ['branch' => $branch, 'error' => $e->getMessage()]));
                $this->logger->writeln(Logger::VERBOSITY_DEBUG, "    <fg=red>Deletion failed: {$e->getMessage()}</>");
            }
        }

        return $deletedCount;
    }
}
