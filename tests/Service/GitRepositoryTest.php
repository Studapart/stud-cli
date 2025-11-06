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
}
