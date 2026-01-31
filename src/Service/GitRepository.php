<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\GitException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class GitRepository
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly ?FileSystem $fileSystem = null
    ) {
    }

    private function getFileSystem(): FileSystem
    {
        return $this->fileSystem ?? FileSystem::createLocal();
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

        $fileSystem = $this->getFileSystem();
        $fileSystem->write($tempScript, $scriptContent);
        $fileSystem->chmod($tempScript, 0755);

        try {
            // Set GIT_SEQUENCE_EDITOR to our script and run rebase
            $env = $_ENV;
            $env['GIT_SEQUENCE_EDITOR'] = $tempScript;

            $process = $this->processFactory->create("git rebase -i --autosquash {$baseSha}");
            $process->setEnv($env);
            $process->mustRun();
        } finally {
            $fileSystem->delete($tempScript);
            $backupFile = $tempScript . '.bak';
            if ($fileSystem->fileExists($backupFile)) {
                $fileSystem->delete($backupFile);
            }
        }
    }

    public function deleteBranch(string $branch, ?bool $remoteExists = null): void
    {
        // If remote state is explicitly false (remote doesn't exist), prune stale refs first
        if ($remoteExists === false) {
            $this->pruneRemoteTrackingRefs();
        }

        $this->run("git branch -d {$branch}");
    }

    public function deleteBranchForce(string $branch): Process
    {
        return $this->run("git branch -D {$branch}");
    }

    /**
     * Prunes stale remote-tracking references for the specified remote.
     * This removes local refs for branches that no longer exist on the remote.
     *
     * @param string $remote The remote name (default: 'origin')
     */
    public function pruneRemoteTrackingRefs(string $remote = 'origin'): void
    {
        $this->run("git fetch --prune {$remote}");
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
        $parsed = $this->parseGitUrl($remote);

        return $parsed['owner'] ?? null;
    }

    public function getRepositoryName(string $remote = 'origin'): ?string
    {
        $parsed = $this->parseGitUrl($remote);

        return $parsed['name'] ?? null;
    }

    /**
     * Parses repository owner and name from a remote URL (supports GitHub and GitLab).
     *
     * @param string $remote The remote name (default: 'origin')
     * @return array{owner?: string, name?: string, provider?: string} Array with 'owner', 'name', and 'provider' keys, or empty array if parsing fails
     */
    protected function parseGitUrl(string $remote = 'origin'): array
    {
        $remoteUrl = $this->getRemoteUrl($remote);

        if (! $remoteUrl) {
            return [];
        }

        // Parse GitHub URLs
        // SSH format: git@github.com:owner/repo.git
        // HTTPS format: https://github.com/owner/repo.git
        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
                'provider' => 'github',
            ];
        }

        // Parse GitLab URLs (supports nested groups)
        // SSH format: git@gitlab.com:owner/repo.git or git@gitlab.com:group/subgroup/repo.git
        // HTTPS format: https://gitlab.com/owner/repo.git or https://gitlab.com/group/subgroup/repo.git
        // Custom instance: https://git.example.com/owner/repo.git or https://git.example.com/group/subgroup/repo.git
        // For nested groups, capture all path segments except the last one as owner
        if (preg_match('#gitlab\.com[:/](.+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
                'provider' => 'gitlab',
            ];
        }

        // Parse custom GitLab instance URLs (e.g., self-hosted, supports nested groups)
        // Pattern: https://git.example.com/owner/repo.git or git@git.example.com:owner/repo.git
        // Pattern: https://git.example.com/group/subgroup/repo.git or git@git.example.com:group/subgroup/repo.git
        // This is a fallback that matches any host that isn't github.com
        // We check for the common GitLab URL structure: host/path/to/repo
        // For nested groups, capture all path segments except the last one as owner
        if (preg_match('#(?:git@|https?://)([^/:]+)[:/](.+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches)) {
            $host = $matches[1];
            // Only treat as GitLab if it's not github.com (already handled above)
            if ($host !== 'github.com') {
                return [
                    'owner' => $matches[2],
                    'name' => $matches[3],
                    'provider' => 'gitlab',
                ];
            }
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

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            $technicalDetails = trim($errorOutput) ?: 'Command failed with no error output';

            throw new GitException(
                "Git command failed: {$command}",
                $technicalDetails,
                $e
            );
        }

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
     * @return array{projectKey?: string, transitionId?: int, baseBranch?: string, gitProvider?: string, githubToken?: string, gitlabToken?: string, gitlabInstanceUrl?: string, migration_version?: string}
     */
    public function readProjectConfig(): array
    {
        $configPath = $this->getProjectConfigPath();
        $fileSystem = $this->getFileSystem();

        if (! $fileSystem->fileExists($configPath)) {
            return [];
        }

        try {
            $config = $fileSystem->parseFile($configPath);

            return $config;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Writes the project-specific config file.
     * Preserves migration_version if it exists in the current config.
     *
     * @param array{projectKey?: string, transitionId?: int, baseBranch?: string, gitProvider?: string, githubToken?: string, gitlabToken?: string, gitlabInstanceUrl?: string, migration_version?: string} $config
     */
    public function writeProjectConfig(array $config): void
    {
        $configPath = $this->getProjectConfigPath();
        $configDir = dirname($configPath);
        $fileSystem = $this->getFileSystem();

        if (! $fileSystem->isDir($configDir)) {
            throw new \RuntimeException("Git directory not found: {$configDir}");
        }

        // Preserve migration_version if it exists in current config
        if ($fileSystem->fileExists($configPath)) {
            $existingConfig = $this->readProjectConfig();
            if (isset($existingConfig['migration_version'])) {
                $config['migration_version'] = $existingConfig['migration_version'];
            }
        }

        $fileSystem->dumpFile($configPath, $config);
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

    /**
     * Gets all local branch names.
     *
     * @return array<string> Array of local branch names (excluding current branch marker *)
     */
    public function getAllLocalBranches(): array
    {
        $process = $this->runQuietly("git branch --format='%(refname:short)'");
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
     * Checks if a branch is merged into the specified base branch.
     *
     * @param string $branch The branch to check
     * @param string $baseBranch The base branch to check against
     * @return bool True if branch is merged into baseBranch, false otherwise
     */
    public function isBranchMergedInto(string $branch, string $baseBranch): bool
    {
        $process = $this->runQuietly("git branch --merged {$baseBranch}");
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
            // Remove leading * marker if present
            $cleanBranch = ltrim($mergedBranch, '* ');
            if ($cleanBranch === $branch) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets all remote branch names.
     *
     * @param string $remote The remote name (default: 'origin')
     * @return array<string> Array of remote branch names (without remote prefix)
     */
    public function getAllRemoteBranches(string $remote = 'origin'): array
    {
        $process = $this->runQuietly("git ls-remote --heads {$remote}");
        if (! $process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return [];
        }

        $branches = [];
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('#refs/heads/(.+)$#', $line, $matches)) {
                $branches[] = $matches[1];
            }
        }

        return $branches;
    }

    /**
     * Auto-detects the most likely base branch from remote branches.
     * Checks branches in priority order: develop, main, master, dev, trunk.
     *
     * @return string|null The detected base branch name (without origin/ prefix), or null if none found
     */
    protected function detectBaseBranch(): ?string
    {
        $candidates = ['develop', 'main', 'master', 'dev', 'trunk'];
        $remoteBranches = $this->getAllRemoteBranches('origin');
        $remoteBranchesSet = array_flip($remoteBranches);

        foreach ($candidates as $candidate) {
            if (isset($remoteBranchesSet[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Gets the configured base branch from project config.
     * Returns the branch name with 'origin/' prefix for consistency with git commands.
     *
     * @return string The base branch name with 'origin/' prefix
     * @throws \RuntimeException If base branch is not configured and cannot be auto-detected
     */
    protected function getBaseBranch(): string
    {
        $config = $this->readProjectConfig();
        $baseBranchValue = $config['baseBranch'] ?? null;
        if ($baseBranchValue !== null && is_string($baseBranchValue) && trim($baseBranchValue) !== '') {
            $baseBranch = $baseBranchValue;
            // Ensure origin/ prefix for consistency
            if (! str_starts_with($baseBranch, 'origin/')) {
                return 'origin/' . $baseBranch;
            }

            return $baseBranch;
        }

        // Try auto-detection
        $detected = $this->detectBaseBranch();
        if ($detected !== null) {
            return 'origin/' . $detected;
        }

        throw new \RuntimeException('Base branch not configured and could not be auto-detected.');
    }

    /**
     * Ensures the base branch is configured in project config.
     * If not configured, attempts auto-detection and prompts user if needed.
     * Validates that the configured branch exists on remote before saving.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io The Symfony IO instance
     * @param \App\Service\Logger $logger The logger instance
     * @param \App\Service\TranslationService $translator The translation service
     * @return string The base branch name with 'origin/' prefix
     * @throws \RuntimeException If not in a git repository or if base branch validation fails
     */
    public function ensureBaseBranchConfigured(
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        \App\Service\Logger $logger,
        \App\Service\TranslationService $translator
    ): string {
        // Check if we're in a git repository
        try {
            $this->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($translator->trans('config.base_branch_required'));
        }

        $config = $this->readProjectConfig();
        $baseBranch = $config['baseBranch'] ?? null;

        // If configured, validate it exists on remote
        if ($baseBranch !== null && is_string($baseBranch) && ! empty($baseBranch)) {
            // Remove origin/ prefix if present for validation
            $branchName = str_replace('origin/', '', $baseBranch);
            if ($this->remoteBranchExists('origin', $branchName)) {
                // Return with origin/ prefix for consistency
                if (! str_starts_with($baseBranch, 'origin/')) {
                    return 'origin/' . $baseBranch;
                }

                return $baseBranch;
            }

            // Configured branch doesn't exist on remote, need to reconfigure
            $logger->warning(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $translator->trans('config.base_branch_invalid', ['branch' => $branchName])
            );
        }

        // Try auto-detection
        $detected = $this->detectBaseBranch();
        $defaultSuggestion = $detected ?? 'develop';

        if ($detected !== null) {
            $logger->note(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $translator->trans('config.base_branch_detected', ['branch' => $detected])
            );
        }

        // Prompt user for base branch
        $logger->note(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.base_branch_not_configured')
        );

        $enteredBranch = $logger->ask(
            $translator->trans('config.base_branch_prompt'),
            $defaultSuggestion,
            function (?string $value): string {
                if (empty(trim($value ?? ''))) {
                    throw new \RuntimeException('Base branch name cannot be empty.');
                }

                return trim($value);
            }
        );

        if ($enteredBranch === null || empty(trim($enteredBranch))) {
            throw new \RuntimeException($translator->trans('config.base_branch_required'));
        }

        $enteredBranch = trim($enteredBranch);

        // Validate branch exists on remote
        if (! $this->remoteBranchExists('origin', $enteredBranch)) {
            throw new \RuntimeException(
                $translator->trans('config.base_branch_invalid', ['branch' => $enteredBranch])
            );
        }

        // Save to config
        $logger->text(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.base_branch_saving')
        );

        $config['baseBranch'] = $enteredBranch;
        $this->writeProjectConfig($config);

        $logger->success(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.base_branch_saved', ['branch' => $enteredBranch])
        );

        return 'origin/' . $enteredBranch;
    }

    /**
     * Gets the configured git provider from project config.
     * Attempts auto-detection from remote URL if not configured.
     *
     * @return string|null The provider type ('github' or 'gitlab'), or null if not configured and cannot be detected
     */
    public function getGitProvider(): ?string
    {
        $config = $this->readProjectConfig();
        $provider = $config['gitProvider'] ?? null;

        if ($provider !== null && is_string($provider) && in_array($provider, ['github', 'gitlab'], true)) {
            return $provider;
        }

        // Try auto-detection from remote URL
        $parsed = $this->parseGitUrl('origin');
        if (isset($parsed['provider'])) {
            return $parsed['provider'];
        }

        return null;
    }

    /**
     * Ensures the git provider is configured in project config.
     * If not configured, attempts auto-detection from remote URL and prompts user if needed.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io The Symfony IO instance
     * @param \App\Service\Logger $logger The logger instance
     * @param \App\Service\TranslationService $translator The translation service
     * @return string The provider type ('github' or 'gitlab')
     * @throws \RuntimeException If not in a git repository or if provider cannot be determined
     */
    public function ensureGitProviderConfigured(
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        \App\Service\Logger $logger,
        \App\Service\TranslationService $translator
    ): string {
        // Check if we're in a git repository
        try {
            $this->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($translator->trans('config.git_provider_required'));
        }

        $config = $this->readProjectConfig();
        $provider = $config['gitProvider'] ?? null;

        // If configured and valid, return it
        if (is_string($provider) && in_array($provider, ['github', 'gitlab'], true)) {
            return $provider;
        }

        // Try auto-detection from remote URL
        $parsed = $this->parseGitUrl('origin');
        $detected = $parsed['provider'] ?? null;

        if ($detected !== null) {
            $logger->note(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $translator->trans('config.git_provider_detected', ['provider' => $detected])
            );
        }

        // Prompt user for provider
        $logger->note(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.git_provider_not_configured')
        );

        $defaultSuggestion = $detected ?? 'github';
        $enteredProvider = $logger->choice(
            $translator->trans('config.git_provider_prompt'),
            ['github', 'gitlab'],
            $defaultSuggestion
        );

        if ($enteredProvider === null || ! in_array($enteredProvider, ['github', 'gitlab'], true)) {
            throw new \RuntimeException($translator->trans('config.git_provider_required'));
        }

        // Save to config
        $logger->text(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.git_provider_saving')
        );

        $config['gitProvider'] = $enteredProvider;
        $this->writeProjectConfig($config);

        $logger->success(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.git_provider_saved', ['provider' => $enteredProvider])
        );

        return $enteredProvider;
    }

    /**
     * Ensures the git token is configured for the given provider.
     * Checks project config first, then global config.
     * If not found, prompts user to configure it.
     *
     * @param string $providerType The provider type ('github' or 'gitlab')
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io The Symfony IO instance
     * @param \App\Service\Logger $logger The logger instance
     * @param \App\Service\TranslationService $translator The translation service
     * @param array<string, mixed> $globalConfig The global configuration array
     * @return string|null The token string, or null if user skipped or error occurred
     * @throws \RuntimeException If not in a git repository
     */
    public function ensureGitTokenConfigured(
        string $providerType,
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        \App\Service\Logger $logger,
        \App\Service\TranslationService $translator,
        array $globalConfig
    ): ?string {
        // Check if we're in a git repository
        try {
            $this->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($translator->trans('config.git_token_required'));
        }

        $projectConfig = $this->readProjectConfig();

        // Determine token key based on provider
        $tokenKey = $providerType === 'github' ? 'githubToken' : 'gitlabToken';
        $globalTokenKey = $providerType === 'github' ? 'GITHUB_TOKEN' : 'GITLAB_TOKEN';
        $oppositeTokenKey = $providerType === 'github' ? 'GITLAB_TOKEN' : 'GITHUB_TOKEN';
        $oppositeLocalKey = $providerType === 'github' ? 'gitlabToken' : 'githubToken';
        $oppositeProvider = $providerType === 'github' ? 'GitLab' : 'GitHub';

        // Check if token already exists in project config
        $token = $projectConfig[$tokenKey] ?? null;
        if ($token !== null && is_string($token) && trim($token) !== '') {
            return trim($token);
        }

        // Check if token exists in global config
        $globalToken = $globalConfig[$globalTokenKey] ?? null;
        if ($globalToken !== null && is_string($globalToken) && trim($globalToken) !== '') {
            return trim($globalToken);
        }

        // Check for token type mismatch (opposite token exists)
        $oppositeToken = $projectConfig[$oppositeLocalKey] ?? $globalConfig[$oppositeTokenKey] ?? null;
        if ($oppositeToken !== null && is_string($oppositeToken) && trim($oppositeToken) !== '') {
            $logger->warning(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $translator->trans('config.git_token_type_mismatch', [
                    'provider' => ucfirst($providerType),
                    'opposite' => $oppositeProvider,
                ])
            );
        }

        // No token found - prompt user
        $logger->note(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.git_token_not_configured')
        );

        // Check if any tokens exist globally
        $hasAnyGlobalToken = ($globalConfig['GITHUB_TOKEN'] ?? null) !== null
            || ($globalConfig['GITLAB_TOKEN'] ?? null) !== null;

        if (! $hasAnyGlobalToken) {
            $logger->note(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $translator->trans('config.git_token_global_suggestion')
            );
        }

        $enteredToken = $logger->askHidden(
            $translator->trans('config.git_token_prompt', ['provider' => ucfirst($providerType)])
        );

        if ($enteredToken === null || empty(trim($enteredToken))) {
            // User skipped - return null
            return null;
        }

        $enteredToken = trim($enteredToken);

        // Save to project config
        $logger->text(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.git_token_saving')
        );

        $projectConfig[$tokenKey] = $enteredToken;
        $this->writeProjectConfig($projectConfig);

        $logger->success(
            \App\Service\Logger::VERBOSITY_NORMAL,
            $translator->trans('config.git_token_saved', ['provider' => ucfirst($providerType)])
        );

        return $enteredToken;
    }
}
