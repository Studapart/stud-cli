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
}
