<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

class GitRebaseAutosquashService
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
        private readonly FileSystem $fileSystem,
    ) {
    }

    public function hasFixupCommits(string $baseSha): bool
    {
        $process = $this->runQuietly(
            "git log {$baseSha}..HEAD --format=%s --grep='^fixup!' --grep='^squash!'"
        );

        if (! $process->isSuccessful()) {
            return false;
        }

        return trim($process->getOutput()) !== '';
    }

    public function rebaseAutosquash(string $baseSha): void
    {
        $tempScript = tempnam(sys_get_temp_dir(), 'stud-rebase-');
        // @codeCoverageIgnoreStart
        if ($tempScript === false) {
            throw new \RuntimeException('Failed to create temporary script file');
        }
        // @codeCoverageIgnoreEnd

        $this->fileSystem->write($tempScript, self::REBASE_AUTOSQUASH_SCRIPT);
        $this->fileSystem->chmod($tempScript, 0755);

        try {
            $env = $_ENV;
            $env['GIT_SEQUENCE_EDITOR'] = $tempScript;

            $process = $this->processFactory->create("git rebase -i --autosquash {$baseSha}");
            $process->setEnv($env);
            $process->mustRun();
        } finally {
            $this->deleteIfExists($tempScript);
            $this->deleteIfExists($tempScript . '.bak');
        }
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

        return $output === '' ? null : $output;
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

        return $output === '' ? null : $output;
    }

    protected function runQuietly(string $command): Process
    {
        $process = $this->processFactory->create($command);
        $process->run();

        return $process;
    }

    private function deleteIfExists(string $path): void
    {
        if (! $this->fileSystem->fileExists($path)) {
            return;
        }

        try {
            $this->fileSystem->delete($path);
        } catch (\RuntimeException) {
            // Recoverable cleanup: temp script may already be gone; no recorder at this layer (ADR-017).
        }
    }
}
