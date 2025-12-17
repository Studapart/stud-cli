<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

class GitRepository
{
    public function __construct(private readonly ProcessFactory $processFactory)
    {
    }

    public function getJiraKeyFromBranchName(): ?string
    {
        $process = $this->processFactory->create('git rev-parse --abbrev-ref HEAD');
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());
        preg_match('/(?i)([a-z]+-\d+)/', $branch, $matches);

        return isset($matches[1]) ? strtoupper($matches[1]) : null;
    }

    public function getUpstreamBranch(): ?string
    {
        $process = $this->runQuietly('git rev-parse --abbrev-ref @{u} 2>/dev/null');

        if (! $process->isSuccessful()) {
            return null;
        }

        $upstream = trim($process->getOutput());

        return empty($upstream) ? null : $upstream;
    }

    public function forcePushWithLease(): Process
    {
        return $this->run('git push --force-with-lease');
    }

    public function forcePushWithLeaseRemote(string $remote, string $branch): Process
    {
        return $this->run("git push --force-with-lease {$remote} {$branch}");
    }

    public function checkout(string $branch): void
    {
        $this->run("git checkout {$branch}");
    }

    public function pull(string $remote, string $branch): void
    {
        $this->run("git pull {$remote} {$branch}");
    }

    public function pullWithRebase(string $remote, string $branch): void
    {
        $this->run("git pull --rebase {$remote} {$branch}");
    }

    public function merge(string $branch): void
    {
        $this->run("git merge --no-ff {$branch}");
    }

    public function tag(string $tagName, string $message): void
    {
        $this->run("git tag -a {$tagName} -m '{$message}'");
    }

    public function pushTags(string $remote): void
    {
        $this->run("git push --tags {$remote} main");
    }

    public function rebase(string $branch): void
    {
        $this->run("git rebase {$branch}");
    }

    public function hasFixupCommits(string $baseSha): bool
    {
        $process = $this->runQuietly(
            "git log {$baseSha}..HEAD --format=%s --grep='^fixup!' --grep='^squash!'"
        );

        if (! $process->isSuccessful()) {
            return false;
        }

        $output = trim($process->getOutput());

        return ! empty($output);
    }

    public function rebaseAutosquash(string $baseSha): void
    {
        // Create a temporary script that processes the rebase plan
        // Since --autosquash already reorders commits, we just need to change
        // 'pick' to 'fixup' or 'squash' for commits with those prefixes
        $scriptContent = <<<'SCRIPT'
#!/bin/sh
# Process the rebase plan file passed as $1
# Change 'pick' to 'fixup' for fixup! commits and 'squash' for squash! commits
sed -i.bak -E '
    /^pick [a-f0-9]+ fixup!/ {
        s/^pick/fixup/
    }
    /^pick [a-f0-9]+ squash!/ {
        s/^pick/squash/
    }
' "$1"
SCRIPT;

        $tempScript = tempnam(sys_get_temp_dir(), 'stud-rebase-');
        // @codeCoverageIgnoreStart
        // tempnam() failure is extremely rare and difficult to simulate in tests
        if ($tempScript === false) {
            throw new \RuntimeException('Failed to create temporary script file');
        }
        // @codeCoverageIgnoreEnd

        file_put_contents($tempScript, $scriptContent);
        chmod($tempScript, 0755);

        try {
            // Set GIT_SEQUENCE_EDITOR to our script and run rebase
            $env = $_ENV;
            $env['GIT_SEQUENCE_EDITOR'] = $tempScript;

            $process = $this->processFactory->create("git rebase -i --autosquash {$baseSha}");
            $process->setEnv($env);
            $process->mustRun();
        } finally {
            @unlink($tempScript);
            $backupFile = $tempScript . '.bak';
            if (file_exists($backupFile)) {
                @unlink($backupFile);
            }
        }
    }

    public function deleteBranch(string $branch): void
    {
        $this->run("git branch -d {$branch}");
    }

    public function deleteRemoteBranch(string $remote, string $branch): void
    {
        $this->run("git push {$remote} --delete {$branch}");
    }

    public function findLatestLogicalSha(string $baseBranch): ?string
    {
        $process = $this->runQuietly(
            'git log ' . $baseBranch . '..HEAD --format=%H --grep="^fixup!" --grep="^squash!" --invert-grep --max-count=1'
        );

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return null;
        }

        return $output;
    }

    public function stageAllChanges(): void
    {
        $this->run('git add -A');
    }

    public function commitFixup(string $sha): void
    {
        $this->run("git commit --fixup {$sha}");
    }

    public function commit(string $message): void
    {
        $this->run('git commit -m ' . escapeshellarg($message));
    }

    public function fetch(): void
    {
        $this->run('git fetch origin');
    }

    public function createBranch(string $branchName, string $baseBranch): void
    {
        $this->run("git switch -c {$branchName} " . $baseBranch);
    }

    /**
     * @param array<string> $files
     */
    public function add(array $files): void
    {
        $this->run('git add ' . implode(' ', $files));
    }

    public function getPorcelainStatus(): string
    {
        return $this->run('git status --porcelain')->getOutput();
    }

    public function getCurrentBranchName(): string
    {
        return trim($this->run('git rev-parse --abbrev-ref HEAD')->getOutput());
    }

    public function pushToOrigin(string $branchName): Process
    {
        return $this->runQuietly("git push --set-upstream origin {$branchName}");
    }

    public function getMergeBase(string $baseBranch, string $head): string
    {
        return trim($this->run("git merge-base {$baseBranch} {$head}")->getOutput());
    }

    public function findFirstLogicalSha(string $ancestorSha): ?string
    {
        $process = $this->runQuietly(
            "git rev-list --reverse {$ancestorSha}..HEAD | grep -v -E '^ (fixup|squash)!' | head -n 1"
        );

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return null;
        }

        return $output;
    }

    public function getCommitMessage(string $sha): string
    {
        return trim($this->run("git log -1 --pretty=%B {$sha}")->getOutput());
    }

    public function localBranchExists(string $branchName): bool
    {
        $process = $this->runQuietly("git rev-parse --verify --quiet {$branchName}");

        return $process->isSuccessful();
    }

    public function remoteBranchExists(string $remote, string $branchName): bool
    {
        $process = $this->runQuietly("git ls-remote --heads {$remote} {$branchName}");

        return ! empty(trim($process->getOutput()));
    }

    public function getRepositoryOwner(string $remote = 'origin'): ?string
    {
        $parsed = $this->parseGithubUrl($remote);

        return $parsed['owner'] ?? null;
    }

    public function getRepositoryName(string $remote = 'origin'): ?string
    {
        $parsed = $this->parseGithubUrl($remote);

        return $parsed['name'] ?? null;
    }

    /**
     * Parses GitHub repository owner and name from a remote URL.
     *
     * @param string $remote The remote name (default: 'origin')
     * @return array{owner?: string, name?: string} Array with 'owner' and 'name' keys, or empty array if parsing fails
     */
    protected function parseGithubUrl(string $remote = 'origin'): array
    {
        $remoteUrl = $this->getRemoteUrl($remote);

        if (! $remoteUrl) {
            return [];
        }

        // Parse owner and name from different Git URL formats
        // SSH format: git@github.com:owner/repo.git
        // HTTPS format: https://github.com/owner/repo.git
        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
            ];
        }

        return [];
    }

    protected function getRemoteUrl(string $remote = 'origin'): ?string
    {
        $process = $this->runQuietly("git config --get remote.{$remote}.url");

        if (! $process->isSuccessful()) {
            return null;
        }

        // Trim whitespace and any trailing dots/periods that might be present
        $remoteUrl = trim($process->getOutput());
        $remoteUrl = rtrim($remoteUrl, '.');

        return empty($remoteUrl) ? null : $remoteUrl;
    }

    public function run(string $command): Process
    {
        $process = $this->processFactory->create($command);
        $process->mustRun();

        return $process;
    }

    public function runQuietly(string $command): Process
    {
        $process = $this->processFactory->create($command);
        $process->run();

        return $process;
    }

    /**
     * Gets the path to the project-specific config file (.git/stud.config).
     */
    public function getProjectConfigPath(): string
    {
        $process = $this->runQuietly('git rev-parse --git-dir');
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Not in a git repository.');
        }
        $gitDir = trim($process->getOutput());

        return rtrim($gitDir, '/') . '/stud.config';
    }

    /**
     * Reads the project-specific config file.
     * Returns an empty array if the file doesn't exist.
     *
     * @return array{projectKey?: string, transitionId?: int}
     */
    public function readProjectConfig(): array
    {
        $configPath = $this->getProjectConfigPath();
        if (! file_exists($configPath)) {
            return [];
        }

        $content = @file_get_contents($configPath);
        if ($content === false) {
            return [];
        }

        $config = \Symfony\Component\Yaml\Yaml::parse($content);

        return is_array($config) ? $config : [];
    }

    /**
     * Writes the project-specific config file.
     *
     * @param array{projectKey?: string, transitionId?: int} $config
     */
    public function writeProjectConfig(array $config): void
    {
        $configPath = $this->getProjectConfigPath();
        $configDir = dirname($configPath);

        if (! is_dir($configDir)) {
            throw new \RuntimeException("Git directory not found: {$configDir}");
        }

        $yaml = \Symfony\Component\Yaml\Yaml::dump($config);
        file_put_contents($configPath, $yaml);
    }

    /**
     * Gets the project key from a Jira issue key (e.g., "PROJ-123" -> "PROJ").
     */
    public function getProjectKeyFromIssueKey(string $issueKey): string
    {
        if (preg_match('/^([A-Z]+)-\d+$/', strtoupper($issueKey), $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException("Invalid Jira issue key format: {$issueKey}");
    }

    /**
     * Renames a local branch.
     *
     * @param string $oldName The current branch name
     * @param string $newName The new branch name
     */
    public function renameLocalBranch(string $oldName, string $newName): void
    {
        $currentBranch = $this->getCurrentBranchName();
        if ($oldName === $currentBranch) {
            // Renaming current branch
            $this->run("git branch -m {$newName}");
        } else {
            // Renaming a different branch
            $this->run("git branch -m {$oldName} {$newName}");
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
        // Determine which local branch to push from
        // If local branch was already renamed, it's now $newName, otherwise it's still $oldName
        $localBranch = $this->localBranchExists($oldName) ? $oldName : $newName;

        // Push local branch to remote with new name and set upstream
        // Format: git push origin localBranch:newName
        // This pushes the local branch to the remote as 'newName'
        $this->run("git push {$remote} {$localBranch}:{$newName}");
        $this->run("git push {$remote} -u {$localBranch}:{$newName}");
        // Delete old remote branch
        $this->run("git push {$remote} --delete {$oldName}");
        // Update local tracking branch
        $this->run("git branch --set-upstream-to={$remote}/{$newName} {$localBranch}");
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
        $process = $this->runQuietly("git rev-list --count {$compareBranch}..{$branch}");

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
        $process = $this->runQuietly("git rev-list --count {$branch}..{$compareBranch}");

        if (! $process->isSuccessful()) {
            return 0;
        }

        $output = trim($process->getOutput());

        return empty($output) ? 0 : (int) $output;
    }

    /**
     * Checks if a branch can be rebased onto another branch without conflicts.
     *
     * @param string $branch The branch to rebase
     * @param string $ontoBranch The branch to rebase onto
     * @return bool True if rebase would succeed, false otherwise
     */
    public function canRebaseBranch(string $branch, string $ontoBranch): bool
    {
        // Use merge-base to check if ontoBranch is an ancestor of branch
        // If it is, rebase should be safe
        $process = $this->runQuietly("git merge-base --is-ancestor {$ontoBranch} {$branch}");

        if ($process->isSuccessful()) {
            return true;
        }

        // If merge-base check fails, try a dry-run rebase
        $dryRunProcess = $this->runQuietly("git rebase --dry-run {$ontoBranch} {$branch}");

        return $dryRunProcess->isSuccessful();
    }

    /**
     * Switches to an existing local branch.
     *
     * @param string $branchName The branch name to switch to
     * @throws \RuntimeException If branch doesn't exist locally
     */
    public function switchBranch(string $branchName): void
    {
        $this->run("git switch {$branchName}");
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
        $this->run("git switch -c {$branchName} {$remote}/{$branchName}");
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
     * Checks if a branch is directly based on the specified base branch.
     *
     * @param string $branch The branch to check
     * @param string $baseBranch The base branch to check against
     * @return bool True if branch is directly based on baseBranch, false otherwise
     */
    public function isBranchBasedOn(string $branch, string $baseBranch): bool
    {
        try {
            $mergeBase = $this->getMergeBase($baseBranch, $branch);
            $baseHead = trim($this->run("git rev-parse {$baseBranch}")->getOutput());

            return $mergeBase === $baseHead;
        } catch (\Exception $e) {
            return false;
        }
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
            $process = $this->runQuietly("git branch --list '{$prefix}/{$key}-*'");
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
            $process = $this->runQuietly("git ls-remote --heads {$remote} 'refs/heads/{$prefix}/{$key}-*'");
            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                if (! empty($output)) {
                    $lines = explode("\n", $output);
                    foreach ($lines as $line) {
                        if (preg_match('#refs/heads/(.+)$#', $line, $matches)) {
                            $branches[] = $matches[1];
                        }
                    }
                }
            }
        }

        return $branches;
    }
}
