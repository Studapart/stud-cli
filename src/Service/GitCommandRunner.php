<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\GitException;
use App\Exception\GitTimeoutException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GitCommandRunner
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
    ) {
    }

    /**
     * Runs a git command and translates failures into git exceptions.
     *
     * @param array<int, string> $command
     * @throws GitTimeoutException When the command exceeds the git subprocess timeout
     * @throws GitException When the command exits unsuccessfully
     */
    public function run(array $command): Process
    {
        $process = $this->processFactory->create($command);
        $commandLine = implode(' ', $command);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            throw new GitTimeoutException(
                $commandLine,
                ProcessFactory::GIT_SUBPROCESS_TIMEOUT_SECONDS,
                $e->getMessage(),
                $e,
            );
        } catch (ProcessFailedException $e) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            $technicalDetails = trim($errorOutput) ?: 'Command failed with no error output';

            throw new GitException(
                "Git command failed: {$commandLine}",
                $technicalDetails,
                $e
            );
        }

        return $process;
    }

    /**
     * Runs a git command without throwing; callers inspect the returned process.
     *
     * @param array<int, string> $command
     */
    public function runQuietly(array $command): Process
    {
        $process = $this->processFactory->create($command);
        $process->run();

        return $process;
    }
}
