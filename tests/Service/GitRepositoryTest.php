<?php

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\ProcessFactory;
use App\Tests\CommandTestCase;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Process\Process;

class GitRepositoryTest extends CommandTestCase
{
    protected GitRepository $gitRepository;
    private ProcessFactory&MockObject $processFactory;
    protected FileSystem $fileSystem;
    protected FlysystemFilesystem $flysystem;

    protected function setUp(): void
    {
        parent::setUp();

        // Create in-memory filesystem
        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem);

        $this->processFactory = $this->createMock(ProcessFactory::class);
        $this->gitRepository = new GitRepository($this->processFactory, $this->fileSystem);
    }

    public function testGetJiraKeyFromBranchName(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('feature/PROJ-123-my-feature');

        $key = $this->gitRepository->getJiraKeyFromBranchName();

        $this->assertSame('PROJ-123', $key);
    }

    public function testGetJiraKeyFromBranchNameReturnsNullIfNotFound(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('main');

        $key = $this->gitRepository->getJiraKeyFromBranchName();

        $this->assertNull($key);
    }

    public function testGetJiraKeyFromBranchNameReturnsNullIfNotSuccessful(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $key = $this->gitRepository->getJiraKeyFromBranchName();

        $this->assertNull($key);
    }

    public function testGetUpstreamBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref @{u} 2>/dev/null')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('origin/main');

        $upstream = $this->gitRepository->getUpstreamBranch();

        $this->assertSame('origin/main', $upstream);
    }

    public function testGetUpstreamBranchReturnsNullIfNoUpstream(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref @{u} 2>/dev/null')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $upstream = $this->gitRepository->getUpstreamBranch();

        $this->assertNull($upstream);
    }

    public function testGetUpstreamBranchReturnsNullIfNotSuccessful(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref @{u} 2>/dev/null')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $upstream = $this->gitRepository->getUpstreamBranch();

        $this->assertNull($upstream);
    }

    public function testIsHeadPushedReturnsFalseWhenNoUpstream(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref @{u} 2>/dev/null')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsFalseWhenHeadRevParseFails(): void
    {
        $upstreamProcess = $this->createMock(Process::class);
        $upstreamProcess->expects($this->once())->method('run');
        $upstreamProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $upstreamProcess->expects($this->once())->method('getOutput')->willReturn('origin/main');

        $headProcess = $this->createMock(Process::class);
        $headProcess->expects($this->once())->method('run');
        $headProcess->expects($this->once())->method('isSuccessful')->willReturn(false);

        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (string $command) use ($upstreamProcess, $headProcess) {
                if (str_contains($command, '--abbrev-ref @{u}')) {
                    return $upstreamProcess;
                }
                if (str_contains($command, 'rev-parse HEAD') && ! str_contains($command, '@{u}')) {
                    return $headProcess;
                }

                throw new \RuntimeException('Unexpected command: ' . $command);
            });

        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsFalseWhenUpstreamRevParseFails(): void
    {
        $upstreamProcess = $this->createMock(Process::class);
        $upstreamProcess->expects($this->once())->method('run');
        $upstreamProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $upstreamProcess->expects($this->once())->method('getOutput')->willReturn('origin/main');

        $headProcess = $this->createMock(Process::class);
        $headProcess->expects($this->once())->method('run');
        $headProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $headProcess->expects($this->never())->method('getOutput');

        $atUProcess = $this->createMock(Process::class);
        $atUProcess->expects($this->once())->method('run');
        $atUProcess->expects($this->once())->method('isSuccessful')->willReturn(false);

        $this->processFactory->expects($this->exactly(3))
            ->method('create')
            ->willReturnCallback(function (string $command) use ($upstreamProcess, $headProcess, $atUProcess) {
                if (str_contains($command, '--abbrev-ref @{u}')) {
                    return $upstreamProcess;
                }
                if (str_contains($command, 'rev-parse HEAD') && ! str_contains($command, '@{u}')) {
                    return $headProcess;
                }
                if (str_contains($command, 'rev-parse @{u}')) {
                    return $atUProcess;
                }

                throw new \RuntimeException('Unexpected command: ' . $command);
            });

        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsTrueWhenHeadEqualsUpstream(): void
    {
        $sameSha = 'abc123def456';

        $upstreamProcess = $this->createMock(Process::class);
        $upstreamProcess->expects($this->once())->method('run');
        $upstreamProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $upstreamProcess->expects($this->once())->method('getOutput')->willReturn('origin/main');

        $headProcess = $this->createMock(Process::class);
        $headProcess->expects($this->once())->method('run');
        $headProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $headProcess->expects($this->once())->method('getOutput')->willReturn($sameSha);

        $atUProcess = $this->createMock(Process::class);
        $atUProcess->expects($this->once())->method('run');
        $atUProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $atUProcess->expects($this->once())->method('getOutput')->willReturn($sameSha);

        $this->processFactory->expects($this->exactly(3))
            ->method('create')
            ->willReturnCallback(function (string $command) use ($upstreamProcess, $headProcess, $atUProcess) {
                if (str_contains($command, '--abbrev-ref @{u}')) {
                    return $upstreamProcess;
                }
                if (str_contains($command, 'rev-parse HEAD') && ! str_contains($command, '@{u}')) {
                    return $headProcess;
                }
                if (str_contains($command, 'rev-parse @{u}')) {
                    return $atUProcess;
                }

                throw new \RuntimeException('Unexpected command: ' . $command);
            });

        $this->assertTrue($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsFalseWhenHeadDiffersFromUpstream(): void
    {
        $upstreamProcess = $this->createMock(Process::class);
        $upstreamProcess->expects($this->once())->method('run');
        $upstreamProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $upstreamProcess->expects($this->once())->method('getOutput')->willReturn('origin/main');

        $headProcess = $this->createMock(Process::class);
        $headProcess->expects($this->once())->method('run');
        $headProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $headProcess->expects($this->once())->method('getOutput')->willReturn('abc123');

        $atUProcess = $this->createMock(Process::class);
        $atUProcess->expects($this->once())->method('run');
        $atUProcess->expects($this->once())->method('isSuccessful')->willReturn(true);
        $atUProcess->expects($this->once())->method('getOutput')->willReturn('def456');

        $this->processFactory->expects($this->exactly(3))
            ->method('create')
            ->willReturnCallback(function (string $command) use ($upstreamProcess, $headProcess, $atUProcess) {
                if (str_contains($command, '--abbrev-ref @{u}')) {
                    return $upstreamProcess;
                }
                if (str_contains($command, 'rev-parse HEAD') && ! str_contains($command, '@{u}')) {
                    return $headProcess;
                }
                if (str_contains($command, 'rev-parse @{u}')) {
                    return $atUProcess;
                }

                throw new \RuntimeException('Unexpected command: ' . $command);
            });

        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testHasAtLeastOneCommitReturnsTrueWhenHeadExists(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse HEAD')
            ->willReturn($process);

        $process->expects($this->once())->method('run');
        $process->expects($this->once())->method('isSuccessful')->willReturn(true);

        $this->assertTrue($this->gitRepository->hasAtLeastOneCommit());
    }

    public function testHasAtLeastOneCommitReturnsFalseWhenNoCommits(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse HEAD')
            ->willReturn($process);

        $process->expects($this->once())->method('run');
        $process->expects($this->once())->method('isSuccessful')->willReturn(false);

        $this->assertFalse($this->gitRepository->hasAtLeastOneCommit());
    }

    public function testUndoLastCommitRunsReset(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git reset HEAD~1')
            ->willReturn($process);

        $process->expects($this->once())->method('mustRun');

        $this->gitRepository->undoLastCommit();
    }

    public function testUndoLastCommitThrowsWhenResetFails(): void
    {
        $this->expectException(\App\Exception\GitException::class);

        $process = $this->createMock(Process::class);
        $process->expects($this->once())->method('mustRun')
            ->willThrowException(new \Symfony\Component\Process\Exception\ProcessFailedException($process));

        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git reset HEAD~1')
            ->willReturn($process);

        $this->gitRepository->undoLastCommit();
    }

    public function testForcePushWithLease(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git push --force-with-lease')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->forcePushWithLease();
    }

    public function testFindLatestLogicalSha(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git log origin/develop..HEAD --format=%H --grep="^fixup!" --grep="^squash!" --invert-grep --max-count=1')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('abcdef');

        $sha = $this->gitRepository->findLatestLogicalSha('origin/develop');

        $this->assertSame('abcdef', $sha);
    }

    public function testFindLatestLogicalShaReturnsNullIfNotFound(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git log origin/develop..HEAD --format=%H --grep="^fixup!" --grep="^squash!" --invert-grep --max-count=1')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $sha = $this->gitRepository->findLatestLogicalSha('origin/develop');

        $this->assertNull($sha);
    }

    public function testFindLatestLogicalShaReturnsNullIfNotSuccessful(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git log origin/develop..HEAD --format=%H --grep="^fixup!" --grep="^squash!" --invert-grep --max-count=1')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $sha = $this->gitRepository->findLatestLogicalSha('origin/develop');

        $this->assertNull($sha);
    }

    public function testStageAllChanges(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git add -A')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->stageAllChanges();
    }

    public function testCommitFixup(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git commit --fixup abcdef')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->commitFixup('abcdef');
    }

    public function testCommit(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git commit -m \'my message\'')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->commit('my message');
    }

    public function testFetch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git fetch origin')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->fetch();
    }

    public function testCreateBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git switch -c feature/PROJ-123-my-feature origin/develop')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->createBranch('feature/PROJ-123-my-feature', 'origin/develop');
    }

    public function testGetPorcelainStatus(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git status --porcelain')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn(' M file.txt\n');

        $status = $this->gitRepository->getPorcelainStatus();

        $this->assertSame(' M file.txt\n', $status);
    }

    public function testGetCurrentBranchName(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --abbrev-ref HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('feature/PROJ-123-my-feature');

        $branchName = $this->gitRepository->getCurrentBranchName();

        $this->assertSame('feature/PROJ-123-my-feature', $branchName);
    }

    public function testPushToOrigin(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git push --set-upstream origin my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');

        $this->gitRepository->pushToOrigin('my-branch');
    }

    public function testPushHeadToOrigin(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git push --set-upstream origin HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');

        $this->gitRepository->pushHeadToOrigin();
    }

    public function testGetMergeBase(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git merge-base origin/develop HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('abcdef');

        $mergeBase = $this->gitRepository->getMergeBase('origin/develop', 'HEAD');

        $this->assertSame('abcdef', $mergeBase);
    }

    public function testFindFirstLogicalSha(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git rev-list --reverse abcdef..HEAD | grep -v -E '^ (fixup|squash)!' | head -n 1")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('ghijkl');

        $sha = $this->gitRepository->findFirstLogicalSha('abcdef');

        $this->assertSame('ghijkl', $sha);
    }

    public function testFindFirstLogicalShaReturnsNullIfNotFound(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git rev-list --reverse abcdef..HEAD | grep -v -E '^ (fixup|squash)!' | head -n 1")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $sha = $this->gitRepository->findFirstLogicalSha('abcdef');

        $this->assertNull($sha);
    }

    public function testFindFirstLogicalShaReturnsNullIfNotSuccessful(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git rev-list --reverse abcdef..HEAD | grep -v -E '^ (fixup|squash)!' | head -n 1")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $sha = $this->gitRepository->findFirstLogicalSha('abcdef');

        $this->assertNull($sha);
    }

    public function testGetCommitMessage(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git log -1 --pretty=%B abcdef')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('feat: my commit message');

        $message = $this->gitRepository->getCommitMessage('abcdef');

        $this->assertSame('feat: my commit message', $message);
    }

    public function testLocalBranchExists(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --verify --quiet my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);

        $exists = $this->gitRepository->localBranchExists('my-branch');

        $this->assertTrue($exists);
    }

    public function testLocalBranchDoesNotExist(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --verify --quiet my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $exists = $this->gitRepository->localBranchExists('my-branch');

        $this->assertFalse($exists);
    }

    public function testRemoteBranchExists(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git ls-remote --heads origin my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('abcdef refs/heads/my-branch');

        $exists = $this->gitRepository->remoteBranchExists('origin', 'my-branch');

        $this->assertTrue($exists);
    }

    public function testRemoteBranchDoesNotExist(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git ls-remote --heads origin my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $exists = $this->gitRepository->remoteBranchExists('origin', 'my-branch');

        $this->assertFalse($exists);
    }

    public function testRun(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('my command')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->run('my command');
    }

    public function testRunThrowsGitExceptionOnFailure(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('my command')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun')
            ->willThrowException(new \Symfony\Component\Process\Exception\ProcessFailedException($process));

        $process->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn('Error: command failed');

        $this->expectException(\App\Exception\GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        $this->gitRepository->run('my command');
    }

    public function testRunThrowsGitExceptionWithOutputWhenErrorOutputEmpty(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('my command')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun')
            ->willThrowException(new \Symfony\Component\Process\Exception\ProcessFailedException($process));

        $process->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn('');

        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('Some output');

        $this->expectException(\App\Exception\GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        try {
            $this->gitRepository->run('my command');
        } catch (\App\Exception\GitException $e) {
            $this->assertSame('Some output', $e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testRunThrowsGitExceptionWithDefaultMessageWhenBothOutputsEmpty(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('my command')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun')
            ->willThrowException(new \Symfony\Component\Process\Exception\ProcessFailedException($process));

        $process->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn('');

        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $this->expectException(\App\Exception\GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        try {
            $this->gitRepository->run('my command');
        } catch (\App\Exception\GitException $e) {
            $this->assertSame('Command failed with no error output', $e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testRunQuietly(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('my command')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');

        $this->gitRepository->runQuietly('my command');
    }

    public function testAdd(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git add file1.txt file2.txt')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->add(['file1.txt', 'file2.txt']);
    }

    public function testCheckout(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git checkout my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->checkout('my-branch');
    }

    public function testPull(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git pull origin main')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->pull('origin', 'main');
    }

    public function testMerge(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git merge --no-ff my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->merge('my-branch');
    }

    public function testTag(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git tag -a v1.2.3 -m 'Release v1.2.3'")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->tag('v1.2.3', 'Release v1.2.3');
    }

    public function testPushTags(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git push --tags origin main')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->pushTags('origin');
    }

    public function testRebase(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rebase main')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->rebase('main');
    }

    public function testHasFixupCommitsWithFixups(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git log abc123..HEAD --format=%s --grep='^fixup!' --grep='^squash!'")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("fixup! Initial commit\nsquash! Another commit");

        $result = $this->gitRepository->hasFixupCommits('abc123');

        $this->assertTrue($result);
    }

    public function testHasFixupCommitsWithoutFixups(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git log abc123..HEAD --format=%s --grep='^fixup!' --grep='^squash!'")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $result = $this->gitRepository->hasFixupCommits('abc123');

        $this->assertFalse($result);
    }

    public function testHasFixupCommitsWithFailedProcess(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git log abc123..HEAD --format=%s --grep='^fixup!' --grep='^squash!'")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $result = $this->gitRepository->hasFixupCommits('abc123');

        $this->assertFalse($result);
    }

    public function testRebaseAutosquash(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($command) {
                return str_contains($command, 'git rebase -i --autosquash abc123');
            }))
            ->willReturn($process);

        $process->expects($this->once())
            ->method('setEnv')
            ->with($this->callback(function ($env) {
                if (! isset($env['GIT_SEQUENCE_EDITOR'])) {
                    return false;
                }
                $scriptPath = $env['GIT_SEQUENCE_EDITOR'];
                // Verify script exists in filesystem (works with both real and in-memory)
                if (! $this->fileSystem->fileExists($scriptPath)) {
                    return false;
                }

                // Verify script content
                try {
                    $content = $this->fileSystem->read($scriptPath);
                } catch (\RuntimeException $e) {
                    return false;
                }

                return str_contains($content, 'fixup!') && str_contains($content, 'squash!');
            }));

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->rebaseAutosquash('abc123');
    }

    public function testRebaseAutosquashCleansUpBackupFile(): void
    {
        // This test verifies that backup files are cleaned up
        // We'll create a scenario where sed would create a backup file
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $scriptPath = null;
        $process->expects($this->once())
            ->method('setEnv')
            ->with($this->callback(function ($env) use (&$scriptPath) {
                if (isset($env['GIT_SEQUENCE_EDITOR'])) {
                    $scriptPath = $env['GIT_SEQUENCE_EDITOR'];
                    // Create a backup file using FileSystem to test cleanup (line 155)
                    $backupPath = $scriptPath . '.bak';
                    $this->fileSystem->write($backupPath, 'backup content');
                    // Verify it exists before cleanup
                    $this->assertTrue($this->fileSystem->fileExists($backupPath));
                }

                return true;
            }));

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->rebaseAutosquash('abc123');

        // Verify backup file was cleaned up (line 155)
        if ($scriptPath !== null) {
            $backupPath = $scriptPath . '.bak';
            $this->assertFalse($this->fileSystem->fileExists($backupPath));
        }
    }

    public function testDeleteBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git branch -d my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->deleteBranch('my-branch');
    }

    public function testDeleteBranchWithRemoteExistsTrue(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git branch -d my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->deleteBranch('my-branch', true);
    }

    public function testDeleteBranchWithRemoteExistsFalse(): void
    {
        $pruneProcess = $this->createMock(Process::class);
        $deleteProcess = $this->createMock(Process::class);

        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($pruneProcess, $deleteProcess) {
                if ($command === 'git fetch --prune origin') {
                    return $pruneProcess;
                }
                if ($command === 'git branch -d my-branch') {
                    return $deleteProcess;
                }

                return null;
            });

        $pruneProcess->expects($this->once())
            ->method('mustRun');
        $deleteProcess->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->deleteBranch('my-branch', false);
    }

    public function testDeleteBranchForce(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git branch -D my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->deleteBranchForce('my-branch');
    }

    public function testPruneRemoteTrackingRefs(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git fetch --prune origin')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->pruneRemoteTrackingRefs();
    }

    public function testPruneRemoteTrackingRefsWithCustomRemote(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git fetch --prune upstream')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->pruneRemoteTrackingRefs('upstream');
    }

    public function testDeleteRemoteBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git push origin --delete my-branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->deleteRemoteBranch('origin', 'my-branch');
    }

    public function testForcePushWithLeaseRemote(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git push --force-with-lease origin main')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->forcePushWithLeaseRemote('origin', 'main');
    }

    public function testGetRepositoryOwnerWithSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@github.com:studapart/stud-cli.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://github.com/studapart/stud-cli.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithHttpsUrlWithoutGitExtension(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://github.com/studapart/stud-cli');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerReturnsNullIfNotSuccessful(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertNull($owner);
    }

    public function testGetRepositoryOwnerWithGitLabSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@gitlab.com:studapart/stud-cli.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithGitLabHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://gitlab.com/studapart/stud-cli.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryNameWithGitLabSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@gitlab.com:studapart/stud-cli.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryOwnerReturnsNullIfUrlDoesNotMatchPattern(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        // Use a URL format that doesn't match any known pattern (file:// or invalid format)
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('file:///path/to/repo.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertNull($owner);
    }

    public function testGetRepositoryNameWithSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@github.com:studapart/stud-cli.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameWithHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://github.com/studapart/stud-cli.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameWithHttpsUrlWithoutGitExtension(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://github.com/studapart/stud-cli');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameReturnsNullIfNotSuccessful(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertNull($name);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@git.example.com:studapart/stud-cli.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://git.example.com/studapart/stud-cli.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@git.example.com:studapart/stud-cli.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://git.example.com/studapart/stud-cli.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryOwnerWithGitLabNestedGroupSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@gitlab.com:group/subgroup/repo.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryOwnerWithGitLabNestedGroupHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://gitlab.com/group/subgroup/repo.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryNameWithGitLabNestedGroupSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@gitlab.com:group/subgroup/repo.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryNameWithGitLabNestedGroupHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://gitlab.com/group/subgroup/repo.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceNestedGroupSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@git.example.com:group/subgroup/repo.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceNestedGroupHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://git.example.com/group/subgroup/repo.git');

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceNestedGroupSshUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@git.example.com:group/subgroup/repo.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceNestedGroupHttpsUrl(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('https://git.example.com/group/subgroup/repo.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryNameReturnsNullIfUrlDoesNotMatchPattern(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        // Use a URL format that doesn't match any known pattern (file:// or invalid format)
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('file:///path/to/repo.git');

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertNull($name);
    }

    public function testGetProjectConfigPath(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('.git');

        $path = $this->gitRepository->getProjectConfigPath();

        $this->assertSame('.git/stud.config', $path);
    }

    public function testGetProjectConfigPathWithTrailingSlash(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('.git/');

        $path = $this->gitRepository->getProjectConfigPath();

        $this->assertSame('.git/stud.config', $path);
    }

    public function testGetProjectConfigPathThrowsExceptionIfNotGitRepo(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not in a git repository.');

        $this->gitRepository->getProjectConfigPath();
    }

    public function testReadProjectConfigReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        // Mock getProjectConfigPath to return a non-existent file path in in-memory filesystem
        $configDir = '/test/git-dir';

        // We need to mock the getProjectConfigPath call
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn($configDir);

        $config = $this->gitRepository->readProjectConfig();

        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    public function testReadProjectConfigReturnsParsedConfig(): void
    {
        $configDir = '/test/git-dir';
        $configData = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];

        // Create config file in in-memory filesystem
        $expectedPath = $configDir . '/stud.config';
        $this->flysystem->write($expectedPath, \Symfony\Component\Yaml\Yaml::dump($configData));

        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn($configDir);

        $config = $this->gitRepository->readProjectConfig();

        $this->assertIsArray($config);
        $this->assertSame('TEST', $config['projectKey']);
        $this->assertSame(11, $config['transitionId']);
    }

    public function testWriteProjectConfig(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        // Create directory in in-memory filesystem
        $this->flysystem->createDirectory($configDir);

        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn($configDir);

        $config = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($config);

        $this->assertTrue($this->fileSystem->fileExists($configPath));
        $parsed = $this->fileSystem->parseFile($configPath);
        $this->assertSame('TEST', $parsed['projectKey']);
        $this->assertSame(11, $parsed['transitionId']);
    }

    public function testWriteProjectConfigPreservesMigrationVersion(): void
    {
        // Test that writeProjectConfig preserves migration_version from existing config
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);

        // Create existing config with migration_version
        $existingConfig = [
            'projectKey' => 'OLD',
            'migration_version' => '202501150000001',
        ];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($existingConfig));

        // writeProjectConfig calls getProjectConfigPath() which calls git rev-parse
        // and also calls readProjectConfig() which also calls getProjectConfigPath()
        // So we need to expect 2 calls
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->exactly(2))
            ->method('run');
        $process->expects($this->exactly(2))
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->exactly(2))
            ->method('getOutput')
            ->willReturn($configDir);

        $newConfig = [
            'projectKey' => 'NEW',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($newConfig);

        $parsed = $this->fileSystem->parseFile($configPath);
        $this->assertSame('NEW', $parsed['projectKey']);
        $this->assertSame(11, $parsed['transitionId']);
        // migration_version should be preserved
        $this->assertSame('202501150000001', $parsed['migration_version']);
    }

    public function testWriteProjectConfigDoesNotAddMigrationVersionWhenNotExists(): void
    {
        // Test that writeProjectConfig doesn't add migration_version if it doesn't exist
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);

        // Create existing config without migration_version
        $existingConfig = [
            'projectKey' => 'OLD',
        ];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($existingConfig));

        // writeProjectConfig calls getProjectConfigPath() which calls git rev-parse
        // and also calls readProjectConfig() which also calls getProjectConfigPath()
        // So we need to expect 2 calls
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->exactly(2))
            ->method('run');
        $process->expects($this->exactly(2))
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->exactly(2))
            ->method('getOutput')
            ->willReturn($configDir);

        $newConfig = [
            'projectKey' => 'NEW',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($newConfig);

        $parsed = $this->fileSystem->parseFile($configPath);
        $this->assertSame('NEW', $parsed['projectKey']);
        $this->assertSame(11, $parsed['transitionId']);
        // migration_version should not be present
        $this->assertArrayNotHasKey('migration_version', $parsed);
    }

    public function testWriteProjectConfigThrowsExceptionWhenDirectoryDoesNotExist(): void
    {
        $nonExistentDir = '/test/nonexistent-dir';

        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn($nonExistentDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Git directory not found: {$nonExistentDir}");

        $config = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($config);
    }

    public function testReadProjectConfigReturnsEmptyArrayWhenParseFileThrowsException(): void
    {
        // Test with a file that exists but causes parse error in FileSystem
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        // Create a mock FileSystem that throws an exception when parseFile is called
        $mockFileSystem = $this->createMock(FileSystem::class);
        $mockFileSystem->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);
        $mockFileSystem->expects($this->once())
            ->method('parseFile')
            ->willThrowException(new \Exception('Parse error'));

        $gitRepository = new GitRepository($this->processFactory, $mockFileSystem);

        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn($configDir);

        $config = $gitRepository->readProjectConfig();

        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    public function testGetProjectKeyFromIssueKey(): void
    {
        $this->assertSame('TEST', $this->gitRepository->getProjectKeyFromIssueKey('TEST-123'));
        $this->assertSame('PROJ', $this->gitRepository->getProjectKeyFromIssueKey('PROJ-456'));
        $this->assertSame('ABC', $this->gitRepository->getProjectKeyFromIssueKey('abc-789'));
    }

    public function testGetProjectKeyFromIssueKeyThrowsExceptionForInvalidFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid Jira issue key format: INVALID');

        $this->gitRepository->getProjectKeyFromIssueKey('INVALID');
    }

    public function testPullWithRebase(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git pull --rebase origin feat/PROJ-123')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->pullWithRebase('origin', 'feat/PROJ-123');
    }

    public function testGetGitProviderReturnsConfiguredProvider(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['gitProvider' => 'github'];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($config));

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            }

            return $process;
        });

        $result = $this->gitRepository->getGitProvider();

        $this->assertSame('github', $result);
    }

    public function testGetGitProviderReturnsNullWhenNotConfigured(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump([]));

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'config --get remote.origin.url')) {
                $process->method('getOutput')->willReturn('file:///path/to/repo.git');
            }

            return $process;
        });

        $result = $this->gitRepository->getGitProvider();

        $this->assertNull($result);
    }

    public function testGetGitProviderReturnsAutoDetectedProvider(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump([]));

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'config --get remote.origin.url')) {
                $process->method('getOutput')->willReturn('git@github.com:owner/repo.git');
            }

            return $process;
        });

        $result = $this->gitRepository->getGitProvider();

        $this->assertSame('github', $result);
    }

    public function testRebaseAutosquashHandlesCleanupErrorForTempScript(): void
    {
        // Test line 148: cleanup error catch block when deleting temp script fails
        $baseSha = 'abc123';

        $process = $this->createMock(Process::class);
        $process->method('mustRun');

        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git rebase -i --autosquash {$baseSha}")
            ->willReturn($process);

        // Mock FileSystem to throw RuntimeException on delete for temp script
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->method('fileExists')->willReturnCallback(function ($path) {
            // Return true for backup file, false for temp script (already deleted)
            return str_ends_with($path, '.bak');
        });
        $fileSystem->method('filePutContents')->willReturnCallback(function ($path, $contents) {
            // Allow writing temp script
        });
        $fileSystem->method('dirname')->willReturnCallback(function ($path) {
            return dirname($path);
        });

        // Make delete() throw RuntimeException for temp script (line 148)
        $fileSystem->expects($this->atLeastOnce())
            ->method('delete')
            ->willReturnCallback(function ($path) {
                if (! str_ends_with($path, '.bak')) {
                    // Throw exception for temp script deletion
                    throw new \RuntimeException('Cleanup error');
                }
            });

        $gitRepository = new GitRepository($this->processFactory, $fileSystem);

        // Should not throw exception - cleanup errors are caught and ignored
        $gitRepository->rebaseAutosquash($baseSha);
    }

    public function testRebaseAutosquashHandlesCleanupErrorForBackupFile(): void
    {
        // Test line 155: cleanup error catch block when deleting backup file fails
        $baseSha = 'abc123';

        $process = $this->createMock(Process::class);
        $process->method('mustRun');

        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git rebase -i --autosquash {$baseSha}")
            ->willReturn($process);

        // Mock FileSystem to throw RuntimeException on delete for backup file
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->method('fileExists')->willReturn(true); // Backup file exists
        $fileSystem->method('filePutContents')->willReturnCallback(function ($path, $contents) {
            // Allow writing temp script
        });
        $fileSystem->method('dirname')->willReturnCallback(function ($path) {
            return dirname($path);
        });

        // Make delete() throw RuntimeException for backup file (line 155)
        $fileSystem->expects($this->atLeastOnce())
            ->method('delete')
            ->willReturnCallback(function ($path) {
                if (str_ends_with($path, '.bak')) {
                    // Throw exception for backup file deletion
                    throw new \RuntimeException('Cleanup error');
                }
            });

        $gitRepository = new GitRepository($this->processFactory, $fileSystem);

        // Should not throw exception - cleanup errors are caught and ignored
        $gitRepository->rebaseAutosquash($baseSha);
    }

    public function testTryRebaseReturnsTrue(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rebase origin/develop')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->method('isSuccessful')
            ->willReturn(true);

        $this->assertTrue($this->gitRepository->tryRebase('origin/develop'));
    }

    public function testTryRebaseReturnsFalseOnConflict(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rebase origin/develop')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->method('isSuccessful')
            ->willReturn(false);

        $this->assertFalse($this->gitRepository->tryRebase('origin/develop'));
    }

    public function testRebaseAbort(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rebase --abort')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->rebaseAbort();
    }

    public function testIsAncestorReturnsTrue(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git merge-base --is-ancestor origin/develop HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->method('isSuccessful')
            ->willReturn(true);

        $this->assertTrue($this->gitRepository->isAncestor('origin/develop', 'HEAD'));
    }

    public function testIsAncestorReturnsFalse(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git merge-base --is-ancestor origin/develop HEAD')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->method('isSuccessful')
            ->willReturn(false);

        $this->assertFalse($this->gitRepository->isAncestor('origin/develop', 'HEAD'));
    }
}
