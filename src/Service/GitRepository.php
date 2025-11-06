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

    public function fetchOrigin(): void
    {
        $this->run('git fetch origin');
    }

    public function switch(string $branchName, string $baseBranch): void
    {
        $this->run("git switch -c {$branchName} " . $baseBranch);
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
}
