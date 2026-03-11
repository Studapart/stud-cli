<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitBranchService;
use App\Service\GitRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitBranchServiceTest extends TestCase
{
    private GitBranchService $gitBranchService;
    private GitRepository&MockObject $gitRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = new GitBranchService($this->gitRepository);
    }

    private function createSuccessProcess(string $output = ''): Process&MockObject
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($output);

        return $process;
    }

    private function createFailedProcess(): Process&MockObject
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        return $process;
    }

    public function testRenameLocalBranchRenamesCurrentBranch(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->once())
            ->method('run')
            ->with('git branch -m new-branch');

        $this->gitBranchService->renameLocalBranch('old-branch', 'new-branch');
    }

    public function testRenameLocalBranchRenamesDifferentBranch(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('current-branch');
        $this->gitRepository->expects($this->once())
            ->method('run')
            ->with('git branch -m old-branch new-branch');

        $this->gitBranchService->renameLocalBranch('old-branch', 'new-branch');
    }

    public function testRenameRemoteBranch(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('localBranchExists')
            ->with('old-branch')
            ->willReturn(true);

        $this->gitRepository->expects($this->exactly(4))
            ->method('run')
            ->willReturnCallback(function (string $command) {
                $expected = [
                    'git push origin old-branch:new-branch',
                    'git push origin -u old-branch:new-branch',
                    'git push origin --delete old-branch',
                    'git branch --set-upstream-to=origin/new-branch old-branch',
                ];
                static $callIndex = 0;
                $this->assertSame($expected[$callIndex], $command);
                $callIndex++;

                return $this->createMock(Process::class);
            });

        $this->gitBranchService->renameRemoteBranch('old-branch', 'new-branch', 'origin');
    }

    public function testRenameRemoteBranchWhenLocalAlreadyRenamed(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('localBranchExists')
            ->with('old-branch')
            ->willReturn(false);

        $this->gitRepository->expects($this->exactly(4))
            ->method('run')
            ->willReturnCallback(function (string $command) {
                $expected = [
                    'git push origin new-branch:new-branch',
                    'git push origin -u new-branch:new-branch',
                    'git push origin --delete old-branch',
                    'git branch --set-upstream-to=origin/new-branch new-branch',
                ];
                static $callIndex = 0;
                $this->assertSame($expected[$callIndex], $command);
                $callIndex++;

                return $this->createMock(Process::class);
            });

        $this->gitBranchService->renameRemoteBranch('old-branch', 'new-branch', 'origin');
    }

    public function testGetBranchCommitsAhead(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git rev-list --count base..branch')
            ->willReturn($this->createSuccessProcess('5'));

        $result = $this->gitBranchService->getBranchCommitsAhead('branch', 'base');

        $this->assertSame(5, $result);
    }

    public function testGetBranchCommitsAheadReturnsZeroOnFailure(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->gitBranchService->getBranchCommitsAhead('branch', 'base');

        $this->assertSame(0, $result);
    }

    public function testGetBranchCommitsBehind(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git rev-list --count branch..base')
            ->willReturn($this->createSuccessProcess('3'));

        $result = $this->gitBranchService->getBranchCommitsBehind('branch', 'base');

        $this->assertSame(3, $result);
    }

    public function testGetBranchCommitsBehindReturnsZeroOnFailure(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->gitBranchService->getBranchCommitsBehind('branch', 'base');

        $this->assertSame(0, $result);
    }

    public function testCanRebaseBranchReturnsTrueWhenAncestor(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git merge-base --is-ancestor onto branch')
            ->willReturn($this->createSuccessProcess());

        $result = $this->gitBranchService->canRebaseBranch('branch', 'onto');

        $this->assertTrue($result);
    }

    public function testCanRebaseBranchFallsBackToDryRun(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturnCallback(function (string $command) {
                if (str_contains($command, 'merge-base')) {
                    return $this->createFailedProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->gitBranchService->canRebaseBranch('branch', 'onto');

        $this->assertTrue($result);
    }

    public function testCanRebaseBranchReturnsFalseWhenBothFail(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->gitBranchService->canRebaseBranch('branch', 'onto');

        $this->assertFalse($result);
    }

    public function testFindBranchesByIssueKeyReturnsLocalBranches(): void
    {
        $this->gitRepository->expects($this->exactly(6))
            ->method('runQuietly')
            ->willReturnCallback(function (string $command) {
                if (str_contains($command, "git branch --list 'feat/PROJ-123-*'")) {
                    return $this->createSuccessProcess("  feat/PROJ-123-title\n");
                }
                if (str_contains($command, "git branch --list 'fix/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git branch --list 'chore/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/feat/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/fix/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/chore/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitBranchService->findBranchesByIssueKey('PROJ-123');

        $this->assertSame(['feat/PROJ-123-title'], $result['local']);
        $this->assertSame([], $result['remote']);
    }

    public function testFindBranchesByIssueKeyReturnsRemoteBranches(): void
    {
        $this->gitRepository->expects($this->exactly(6))
            ->method('runQuietly')
            ->willReturnCallback(function (string $command) {
                if (str_contains($command, "git branch --list 'feat/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git branch --list 'fix/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git branch --list 'chore/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/feat/PROJ-123-*'")) {
                    return $this->createSuccessProcess("abc123\trefs/heads/feat/PROJ-123-title\n");
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/fix/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }
                if (str_contains($command, "git ls-remote --heads origin 'refs/heads/chore/PROJ-123-*'")) {
                    return $this->createSuccessProcess('');
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitBranchService->findBranchesByIssueKey('PROJ-123');

        $this->assertSame([], $result['local']);
        $this->assertSame(['feat/PROJ-123-title'], $result['remote']);
    }

    public function testGetBranchStatus(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (string $command) {
                if (str_contains($command, 'git rev-list --count origin/feat/PROJ-123..feat/PROJ-123')) {
                    return $this->createSuccessProcess("2\n");
                }
                if (str_contains($command, 'git rev-list --count feat/PROJ-123..origin/feat/PROJ-123')) {
                    return $this->createSuccessProcess("1\n");
                }
                if (str_contains($command, 'git rev-list --count develop..feat/PROJ-123')) {
                    return $this->createSuccessProcess("5\n");
                }
                if (str_contains($command, 'git rev-list --count feat/PROJ-123..develop')) {
                    return $this->createSuccessProcess("3\n");
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitBranchService->getBranchStatus('feat/PROJ-123', 'develop', 'origin/feat/PROJ-123');

        $this->assertSame(1, $result['behind_remote']);
        $this->assertSame(2, $result['ahead_remote']);
        $this->assertSame(3, $result['behind_base']);
        $this->assertSame(5, $result['ahead_base']);
    }

    public function testGetBranchStatusWithoutRemote(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturnCallback(function (string $command) {
                if (str_contains($command, 'git rev-list --count develop..feat/PROJ-123')) {
                    return $this->createSuccessProcess("5\n");
                }
                if (str_contains($command, 'git rev-list --count feat/PROJ-123..develop')) {
                    return $this->createSuccessProcess("3\n");
                }

                throw new \RuntimeException("Unexpected command: {$command}");
            });

        $result = $this->gitBranchService->getBranchStatus('feat/PROJ-123', 'develop', null);

        $this->assertSame(0, $result['behind_remote']);
        $this->assertSame(0, $result['ahead_remote']);
        $this->assertSame(3, $result['behind_base']);
        $this->assertSame(5, $result['ahead_base']);
    }

    public function testIsBranchBasedOnReturnsTrue(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->with('develop', 'feat/PROJ-123')
            ->willReturn('abc123');

        $revParseProcess = $this->createSuccessProcess("abc123\n");
        $this->gitRepository->expects($this->once())
            ->method('run')
            ->with('git rev-parse develop')
            ->willReturn($revParseProcess);

        $result = $this->gitBranchService->isBranchBasedOn('feat/PROJ-123', 'develop');

        $this->assertTrue($result);
    }

    public function testIsBranchBasedOnReturnsFalse(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->with('develop', 'feat/PROJ-123')
            ->willReturn('abc123');

        $revParseProcess = $this->createSuccessProcess("def456\n");
        $this->gitRepository->expects($this->once())
            ->method('run')
            ->with('git rev-parse develop')
            ->willReturn($revParseProcess);

        $result = $this->gitBranchService->isBranchBasedOn('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testIsBranchBasedOnReturnsFalseOnException(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->with('develop', 'feat/PROJ-123')
            ->willThrowException(new \RuntimeException('Branch not found'));

        $result = $this->gitBranchService->isBranchBasedOn('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testGetAllLocalBranches(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with("git branch --format='%(refname:short)'")
            ->willReturn($this->createSuccessProcess("develop\nfeat/PROJ-123\nmain"));

        $result = $this->gitBranchService->getAllLocalBranches();

        $this->assertSame(['develop', 'feat/PROJ-123', 'main'], $result);
    }

    public function testGetAllLocalBranchesReturnsEmptyArrayOnFailure(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->gitBranchService->getAllLocalBranches();

        $this->assertSame([], $result);
    }

    public function testGetAllLocalBranchesReturnsEmptyArrayOnEmptyOutput(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->willReturn($this->createSuccessProcess(''));

        $result = $this->gitBranchService->getAllLocalBranches();

        $this->assertSame([], $result);
    }

    public function testIsBranchMergedIntoReturnsTrue(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git branch --merged develop')
            ->willReturn($this->createSuccessProcess("  develop\n* feat/PROJ-123\n  main\n"));

        $result = $this->gitBranchService->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertTrue($result);
    }

    public function testIsBranchMergedIntoReturnsFalse(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git branch --merged develop')
            ->willReturn($this->createSuccessProcess("  develop\n  main\n"));

        $result = $this->gitBranchService->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testIsBranchMergedIntoReturnsFalseOnFailure(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->gitBranchService->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testIsBranchMergedIntoReturnsFalseOnEmptyOutput(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git branch --merged develop')
            ->willReturn($this->createSuccessProcess(''));

        $result = $this->gitBranchService->isBranchMergedInto('feat/PROJ-123', 'develop');

        $this->assertFalse($result);
    }

    public function testGetAllRemoteBranches(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git ls-remote --heads origin')
            ->willReturn($this->createSuccessProcess("abc123\trefs/heads/develop\n def456\trefs/heads/feat/PROJ-123\n"));

        $result = $this->gitBranchService->getAllRemoteBranches('origin');

        $this->assertSame(['develop', 'feat/PROJ-123'], $result);
    }

    public function testGetAllRemoteBranchesReturnsEmptyArrayOnFailure(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->gitBranchService->getAllRemoteBranches('origin');

        $this->assertSame([], $result);
    }

    public function testGetAllRemoteBranchesReturnsEmptyArrayOnEmptyOutput(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->willReturn($this->createSuccessProcess(''));

        $result = $this->gitBranchService->getAllRemoteBranches('origin');

        $this->assertSame([], $result);
    }

    public function testResolveLatestBaseBranchRemoteAheadOfLocal(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchLocalAheadOfRemote(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createFailedProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('develop', $result);
    }

    public function testResolveLatestBaseBranchSameCommitReturnsOriginal(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchOnlyRemoteExists(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createFailedProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchOnlyLocalExists(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('develop', $result);
    }

    public function testResolveLatestBaseBranchNeitherExistsReturnsOriginal(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->gitBranchService->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchDivergedPrefersRemote(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchWithoutOriginPrefix(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchWithoutOriginPrefixLocalAhead(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (string $cmd) {
                if ($cmd === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($cmd === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createFailedProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->gitBranchService->resolveLatestBaseBranch('develop');

        $this->assertSame('develop', $result);
    }

    public function testSwitchBranch(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('run')
            ->with('git switch feat/PROJ-123-title');

        $this->gitBranchService->switchBranch('feat/PROJ-123-title');
    }

    public function testSwitchToRemoteBranch(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('run')
            ->with('git switch -c feat/PROJ-123-title origin/feat/PROJ-123-title');

        $this->gitBranchService->switchToRemoteBranch('feat/PROJ-123-title');
    }

    public function testSwitchToRemoteBranchWithCustomRemote(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('run')
            ->with('git switch -c feat/PROJ-123-title upstream/feat/PROJ-123-title');

        $this->gitBranchService->switchToRemoteBranch('feat/PROJ-123-title', 'upstream');
    }
}
