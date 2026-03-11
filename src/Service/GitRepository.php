<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\GitException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class GitRepository
{
    private const REBASE_AUTOSQUASH_SCRIPT = <<<'SCRIPT'
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

    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly FileSystem $fileSystem
    ) {
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

    /**
     * Returns true if the current HEAD is pushed to its upstream.
     * If there is no upstream, returns false.
     */
    public function isHeadPushed(): bool
    {
        if ($this->getUpstreamBranch() === null) {
            return false;
        }

        $headProcess = $this->runQuietly('git rev-parse HEAD');
        if (! $headProcess->isSuccessful()) {
            return false;
        }

        $upstreamProcess = $this->runQuietly('git rev-parse @{u} 2>/dev/null');
        if (! $upstreamProcess->isSuccessful()) {
            return false;
        }

        $headSha = trim($headProcess->getOutput());
        $upstreamSha = trim($upstreamProcess->getOutput());

        return $headSha === $upstreamSha;
    }

    /**
     * Returns true if the repository has at least one commit (HEAD exists).
     */
    public function hasAtLeastOneCommit(): bool
    {
        $process = $this->runQuietly('git rev-parse HEAD');

        return $process->isSuccessful();
    }

    /**
     * Removes the last commit and leaves its changes in the working tree (unstaged).
     * Equivalent to git reset HEAD~1 (mixed).
     *
     * @throws GitException If not in a repository or there is no commit to undo
     */
    public function undoLastCommit(): void
    {
        $this->run('git reset HEAD~1');
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

    /**
     * Attempts a rebase without throwing on failure.
     *
     * @return bool True if rebase succeeded, false if conflicts occurred
     */
    public function tryRebase(string $branch): bool
    {
        return $this->runQuietly("git rebase {$branch}")->isSuccessful();
    }

    /**
     * Aborts an in-progress rebase, restoring the branch to its pre-rebase state.
     */
    public function rebaseAbort(): void
    {
        $this->run('git rebase --abort');
    }

    /**
     * Checks whether a given ref is an ancestor of another ref.
     */
    public function isAncestor(string $possibleAncestor, string $descendant): bool
    {
        return $this->runQuietly("git merge-base --is-ancestor {$possibleAncestor} {$descendant}")->isSuccessful();
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
        $scriptContent = self::REBASE_AUTOSQUASH_SCRIPT;

        $tempScript = tempnam(sys_get_temp_dir(), 'stud-rebase-');
        // @codeCoverageIgnoreStart
        // tempnam() failure is extremely rare and difficult to simulate in tests
        if ($tempScript === false) {
            throw new \RuntimeException('Failed to create temporary script file');
        }
        // @codeCoverageIgnoreEnd

        $this->fileSystem->write($tempScript, $scriptContent);
        $this->fileSystem->chmod($tempScript, 0755);

        try {
            // Set GIT_SEQUENCE_EDITOR to our script and run rebase
            $env = $_ENV;
            $env['GIT_SEQUENCE_EDITOR'] = $tempScript;

            $process = $this->processFactory->create("git rebase -i --autosquash {$baseSha}");
            $process->setEnv($env);
            $process->mustRun();
        } finally {
            try {
                $this->fileSystem->delete($tempScript);
            } catch (\RuntimeException $e) {
                // Ignore cleanup errors - file may already be deleted
            }
            $backupFile = $tempScript . '.bak';
            if ($this->fileSystem->fileExists($backupFile)) {
                try {
                    $this->fileSystem->delete($backupFile);
                } catch (\RuntimeException $e) {
                    // Ignore cleanup errors - file may already be deleted
                }
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
    public function parseGitUrl(string $remote = 'origin'): array
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

        if (! $this->fileSystem->fileExists($configPath)) {
            return [];
        }

        try {
            $config = $this->fileSystem->parseFile($configPath);

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

        if (! $this->fileSystem->isDir($configDir)) {
            throw new \RuntimeException("Git directory not found: {$configDir}");
        }

        // Preserve migration_version if it exists in current config
        if ($this->fileSystem->fileExists($configPath)) {
            $existingConfig = $this->readProjectConfig();
            if (isset($existingConfig['migration_version'])) {
                $config['migration_version'] = $existingConfig['migration_version'];
            }
        }

        $this->fileSystem->dumpFile($configPath, $config);
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
}
