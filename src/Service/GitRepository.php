<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConfigFileReadResult;
use App\Exception\GitException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class GitRepository
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly GitProjectConfigService $projectConfigService,
        private readonly GitRemoteUrlParser $remoteUrlParser,
        private readonly GitRebaseAutosquashService $rebaseAutosquashService,
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
        return $this->rebaseAutosquashService->hasFixupCommits($baseSha);
    }

    public function rebaseAutosquash(string $baseSha): void
    {
        $this->rebaseAutosquashService->rebaseAutosquash($baseSha);
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
        return $this->rebaseAutosquashService->findLatestLogicalSha($baseBranch);
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

    /**
     * Non-force push of HEAD to origin (same ref as submit preflight). Delegates to {@see pushToOrigin}.
     */
    public function pushHeadToOrigin(): Process
    {
        return $this->pushToOrigin('HEAD');
    }

    public function getMergeBase(string $baseBranch, string $head): string
    {
        return trim($this->run("git merge-base {$baseBranch} {$head}")->getOutput());
    }

    public function findFirstLogicalSha(string $ancestorSha): ?string
    {
        return $this->rebaseAutosquashService->findFirstLogicalSha($ancestorSha);
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
     * @return array{owner?: string, name?: string, provider?: string}
     */
    public function parseGitUrl(string $remote = 'origin'): array
    {
        return $this->remoteUrlParser->parseRemote($remote);
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
        return $this->projectConfigService->getProjectConfigPath();
    }

    /**
     * @return array{projectKey?: string, transitionId?: int, baseBranch?: string, gitProvider?: string, githubToken?: string, gitlabToken?: string, gitlabInstanceUrl?: string, JIRA_DEFAULT_PROJECT?: string, CONFLUENCE_DEFAULT_SPACE?: string, migration_version?: string}
     */
    public function readProjectConfig(): array
    {
        return $this->projectConfigService->readProjectConfig();
    }

    public function readProjectConfigResult(): ConfigFileReadResult
    {
        return $this->projectConfigService->readProjectConfigResult();
    }

    /**
     * @param array{projectKey?: string, transitionId?: int, baseBranch?: string, gitProvider?: string, githubToken?: string, gitlabToken?: string, gitlabInstanceUrl?: string, JIRA_DEFAULT_PROJECT?: string, CONFLUENCE_DEFAULT_SPACE?: string, migration_version?: string} $config
     */
    public function writeProjectConfig(array $config): void
    {
        $this->projectConfigService->writeProjectConfig($config);
    }

    public function getProjectKeyFromIssueKey(string $issueKey): string
    {
        return $this->projectConfigService->getProjectKeyFromIssueKey($issueKey);
    }

    public function getGitProvider(): ?string
    {
        return $this->projectConfigService->getGitProvider();
    }
}
