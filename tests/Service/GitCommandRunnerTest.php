<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\GitException;
use App\Exception\GitTimeoutException;
use App\Service\GitCommandRunner;
use App\Service\ProcessFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GitCommandRunnerTest extends TestCase
{
    private ProcessFactory&Stub $processFactory;
    private GitCommandRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processFactory = $this->createStub(ProcessFactory::class);
        $this->runner = new GitCommandRunner($this->processFactory);
    }

    public function testRunReturnsProcessOnSuccess(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('mustRun')->willReturnSelf();
        $this->processFactory->method('create')->willReturn($process);

        $this->assertSame($process, $this->runner->run(['git', 'status']));
    }

    public function testRunThrowsGitExceptionOnFailure(): void
    {
        $this->processFactory->method('create')->willReturn($this->stubFailingProcess('Error: command failed', ''));

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        $this->runner->run(['my', 'command']);
    }

    public function testRunThrowsGitExceptionWithOutputWhenErrorOutputEmpty(): void
    {
        $this->processFactory->method('create')->willReturn($this->stubFailingProcess('', 'Some output'));

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        try {
            $this->runner->run(['my', 'command']);
        } catch (GitException $e) {
            $this->assertSame('Some output', $e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testRunThrowsGitExceptionWithDefaultMessageWhenBothOutputsEmpty(): void
    {
        $this->processFactory->method('create')->willReturn($this->stubFailingProcess());

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        try {
            $this->runner->run(['my', 'command']);
        } catch (GitException $e) {
            $this->assertSame('Command failed with no error output', $e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testRunThrowsGitTimeoutExceptionOnProcessTimeout(): void
    {
        $this->processFactory->method('create')->willReturn($this->stubTimedOutProcess());

        $this->expectException(GitTimeoutException::class);

        try {
            $this->runner->run(['git', 'commit', '-m', 'test']);
        } catch (GitTimeoutException $e) {
            $this->assertSame('git commit -m test', $e->getCommand());
            $this->assertSame(ProcessFactory::GIT_SUBPROCESS_TIMEOUT_SECONDS, $e->getTimeoutSeconds());
            $this->assertNotEmpty($e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testRunQuietlyRunsWithoutThrowing(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('run')->willReturn(1);
        $process->expects($this->never())->method('mustRun');
        $this->processFactory->method('create')->willReturn($process);

        $this->assertSame($process, $this->runner->runQuietly(['git', 'diff', '--cached', '--quiet']));
    }

    private function stubFailingProcess(string $errorOutput = '', string $output = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn($errorOutput);
        $process->method('getOutput')->willReturn($output);
        $process->method('getCommandLine')->willReturn('');
        $process->method('getExitCode')->willReturn(1);
        $process->method('getExitCodeText')->willReturn('General error');
        $process->method('getWorkingDirectory')->willReturn('');
        $process->method('isOutputDisabled')->willReturn(false);
        $process->method('mustRun')->willThrowException(new ProcessFailedException($process));

        return $process;
    }

    private function stubTimedOutProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getCommandLine')->willReturn('git commit -m test');
        $process->method('getTimeout')->willReturn(1.0);
        $process->method('mustRun')->willThrowException(new ProcessTimedOutException(
            $process,
            ProcessTimedOutException::TYPE_GENERAL,
        ));

        return $process;
    }
}
