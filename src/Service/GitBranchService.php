<?php

declare(strict_types=1);

namespace App\Service;

class GitBranchService
{
    public function __construct(
        private readonly GitRepository $gitRepository
    ) {
    }

    /**
     * Finds branches matching the issue key pattern (feat/{KEY}-*, fix/{KEY}-*, chore/{KEY}-*).
     *
     * @param string $key The Jira issue key (e.g., 'PROJ-123')
     * @return array{local: array<string>, remote: array<string>} Array with 'local' and 'remote' branch arrays
     */
    public function findBranchesByIssueKey(string $key): array
    {
        $localBranches = $this->findLocalBranchesByIssueKey($key);
        $remoteBranches = $this->findRemoteBranchesByIssueKey($key);

        return [
            'local' => $localBranches,
            'remote' => $remoteBranches,
        ];
    }

    /**
     * Gets branch status compared to remote and base branch.
     *
     * @param string $branch The branch to check
     * @param string $baseBranch The base branch to compare against
     * @param string|null $remoteBranch The remote branch name (e.g., 'origin/feat/PROJ-123-title') or null
     * @return array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int}
     */
    public function getBranchStatus(string $branch, string $baseBranch, ?string $remoteBranch = null): array
    {
        $behindRemote = 0;
        $aheadRemote = 0;

        if ($remoteBranch !== null) {
            $behindRemote = $this->getBranchCommitsBehind($branch, $remoteBranch);
            $aheadRemote = $this->getBranchCommitsAhead($branch, $remoteBranch);
        }

        $behindBase = $this->getBranchCommitsBehind($branch, $baseBranch);
        $aheadBase = $this->getBranchCommitsAhead($branch, $baseBranch);

        return [
            'behind_remote' => $behindRemote,
            'ahead_remote' => $aheadRemote,
            'behind_base' => $behindBase,
            'ahead_base' => $aheadBase,
        ];
    }

    /**
     * Returns the count of commits in $branch that are not in $compareBranch.
     *
     * @param string $branch The branch to check
     * @param string $compareBranch The branch to compare against
     * @return int Number of commits ahead, or 0 if branch is not ahead
     */
    public function getBranchCommitsAhead(string $branch, string $compareBranch): int
    {
        $process = $this->gitRepository->runQuietly("git rev-list --count {$compareBranch}..{$branch}");

        if (! $process->isSuccessful()) {
            return 0;
        }

        $output = trim($process->getOutput());

        return empty($output) ? 0 : (int) $output;
    }

    /**
     * Returns the count of commits in $compareBranch that are not in $branch.
     *
     * @param string $branch The branch to check
     * @param string $compareBranch The branch to compare against
     * @return int Number of commits behind, or 0 if branch is not behind
     */
    public function getBranchCommitsBehind(string $branch, string $compareBranch): int
    {
        $process = $this->gitRepository->runQuietly("git rev-list --count {$branch}..{$compareBranch}");

        if (! $process->isSuccessful()) {
            return 0;
        }

        $output = trim($process->getOutput());

        return empty($output) ? 0 : (int) $output;
    }

    /**
     * Checks if a branch is merged into the specified base branch.
     *
     * @param string $branch The branch to check
     * @param string $baseBranch The base branch to check against
     * @return bool True if branch is merged into baseBranch, false otherwise
     */
    public function isBranchMergedInto(string $branch, string $baseBranch): bool
    {
        $process = $this->gitRepository->runQuietly("git branch --merged {$baseBranch}");
        if (! $process->isSuccessful()) {
            return false;
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return false;
        }

        $mergedBranches = array_filter(
            array_map('trim', explode("\n", $output)),
            fn (string $line) => ! empty($line)
        );

        foreach ($mergedBranches as $mergedBranch) {
            $cleanBranch = ltrim($mergedBranch, '* ');
            if ($cleanBranch === $branch) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a branch is directly based on the specified base branch.
     *
     * @param string $branch The branch to check
     * @param string $baseBranch The base branch to check against
     * @return bool True if branch is directly based on baseBranch, false otherwise
     */
    public function isBranchBasedOn(string $branch, string $baseBranch): bool
    {
        try {
            $mergeBase = $this->gitRepository->getMergeBase($baseBranch, $branch);
            $baseHead = trim($this->gitRepository->run("git rev-parse {$baseBranch}")->getOutput());

            return $mergeBase === $baseHead;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Checks whether a branch can be rebased onto another branch without conflicts.
     *
     * @param string $branch The branch to rebase
     * @param string $ontoBranch The branch to rebase onto
     * @return bool True if rebase would succeed, false otherwise
     */
    public function canRebaseBranch(string $branch, string $ontoBranch): bool
    {
        $process = $this->gitRepository->runQuietly("git merge-base --is-ancestor {$ontoBranch} {$branch}");

        if ($process->isSuccessful()) {
            return true;
        }

        $dryRunProcess = $this->gitRepository->runQuietly("git rebase --dry-run {$ontoBranch} {$branch}");

        return $dryRunProcess->isSuccessful();
    }

    /**
     * Gets all local branch names.
     *
     * @return array<string> Array of local branch names (excluding current branch marker *)
     */
    public function getAllLocalBranches(): array
    {
        $process = $this->gitRepository->runQuietly("git branch --format='%(refname:short)'");
        if (! $process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return [];
        }

        $branches = array_filter(
            array_map('trim', explode("\n", $output)),
            fn (string $branch) => ! empty($branch)
        );

        return array_values($branches);
    }

    /**
     * Gets all remote branch names.
     *
     * @param string $remote The remote name (default: 'origin')
     * @return array<string> Array of remote branch names (without remote prefix)
     */
    public function getAllRemoteBranches(string $remote = 'origin'): array
    {
        $process = $this->gitRepository->runQuietly("git ls-remote --heads {$remote}");
        if (! $process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return [];
        }

        return $this->parseRemoteBranchRefs($output);
    }

    /**
     * Renames a local branch.
     *
     * @param string $oldName The current branch name
     * @param string $newName The new branch name
     */
    public function renameLocalBranch(string $oldName, string $newName): void
    {
        $currentBranch = $this->gitRepository->getCurrentBranchName();
        if ($oldName === $currentBranch) {
            $this->gitRepository->run("git branch -m {$newName}");
        } else {
            $this->gitRepository->run("git branch -m {$oldName} {$newName}");
        }
    }

    /**
     * Renames a remote branch by pushing the local branch as the new name on remote,
     * setting upstream, and deleting the old remote branch.
     *
     * @param string $oldName The current remote branch name
     * @param string $newName The new remote branch name
     * @param string $remote The remote name (default: 'origin')
     */
    public function renameRemoteBranch(string $oldName, string $newName, string $remote = 'origin'): void
    {
        $localBranch = $this->gitRepository->localBranchExists($oldName) ? $oldName : $newName;

        $this->gitRepository->run("git push {$remote} {$localBranch}:{$newName}");
        $this->gitRepository->run("git push {$remote} -u {$localBranch}:{$newName}");
        $this->gitRepository->run("git push {$remote} --delete {$oldName}");
        $this->gitRepository->run("git branch --set-upstream-to={$remote}/{$newName} {$localBranch}");
    }

    /**
     * Finds local branches matching the issue key pattern.
     *
     * @param string $key The Jira issue key
     * @return array<string> Array of local branch names
     */
    protected function findLocalBranchesByIssueKey(string $key): array
    {
        $prefixes = ['feat', 'fix', 'chore'];
        $branches = [];

        foreach ($prefixes as $prefix) {
            $process = $this->gitRepository->runQuietly("git branch --list '{$prefix}/{$key}-*'");
            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                if (! empty($output)) {
                    $foundBranches = array_filter(
                        array_map('trim', explode("\n", $output)),
                        fn (string $branch) => ! empty($branch)
                    );
                    $branches = array_merge($branches, $foundBranches);
                }
            }
        }

        return $branches;
    }

    /**
     * Finds remote branches matching the issue key pattern.
     *
     * @param string $key The Jira issue key
     * @param string $remote The remote name (default: 'origin')
     * @return array<string> Array of remote branch names (without remote prefix)
     */
    protected function findRemoteBranchesByIssueKey(string $key, string $remote = 'origin'): array
    {
        $prefixes = ['feat', 'fix', 'chore'];
        $branches = [];

        foreach ($prefixes as $prefix) {
            $found = $this->queryRemoteBranchesByPrefix($prefix, $key, $remote);
            $branches = array_merge($branches, $found);
        }

        return $branches;
    }

    /**
     * @return array<string>
     */
    protected function queryRemoteBranchesByPrefix(string $prefix, string $key, string $remote): array
    {
        $process = $this->gitRepository->runQuietly(
            "git ls-remote --heads {$remote} 'refs/heads/{$prefix}/{$key}-*'"
        );

        if (! $process->isSuccessful()) {
            return [];
        }

        return $this->parseRemoteBranchRefs(trim($process->getOutput()));
    }

    /**
     * Resolves the most advanced ref between local and remote counterparts of a base branch.
     *
     * Given a configured baseBranch (e.g. "origin/develop" or "develop"), derives both
     * the local and remote tracking refs, checks which exist, and returns whichever
     * is more advanced (has more recent commits). If branches have diverged, prefers remote.
     *
     * @param string $baseBranch The configured base branch (may or may not have "origin/" prefix)
     * @return string The ref to use as the actual starting point for new branches
     */
    public function resolveLatestBaseBranch(string $baseBranch): string
    {
        [$localRef, $remoteRef] = $this->deriveLocalAndRemoteRefs($baseBranch);

        $localExists = $this->refExists($localRef);
        $remoteExists = $this->refExists($remoteRef);

        if ($localExists && ! $remoteExists) {
            return $localRef;
        }
        if (! $localExists) {
            return $remoteExists ? $remoteRef : $baseBranch;
        }

        return $this->pickMoreAdvancedRef($localRef, $remoteRef, $baseBranch);
    }

    /**
     * Switches to an existing local branch.
     *
     * @param string $branchName The branch name to switch to
     * @throws \RuntimeException If branch doesn't exist locally
     */
    public function switchBranch(string $branchName): void
    {
        $this->gitRepository->run("git switch {$branchName}");
    }

    /**
     * Creates a local tracking branch from a remote branch.
     *
     * @param string $branchName The branch name (without remote prefix)
     * @param string $remote The remote name (default: 'origin')
     * @throws \RuntimeException If remote branch doesn't exist
     */
    public function switchToRemoteBranch(string $branchName, string $remote = 'origin'): void
    {
        $this->gitRepository->run("git switch -c {$branchName} {$remote}/{$branchName}");
    }

    /**
     * Derives local and remote ref names from a configured base branch.
     *
     * @return array{0: string, 1: string} [localRef, remoteRef]
     */
    protected function deriveLocalAndRemoteRefs(string $baseBranch): array
    {
        if (str_starts_with($baseBranch, 'origin/')) {
            return [substr($baseBranch, 7), $baseBranch];
        }

        return [$baseBranch, 'origin/' . $baseBranch];
    }

    /**
     * Checks whether a git ref (branch, tag, remote tracking ref) exists locally.
     */
    protected function refExists(string $ref): bool
    {
        return $this->gitRepository->runQuietly("git rev-parse --verify --quiet {$ref}")->isSuccessful();
    }

    /**
     * Compares two existing refs and returns whichever is more advanced.
     * If diverged, prefers remote. If identical, returns the original configured value.
     */
    protected function pickMoreAdvancedRef(string $localRef, string $remoteRef, string $baseBranch): string
    {
        $localIsAncestor = $this->gitRepository->runQuietly("git merge-base --is-ancestor {$localRef} {$remoteRef}")->isSuccessful();
        $remoteIsAncestor = $this->gitRepository->runQuietly("git merge-base --is-ancestor {$remoteRef} {$localRef}")->isSuccessful();

        if ($localIsAncestor && $remoteIsAncestor) {
            return $baseBranch;
        }
        if ($localIsAncestor) {
            return $remoteRef;
        }

        return $remoteIsAncestor ? $localRef : $remoteRef;
    }

    /**
     * Parses git ls-remote output lines into branch names.
     *
     * @return array<string>
     */
    protected function parseRemoteBranchRefs(string $output): array
    {
        if (empty($output)) {
            return [];
        }

        $branches = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('#refs/heads/(.+)$#', $line, $matches)) {
                $branches[] = $matches[1];
            }
        }

        return $branches;
    }
}
