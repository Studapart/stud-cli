<?php

namespace App\Service;

use App\Service\ProcessFactory;
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

        if (!$process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());
        preg_match('/(?i)([a-z]+-\d+)/', $branch, $matches);

        return isset($matches[1]) ? strtoupper($matches[1]) : null;
    }

    public function getUpstreamBranch(): ?string
    {
        $process = $this->runQuietly('git rev-parse --abbrev-ref @{u} 2>/dev/null');

        if (!$process->isSuccessful()) {
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

        if (!$process->isSuccessful()) {
            return false;
        }

        $output = trim($process->getOutput());
        return !empty($output);
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

        if (!$process->isSuccessful()) {
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

        if (!$process->isSuccessful()) {
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

        return !empty(trim($process->getOutput()));
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

        if (!$remoteUrl) {
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

        if (!$process->isSuccessful()) {
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
        if (!$process->isSuccessful()) {
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
        if (!file_exists($configPath)) {
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
        
        if (!is_dir($configDir)) {
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
}
