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

    public function testRenameLocalBranchRenamesCurrentBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process) {
                if (str_contains($command, 'rev-parse')) {
                    $process->method('getOutput')->willReturn('old-branch');
                    $process->method('isSuccessful')->willReturn(true);
                } else {
                    $process->method('mustRun');
                }

                return $process;
            });

        $this->gitRepository->renameLocalBranch('old-branch', 'new-branch');
    }

    public function testRenameLocalBranchRenamesDifferentBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process) {
                if (str_contains($command, 'rev-parse')) {
                    $process->method('getOutput')->willReturn('current-branch');
                    $process->method('isSuccessful')->willReturn(true);
                } else {
                    $process->method('mustRun');
                }

                return $process;
            });

        $this->gitRepository->renameLocalBranch('old-branch', 'new-branch');
    }

    public function testRenameRemoteBranch(): void
    {
        $process = $this->createMock(Process::class);
        $callCount = 0;
        $this->processFactory->expects($this->exactly(5))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First call: localBranchExists() -> git rev-parse --verify --quiet old-branch
                    $this->assertStringContainsString('git rev-parse --verify --quiet old-branch', $command);
                    $process->method('isSuccessful')->willReturn(true);
                } elseif ($callCount === 2) {
                    // Second call: git push origin old-branch:new-branch
                    $this->assertStringContainsString('git push origin old-branch:new-branch', $command);
                } elseif ($callCount === 3) {
                    // Third call: git push origin -u old-branch:new-branch
                    $this->assertStringContainsString('git push origin -u old-branch:new-branch', $command);
                } elseif ($callCount === 4) {
                    // Fourth call: git push origin --delete old-branch
                    $this->assertStringContainsString('git push origin --delete old-branch', $command);
                } elseif ($callCount === 5) {
                    // Fifth call: git branch --set-upstream-to=origin/new-branch old-branch
                    $this->assertStringContainsString('git branch --set-upstream-to=origin/new-branch old-branch', $command);
                }
                $process->method('mustRun');
                if ($callCount > 1) {
                    $process->method('isSuccessful')->willReturn(true);
                }

                return $process;
            });

        $this->gitRepository->renameRemoteBranch('old-branch', 'new-branch', 'origin');
    }

    public function testRenameRemoteBranchWhenLocalAlreadyRenamed(): void
    {
        $process = $this->createMock(Process::class);
        $callCount = 0;
        $this->processFactory->expects($this->exactly(5))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First call: localBranchExists() -> git rev-parse --verify --quiet old-branch
                    // Returns false because local branch was already renamed
                    $this->assertStringContainsString('git rev-parse --verify --quiet old-branch', $command);
                    $process->method('isSuccessful')->willReturn(false);
                } elseif ($callCount === 2) {
                    // Second call: git push origin new-branch:new-branch (or just new-branch)
                    $this->assertStringContainsString('git push origin new-branch', $command);
                } elseif ($callCount === 3) {
                    // Third call: git push origin -u new-branch:new-branch (or just new-branch)
                    $this->assertStringContainsString('git push origin -u new-branch', $command);
                } elseif ($callCount === 4) {
                    // Fourth call: git push origin --delete old-branch
                    $this->assertStringContainsString('git push origin --delete old-branch', $command);
                } elseif ($callCount === 5) {
                    // Fifth call: git branch --set-upstream-to=origin/new-branch new-branch
                    $this->assertStringContainsString('git branch --set-upstream-to=origin/new-branch new-branch', $command);
                }
                $process->method('mustRun');
                if ($callCount > 1) {
                    $process->method('isSuccessful')->willReturn(true);
                }

                return $process;
            });

        $this->gitRepository->renameRemoteBranch('old-branch', 'new-branch', 'origin');
    }

    public function testGetBranchCommitsAhead(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-list --count base..branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('5');

        $result = $this->gitRepository->getBranchCommitsAhead('branch', 'base');

        $this->assertSame(5, $result);
    }

    public function testGetBranchCommitsAheadReturnsZeroOnFailure(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $result = $this->gitRepository->getBranchCommitsAhead('branch', 'base');

        $this->assertSame(0, $result);
    }

    public function testGetBranchCommitsBehind(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-list --count branch..base')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('3');

        $result = $this->gitRepository->getBranchCommitsBehind('branch', 'base');

        $this->assertSame(3, $result);
    }

    public function testGetBranchCommitsBehindReturnsZeroOnFailure(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $result = $this->gitRepository->getBranchCommitsBehind('branch', 'base');

        $this->assertSame(0, $result);
    }

    public function testCanRebaseBranchReturnsTrueWhenAncestor(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git merge-base --is-ancestor onto branch')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);

        $result = $this->gitRepository->canRebaseBranch('branch', 'onto');

        $this->assertTrue($result);
    }

    public function testCanRebaseBranchFallsBackToDryRun(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);
        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2) {
                if (str_contains($command, 'merge-base')) {
                    $process1->method('run');
                    $process1->method('isSuccessful')->willReturn(false);

                    return $process1;
                } else {
                    $process2->method('run');
                    $process2->method('isSuccessful')->willReturn(true);

                    return $process2;
                }
            });

        $result = $this->gitRepository->canRebaseBranch('branch', 'onto');

        $this->assertTrue($result);
    }

    public function testCanRebaseBranchReturnsFalseWhenBothFail(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);
        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2) {
                if (str_contains($command, 'merge-base')) {
                    $process1->method('run');
                    $process1->method('isSuccessful')->willReturn(false);

                    return $process1;
                } else {
                    $process2->method('run');
                    $process2->method('isSuccessful')->willReturn(false);

                    return $process2;
                }
            });

        $result = $this->gitRepository->canRebaseBranch('branch', 'onto');

        $this->assertFalse($result);
    }

    public function testSwitchBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git switch feat/PROJ-123-title')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->switchBranch('feat/PROJ-123-title');
    }

    public function testSwitchToRemoteBranch(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git switch -c feat/PROJ-123-title origin/feat/PROJ-123-title')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->switchToRemoteBranch('feat/PROJ-123-title');
    }

    public function testSwitchToRemoteBranchWithCustomRemote(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git switch -c feat/PROJ-123-title upstream/feat/PROJ-123-title')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->switchToRemoteBranch('feat/PROJ-123-title', 'upstream');
    }

    public function testFindBranchesByIssueKeyReturnsLocalBranches(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);
        $process3 = $this->createMock(Process::class);
        $process4 = $this->createMock(Process::class);
        $process5 = $this->createMock(Process::class);
        $process6 = $this->createMock(Process::class);

        $this->processFactory->expects($this->exactly(6))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2, $process3, $process4, $process5, $process6) {
                if (str_contains($command, "git branch --list 'feat/PROJ-123-*'")) {
                    $process1->expects($this->once())->method('run');
                    $process1->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process1->expects($this->once())->method('getOutput')->willReturn("  feat/PROJ-123-title\n");

                    return $process1;
                }
                if (str_contains($command, "git branch --list 'fix/PROJ-123-*'")) {
                    $process2->expects($this->once())->method('run');
                    $process2->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process2->expects($this->once())->method('getOutput')->willReturn('');

                    return $process2;
                }
                if (str_contains($command, "git branch --list 'chore/PROJ-123-*'")) {
                    $process3->expects($this->once())->method('run');
                    $process3->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process3->expects($this->once())->method('getOutput')->willReturn('');

                    return $process3;
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/feat/PROJ-123-*'")) {
                    $process4->expects($this->once())->method('run');
                    $process4->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process4->expects($this->once())->method('getOutput')->willReturn('');

                    return $process4;
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/fix/PROJ-123-*'")) {
                    $process5->expects($this->once())->method('run');
                    $process5->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process5->expects($this->once())->method('getOutput')->willReturn('');

                    return $process5;
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/chore/PROJ-123-*'")) {
                    $process6->expects($this->once())->method('run');
                    $process6->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process6->expects($this->once())->method('getOutput')->willReturn('');

                    return $process6;
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitRepository->findBranchesByIssueKey('PROJ-123');

        $this->assertSame(['feat/PROJ-123-title'], $result['local']);
        $this->assertSame([], $result['remote']);
    }

    public function testFindBranchesByIssueKeyReturnsRemoteBranches(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);
        $process3 = $this->createMock(Process::class);
        $process4 = $this->createMock(Process::class);
        $process5 = $this->createMock(Process::class);
        $process6 = $this->createMock(Process::class);

        $this->processFactory->expects($this->exactly(6))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2, $process3, $process4, $process5, $process6) {
                if (str_contains($command, "git branch --list 'feat/PROJ-123-*'")) {
                    $process1->expects($this->once())->method('run');
                    $process1->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process1->expects($this->once())->method('getOutput')->willReturn('');

                    return $process1;
                }
                if (str_contains($command, "git branch --list 'fix/PROJ-123-*'")) {
                    $process2->expects($this->once())->method('run');
                    $process2->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process2->expects($this->once())->method('getOutput')->willReturn('');

                    return $process2;
                }
                if (str_contains($command, "git branch --list 'chore/PROJ-123-*'")) {
                    $process3->expects($this->once())->method('run');
                    $process3->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process3->expects($this->once())->method('getOutput')->willReturn('');

                    return $process3;
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/feat/PROJ-123-*'")) {
                    $process4->expects($this->once())->method('run');
                    $process4->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process4->expects($this->once())->method('getOutput')->willReturn("abc123\trefs/heads/feat/PROJ-123-title\n");

                    return $process4;
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/fix/PROJ-123-*'")) {
                    $process5->expects($this->once())->method('run');
                    $process5->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process5->expects($this->once())->method('getOutput')->willReturn('');

                    return $process5;
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/chore/PROJ-123-*'")) {
                    $process6->expects($this->once())->method('run');
                    $process6->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process6->expects($this->once())->method('getOutput')->willReturn('');

                    return $process6;
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitRepository->findBranchesByIssueKey('PROJ-123');

        $this->assertSame([], $result['local']);
        $this->assertSame(['feat/PROJ-123-title'], $result['remote']);
    }

    public function testGetBranchStatus(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);
        $process3 = $this->createMock(Process::class);
        $process4 = $this->createMock(Process::class);

        $this->processFactory->expects($this->exactly(4))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2, $process3, $process4) {
                if (str_contains($command, 'git rev-list --count origin/feat/PROJ-123..feat/PROJ-123')) {
                    $process1->expects($this->once())->method('run');
                    $process1->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process1->expects($this->once())->method('getOutput')->willReturn("2\n");

                    return $process1;
                }
                if (str_contains($command, 'git rev-list --count feat/PROJ-123..origin/feat/PROJ-123')) {
                    $process2->expects($this->once())->method('run');
                    $process2->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process2->expects($this->once())->method('getOutput')->willReturn("1\n");

                    return $process2;
                }
                if (str_contains($command, 'git rev-list --count develop..feat/PROJ-123')) {
                    $process3->expects($this->once())->method('run');
                    $process3->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process3->expects($this->once())->method('getOutput')->willReturn("5\n");

                    return $process3;
                }
                if (str_contains($command, 'git rev-list --count feat/PROJ-123..develop')) {
                    $process4->expects($this->once())->method('run');
                    $process4->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process4->expects($this->once())->method('getOutput')->willReturn("3\n");

                    return $process4;
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitRepository->getBranchStatus('feat/PROJ-123', 'develop', 'origin/feat/PROJ-123');

        $this->assertSame(1, $result['behind_remote']);
        $this->assertSame(2, $result['ahead_remote']);
        $this->assertSame(3, $result['behind_base']);
        $this->assertSame(5, $result['ahead_base']);
    }

    public function testGetBranchStatusWithoutRemote(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);

        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2) {
                if (str_contains($command, 'git rev-list --count develop..feat/PROJ-123')) {
                    $process1->expects($this->once())->method('run');
                    $process1->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process1->expects($this->once())->method('getOutput')->willReturn("5\n");

                    return $process1;
                }
                if (str_contains($command, 'git rev-list --count feat/PROJ-123..develop')) {
                    $process2->expects($this->once())->method('run');
                    $process2->expects($this->once())->method('isSuccessful')->willReturn(true);
                    $process2->expects($this->once())->method('getOutput')->willReturn("3\n");

                    return $process2;
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitRepository->getBranchStatus('feat/PROJ-123', 'develop', null);

        $this->assertSame(0, $result['behind_remote']);
        $this->assertSame(0, $result['ahead_remote']);
        $this->assertSame(3, $result['behind_base']);
        $this->assertSame(5, $result['ahead_base']);
    }

    public function testIsBranchBasedOnReturnsTrue(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);

        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2) {
                if (str_contains($command, 'git merge-base develop feat/PROJ-123')) {
                    $process1->expects($this->once())->method('mustRun');
                    $process1->expects($this->once())->method('getOutput')->willReturn("abc123\n");

                    return $process1;
                }
                if (str_contains($command, 'git rev-parse develop')) {
                    $process2->expects($this->once())->method('mustRun');
                    $process2->expects($this->once())->method('getOutput')->willReturn("abc123\n");

                    return $process2;
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitRepository->isBranchBasedOn('feat/PROJ-123', 'develop');

        $this->assertTrue($result);
    }

    public function testIsBranchBasedOnReturnsFalse(): void
    {
        $process1 = $this->createMock(Process::class);
        $process2 = $this->createMock(Process::class);

        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2) {
                if (str_contains($command, 'git merge-base develop feat/PROJ-123')) {
                    $process1->expects($this->once())->method('mustRun');
                    $process1->expects($this->once())->method('getOutput')->willReturn("abc123\n");

                    return $process1;
                }
                if (str_contains($command, 'git rev-parse develop')) {
                    $process2->expects($this->once())->method('mustRun');
                    $process2->expects($this->once())->method('getOutput')->willReturn("def456\n");

                    return $process2;
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitRepository->isBranchBasedOn('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testIsBranchBasedOnReturnsFalseOnException(): void
    {
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git merge-base develop feat/PROJ-123')
            ->willThrowException(new \RuntimeException('Branch not found'));

        $result = $this->gitRepository->isBranchBasedOn('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
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

    public function testGetAllLocalBranches(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with("git branch --format='%(refname:short)'")
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("develop\nfeat/PROJ-123\nmain");

        $result = $this->gitRepository->getAllLocalBranches();

        $this->assertSame(['develop', 'feat/PROJ-123', 'main'], $result);
    }

    public function testGetAllLocalBranchesReturnsEmptyArrayOnFailure(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $result = $this->gitRepository->getAllLocalBranches();

        $this->assertSame([], $result);
    }

    public function testGetAllLocalBranchesReturnsEmptyArrayOnEmptyOutput(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $result = $this->gitRepository->getAllLocalBranches();

        $this->assertSame([], $result);
    }

    public function testIsBranchMergedIntoReturnsTrue(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git branch --merged develop')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("  develop\n* feat/PROJ-123\n  main\n");

        $result = $this->gitRepository->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertTrue($result);
    }

    public function testIsBranchMergedIntoReturnsFalse(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git branch --merged develop')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("  develop\n  main\n");

        $result = $this->gitRepository->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testIsBranchMergedIntoReturnsFalseOnFailure(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $result = $this->gitRepository->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testIsBranchMergedIntoReturnsFalseOnEmptyOutput(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git branch --merged develop')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn(''); // Empty output

        $result = $this->gitRepository->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testGetAllRemoteBranches(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git ls-remote --heads origin')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("abc123\trefs/heads/develop\n def456\trefs/heads/feat/PROJ-123\n");

        $result = $this->gitRepository->getAllRemoteBranches('origin');

        $this->assertSame(['develop', 'feat/PROJ-123'], $result);
    }

    public function testGetAllRemoteBranchesReturnsEmptyArrayOnFailure(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(false);

        $result = $this->gitRepository->getAllRemoteBranches('origin');

        $this->assertSame([], $result);
    }

    public function testGetAllRemoteBranchesReturnsEmptyArrayOnEmptyOutput(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $result = $this->gitRepository->getAllRemoteBranches('origin');

        $this->assertSame([], $result);
    }

    public function testDetectBaseBranchReturnsDevelopWhenPresent(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git ls-remote --heads origin')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("abc123\trefs/heads/develop\n");

        $reflection = new \ReflectionClass($this->gitRepository);
        $method = $reflection->getMethod('detectBaseBranch');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitRepository);

        $this->assertSame('develop', $result);
    }

    public function testDetectBaseBranchReturnsMainWhenDevelopNotPresent(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git ls-remote --heads origin')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("abc123\trefs/heads/main\n");

        $reflection = new \ReflectionClass($this->gitRepository);
        $method = $reflection->getMethod('detectBaseBranch');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitRepository);

        $this->assertSame('main', $result);
    }

    public function testDetectBaseBranchReturnsNullWhenNoCandidatesFound(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git ls-remote --heads origin')
            ->willReturn($process);

        $process->expects($this->once())
            ->method('run');
        $process->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn("abc123\trefs/heads/feature-branch\n");

        $reflection = new \ReflectionClass($this->gitRepository);
        $method = $reflection->getMethod('detectBaseBranch');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitRepository);

        $this->assertNull($result);
    }

    public function testGetBaseBranchReturnsFromConfig(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['baseBranch' => 'main'];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($config));

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

        $reflection = new \ReflectionClass($this->gitRepository);
        $method = $reflection->getMethod('getBaseBranch');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitRepository);

        $this->assertSame('origin/main', $result);
    }

    public function testGetBaseBranchReturnsFromConfigWithOriginPrefix(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['baseBranch' => 'origin/main'];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($config));

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

        $reflection = new \ReflectionClass($this->gitRepository);
        $method = $reflection->getMethod('getBaseBranch');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitRepository);

        $this->assertSame('origin/main', $result);
    }

    public function testGetBaseBranchAutoDetectsWhenNotInConfig(): void
    {
        $configDir = '/test/git-dir';
        $this->flysystem->createDirectory($configDir);

        $process1 = $this->createMock(Process::class);
        $process1->expects($this->once())
            ->method('run');
        $process1->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process1->expects($this->once())
            ->method('getOutput')
            ->willReturn($configDir);

        $process2 = $this->createMock(Process::class);
        $process2->expects($this->once())
            ->method('run');
        $process2->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process2->expects($this->once())
            ->method('getOutput')
            ->willReturn("abc123\trefs/heads/develop\n");

        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2) {
                if (str_contains($command, 'rev-parse')) {
                    return $process1;
                } elseif (str_contains($command, 'ls-remote')) {
                    return $process2;
                }

                return $process1;
            });

        $reflection = new \ReflectionClass($this->gitRepository);
        $method = $reflection->getMethod('getBaseBranch');
        $method->setAccessible(true);

        $result = $method->invoke($this->gitRepository);

        $this->assertSame('origin/develop', $result);
    }

    public function testGetBaseBranchThrowsExceptionWhenNotConfiguredAndCannotDetect(): void
    {
        $configDir = '/test/git-dir';
        $this->flysystem->createDirectory($configDir);
        $process1 = $this->createMock(Process::class);
        $process1->expects($this->once())
            ->method('run');
        $process1->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process1->expects($this->once())
            ->method('getOutput')
            ->willReturn($configDir);

        $process2 = $this->createMock(Process::class);
        $process2->expects($this->once())
            ->method('run');
        $process2->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $process2->expects($this->once())
            ->method('getOutput')
            ->willReturn('');

        $this->processFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function ($command) use ($process1, $process2) {
                if (str_contains($command, 'rev-parse')) {
                    return $process1;
                } elseif (str_contains($command, 'ls-remote')) {
                    return $process2;
                }

                return $process1;
            });

        $reflection = new \ReflectionClass($this->gitRepository);
        $method = $reflection->getMethod('getBaseBranch');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Base branch not configured and could not be auto-detected.');

        $method->invoke($this->gitRepository);
    }

    public function testEnsureBaseBranchConfiguredReturnsConfiguredBranchWhenValid(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['baseBranch' => 'main'];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($config));

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'ls-remote')) {
                $process->method('getOutput')->willReturn("abc123\trefs/heads/main\n");
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);

        $this->assertSame('origin/main', $result);
    }

    public function testEnsureBaseBranchConfiguredReturnsConfiguredBranchWithOriginPrefix(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['baseBranch' => 'origin/main'];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($config));

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'ls-remote')) {
                $process->method('getOutput')->willReturn("abc123\trefs/heads/main\n");
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);

        $this->assertSame('origin/main', $result);
    }

    public function testEnsureBaseBranchConfiguredPromptsWhenConfiguredBranchInvalid(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['baseBranch' => 'nonexistent'];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($config));

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'ls-remote --heads origin nonexistent')) {
                $process->method('getOutput')->willReturn(''); // remoteBranchExists returns false
            } elseif (str_contains($command, 'ls-remote --heads origin')) {
                $process->method('getOutput')->willReturn("abc123\trefs/heads/develop\n"); // detectBaseBranch finds develop
            } elseif (str_contains($command, 'ls-remote --heads origin develop')) {
                $process->method('getOutput')->willReturn("abc123\trefs/heads/develop\n"); // remoteBranchExists confirms develop
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('warning');
        $logger->method('note');
        $logger->method('text');
        $logger->method('success');
        $logger->method('ask')->willReturn('develop');
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);

        $this->assertSame('origin/develop', $result);
        $savedConfig = $this->fileSystem->parseFile($configPath);
        $this->assertSame('develop', $savedConfig['baseBranch']);
    }

    public function testEnsureBaseBranchConfiguredPromptsWhenNotConfiguredWithAutoDetection(): void
    {
        $configDir = '/test/git-dir';
        $this->flysystem->createDirectory($configDir);

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'ls-remote --heads origin') && ! str_contains($command, 'main')) {
                $process->method('getOutput')->willReturn("abc123\trefs/heads/main\n"); // detectBaseBranch finds main
            } elseif (str_contains($command, 'ls-remote --heads origin main')) {
                $process->method('getOutput')->willReturn("abc123\trefs/heads/main\n"); // remoteBranchExists confirms main
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('text');
        $logger->method('success');
        $logger->method('ask')->willReturn('main');
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);

        $this->assertSame('origin/main', $result);
    }

    public function testEnsureBaseBranchConfiguredThrowsWhenNotInGitRepo(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run');
        $process->method('isSuccessful')->willReturn(false);

        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git rev-parse --git-dir')
            ->willReturn($process);

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturn('config.base_branch_required');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.base_branch_required');

        $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);
    }

    public function testEnsureBaseBranchConfiguredThrowsWhenUserEntersInvalidBranch(): void
    {
        $configDir = '/test/git-dir';
        $this->flysystem->createDirectory($configDir);

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'ls-remote --heads origin') && ! str_contains($command, 'invalid-branch')) {
                $process->method('getOutput')->willReturn(''); // detectBaseBranch finds nothing
            } elseif (str_contains($command, 'ls-remote --heads origin invalid-branch')) {
                $process->method('getOutput')->willReturn(''); // remoteBranchExists returns false
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('ask')->willReturn('invalid-branch');
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.base_branch_invalid');

        $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);
    }

    public function testEnsureBaseBranchConfiguredThrowsWhenUserEntersEmptyBranch(): void
    {
        $configDir = '/test/git-dir';
        $this->flysystem->createDirectory($configDir);

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'ls-remote --heads origin')) {
                $process->method('getOutput')->willReturn(''); // detectBaseBranch finds nothing
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('ask')->willReturn(null);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.base_branch_required');

        $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);
    }

    public function testEnsureBaseBranchConfiguredValidatorRejectsEmptyInput(): void
    {
        $configDir = '/test/git-dir';
        $this->flysystem->createDirectory($configDir);

        $this->processFactory->method('create')->willReturnCallback(function ($command) use ($configDir) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(true);
            if (str_contains($command, 'rev-parse')) {
                $process->method('getOutput')->willReturn($configDir);
            } elseif (str_contains($command, 'ls-remote --heads origin develop')) {
                $process->method('getOutput')->willReturn("abc123\trefs/heads/develop\n");
            } elseif (str_contains($command, 'ls-remote --heads origin')) {
                $process->method('getOutput')->willReturn(''); // detectBaseBranch finds nothing
            }

            return $process;
        });

        // Use real Logger to actually execute the validator closure
        // We need to test the validator with empty input to cover lines 901-902
        // Create a custom IO that will call the validator with empty string
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('text');
        $logger->method('success');
        // Mock ask() to actually call the validator with empty string first (to cover lines 901-902)
        // then with a valid string (to cover line 905)
        $logger->method('ask')->willReturnCallback(function ($question, $default, $validator) {
            if ($validator !== null) {
                // First call validator with empty string to cover lines 901-902
                try {
                    $validator('');
                    $this->fail('Validator should throw for empty string');
                } catch (\RuntimeException $e) {
                    $this->assertSame('Base branch name cannot be empty.', $e->getMessage());
                }

                // Then call with valid input to cover line 905
                return $validator('develop');
            }

            return 'develop';
        });

        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureBaseBranchConfigured($io, $logger, $translator);

        $this->assertSame('origin/develop', $result);
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

    public function testEnsureGitTokenConfiguredReturnsTokenFromProjectConfig(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['githubToken' => 'test_token'];
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

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);

        $result = $this->gitRepository->ensureGitTokenConfigured(
            'github',
            $io,
            $logger,
            $translator,
            []
        );

        $this->assertSame('test_token', $result);
    }

    public function testEnsureGitTokenConfiguredReturnsTokenFromGlobalConfig(): void
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
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);

        $globalConfig = ['GITHUB_TOKEN' => 'global_token'];

        $result = $this->gitRepository->ensureGitTokenConfigured(
            'github',
            $io,
            $logger,
            $translator,
            $globalConfig
        );

        $this->assertSame('global_token', $result);
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

    public function testEnsureGitProviderConfiguredReturnsConfiguredProvider(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $config = ['gitProvider' => 'gitlab'];
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

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureGitProviderConfigured($io, $logger, $translator);

        $this->assertSame('gitlab', $result);
    }

    public function testEnsureGitProviderConfiguredThrowsWhenNotInGitRepo(): void
    {
        $this->processFactory->method('create')->willReturnCallback(function ($command) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(false);

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturn('Git provider is required');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git provider is required');

        $this->gitRepository->ensureGitProviderConfigured($io, $logger, $translator);
    }

    public function testEnsureGitTokenConfiguredReturnsTokenFromGlobalConfigForGitLab(): void
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
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);

        $globalConfig = ['GITLAB_TOKEN' => 'gitlab_token'];

        $result = $this->gitRepository->ensureGitTokenConfigured(
            'gitlab',
            $io,
            $logger,
            $translator,
            $globalConfig
        );

        $this->assertSame('gitlab_token', $result);
    }

    public function testEnsureGitTokenConfiguredThrowsWhenNotInGitRepo(): void
    {
        $this->processFactory->method('create')->willReturnCallback(function ($command) {
            $process = $this->createMock(Process::class);
            $process->method('run');
            $process->method('isSuccessful')->willReturn(false);

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturn('Git token is required');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git token is required');

        $this->gitRepository->ensureGitTokenConfigured('github', $io, $logger, $translator, []);
    }

    public function testEnsureGitTokenConfiguredWarnsOnTokenTypeMismatch(): void
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
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $this->stringContains('Provider is set to')
            );
        $logger->expects($this->once())
            ->method('note')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $this->anything()
            );
        $logger->expects($this->once())
            ->method('askHidden')
            ->willReturn(null);

        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnCallback(function ($key, $params = []) {
            if ($key === 'config.git_token_type_mismatch') {
                return "Provider is set to '{$params['provider']}' but only {$params['opposite']} token is configured.";
            }

            return $key;
        });

        $globalConfig = ['GITLAB_TOKEN' => 'gitlab_token'];

        $result = $this->gitRepository->ensureGitTokenConfigured(
            'github',
            $io,
            $logger,
            $translator,
            $globalConfig
        );

        $this->assertNull($result);
    }

    public function testEnsureGitTokenConfiguredShowsGlobalSuggestionWhenNoTokens(): void
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
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->exactly(2))
            ->method('note')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $this->anything()
            );
        $logger->expects($this->once())
            ->method('askHidden')
            ->willReturn(null);

        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureGitTokenConfigured(
            'github',
            $io,
            $logger,
            $translator,
            []
        );

        $this->assertNull($result);
    }

    public function testEnsureGitProviderConfiguredPromptsWhenNotConfiguredWithAutoDetection(): void
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
                $process->method('getOutput')->willReturn('git@gitlab.com:owner/repo.git');
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('text');
        $logger->method('success');
        $logger->method('choice')->willReturn('gitlab');
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureGitProviderConfigured($io, $logger, $translator);

        $this->assertSame('gitlab', $result);
        $savedConfig = $this->fileSystem->parseFile($configPath);
        $this->assertSame('gitlab', $savedConfig['gitProvider']);
    }

    public function testEnsureGitProviderConfiguredPromptsWhenNotConfiguredWithoutAutoDetection(): void
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

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('text');
        $logger->method('success');
        $logger->method('choice')->willReturn('github');
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureGitProviderConfigured($io, $logger, $translator);

        $this->assertSame('github', $result);
        $savedConfig = $this->fileSystem->parseFile($configPath);
        $this->assertSame('github', $savedConfig['gitProvider']);
    }

    public function testEnsureGitProviderConfiguredThrowsWhenInvalidChoice(): void
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

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('choice')->willReturn(null);
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturn('Git provider is required');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git provider is required');

        $this->gitRepository->ensureGitProviderConfigured($io, $logger, $translator);
    }

    public function testEnsureGitTokenConfiguredPromptsAndSavesToken(): void
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
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('text');
        $logger->method('success');
        $logger->method('askHidden')->willReturn('new_token');
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureGitTokenConfigured(
            'github',
            $io,
            $logger,
            $translator,
            []
        );

        $this->assertSame('new_token', $result);
        $savedConfig = $this->fileSystem->parseFile($configPath);
        $this->assertSame('new_token', $savedConfig['githubToken']);
    }

    public function testEnsureGitTokenConfiguredReturnsNullWhenUserSkips(): void
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
            }

            return $process;
        });

        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('note');
        $logger->method('askHidden')->willReturn('');
        $translator = $this->createMock(\App\Service\TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);

        $result = $this->gitRepository->ensureGitTokenConfigured(
            'github',
            $io,
            $logger,
            $translator,
            []
        );

        $this->assertNull($result);
    }
}
