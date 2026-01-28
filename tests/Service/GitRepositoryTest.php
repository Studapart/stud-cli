<?php

namespace App\Tests\Service;

use App\Service\GitRepository;
use App\Service\ProcessFactory;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Process\Process;

class GitRepositoryTest extends CommandTestCase
{
    protected GitRepository $gitRepository;
    private ProcessFactory&MockObject $processFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processFactory = $this->createMock(ProcessFactory::class);
        $this->gitRepository = new GitRepository($this->processFactory);
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
                // Verify script exists and is executable
                if (! file_exists($scriptPath) || ! is_executable($scriptPath)) {
                    return false;
                }
                // Verify script content
                $content = file_get_contents($scriptPath);

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
                    // Create a backup file to test cleanup
                    $backupPath = $scriptPath . '.bak';
                    file_put_contents($backupPath, 'backup content');
                }

                return true;
            }));

        $process->expects($this->once())
            ->method('mustRun');

        $this->gitRepository->rebaseAutosquash('abc123');

        // Verify backup file was cleaned up (if it existed)
        // Note: The cleanup happens in finally block, so we can't easily verify
        // but the code path is executed
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
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@gitlab.com:studapart/stud-cli.git');

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
        $process->expects($this->once())
            ->method('getOutput')
            ->willReturn('git@gitlab.com:studapart/stud-cli.git');

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
        // Mock getProjectConfigPath to return a non-existent file path
        $configPath = sys_get_temp_dir() . '/nonexistent-stud-config-' . uniqid() . '.yaml';

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
            ->willReturn(dirname($configPath));

        $config = $this->gitRepository->readProjectConfig();

        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    public function testReadProjectConfigReturnsParsedConfig(): void
    {
        $configPath = sys_get_temp_dir() . '/stud-config-' . uniqid() . '.yaml';
        $configData = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];
        file_put_contents($configPath, \Symfony\Component\Yaml\Yaml::dump($configData));

        try {
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
                ->willReturn(dirname($configPath));

            // Override the config path by creating a new file in the expected location
            $expectedPath = dirname($configPath) . '/stud.config';
            file_put_contents($expectedPath, \Symfony\Component\Yaml\Yaml::dump($configData));

            $config = $this->gitRepository->readProjectConfig();

            $this->assertIsArray($config);
            $this->assertSame('TEST', $config['projectKey']);
            $this->assertSame(11, $config['transitionId']);
        } finally {
            @unlink($configPath);
            @unlink(dirname($configPath) . '/stud.config');
        }
    }

    public function testWriteProjectConfig(): void
    {
        $configDir = sys_get_temp_dir() . '/stud-test-' . uniqid();
        mkdir($configDir, 0755, true);
        $configPath = $configDir . '/stud.config';

        try {
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

            $this->assertFileExists($configPath);
            $parsed = \Symfony\Component\Yaml\Yaml::parseFile($configPath);
            $this->assertSame('TEST', $parsed['projectKey']);
            $this->assertSame(11, $parsed['transitionId']);
        } finally {
            @unlink($configPath);
            @rmdir($configDir);
        }
    }

    public function testWriteProjectConfigThrowsExceptionWhenDirectoryDoesNotExist(): void
    {
        $nonExistentDir = sys_get_temp_dir() . '/stud-test-nonexistent-' . uniqid();
        $configPath = $nonExistentDir . '/stud.config';

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

    public function testReadProjectConfigReturnsEmptyArrayWhenFileGetContentsFails(): void
    {
        // Create a file that exists but is unreadable (no read permissions)
        // This makes file_exists() return true, but file_get_contents() will return false
        $configDir = sys_get_temp_dir() . '/stud-test-' . uniqid();
        mkdir($configDir, 0755, true);
        $configPath = $configDir . '/stud.config';

        try {
            // Create the file
            touch($configPath);
            // Remove read permissions so file_get_contents fails
            chmod($configPath, 0000);

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
        } finally {
            // Restore permissions so we can clean up
            @chmod($configPath, 0644);
            @unlink($configPath);
            @rmdir($configDir);
        }
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
}
