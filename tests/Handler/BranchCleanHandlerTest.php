<?php

namespace App\Tests\Handler;

use App\Handler\BranchCleanHandler;
use App\Service\GithubProvider;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class BranchCleanHandlerTest extends CommandTestCase
{
    private BranchCleanHandler $handler;
    private GithubProvider&MockObject $githubProvider;
    private Logger&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubProvider::class);
        $this->logger = $this->createMock(Logger::class);

        // Allow any calls to logger methods to avoid warnings
        // Using method() without expects() allows any number of calls with any parameters
        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('writeln');
        $this->logger->method('warning');
        $this->logger->method('success');
        // Note: confirm() expectations should be set per-test as needed
        // Quiet mode tests don't call confirm(), so no default expectation needed

        $this->handler = new BranchCleanHandler($this->gitRepository, $this->githubProvider, 'origin/develop', $this->translationService, $this->logger);
    }

    public function testHandleWithNoBranchesToClean(): void
    {
        $this->gitRepository->method('getAllLocalBranches')->willReturn(['develop', 'main']);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['develop', 'main']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchesToCleanLocalOnlyInQuietMode(): void
    {
        $branches = ['develop', 'feat/PROJ-123', 'main'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['develop', 'main']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturnCallback(function ($branch, $base) {
            return $branch === 'feat/PROJ-123' && $base === 'origin/develop';
        });
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchesToCleanWithRemote(): void
    {
        $branches = ['develop', 'feat/PROJ-123', 'main'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['develop', 'main', 'feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturnCallback(function ($branch, $base) {
            return $branch === 'feat/PROJ-123' && $base === 'origin/develop';
        });
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'number' => 123,
                'state' => 'closed',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ]);
        // First confirm is for deleting branches, second is for deleting remote
        $this->logger->method('confirm')
            ->willReturnOnConsecutiveCalls(true, false); // Confirm deletion, but don't delete remote
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');
        $this->gitRepository->expects($this->never())
            ->method('deleteRemoteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleSkipsProtectedBranches(): void
    {
        $branches = ['develop', 'main', 'master', 'feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['develop', 'main', 'master']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturnCallback(function ($branch, $base) {
            // All branches are merged
            return true;
        });
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleSkipsCurrentBranch(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/PROJ-123');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleSkipsBranchesWithOpenPr(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ]);
        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithDeletionError(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);
        $this->gitRepository->method('deleteBranch')->willThrowException(new \RuntimeException('Cannot delete branch'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithGithubProviderNull(): void
    {
        $handler = new BranchCleanHandler($this->gitRepository, null, 'origin/develop', $this->translationService, $this->logger);

        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithGithubProviderException(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));
        $this->githubProvider->method('findPullRequestByBranchName')->willThrowException(new \Exception('API error'));
        // Should continue with merge-based logic when PR check fails
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithRemoteDeletionConfirmed(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'number' => 123,
                'state' => 'closed',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ]);
        $this->logger->method('confirm')
            ->willReturnOnConsecutiveCalls(true, true); // Confirm deletion and remote deletion

        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');
        $this->gitRepository->expects($this->once())
            ->method('deleteRemoteBranch')
            ->with('origin', 'feat/PROJ-123');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithRemoteDeletionError(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->logger->method('confirm')
            ->willReturnOnConsecutiveCalls(true, true); // Confirm deletion and remote deletion


        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');
        $this->gitRepository->method('deleteRemoteBranch')
            ->willThrowException(new \RuntimeException('Remote deletion failed'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithCancellation(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        // Override the default confirm behavior for this test
        $this->logger->method('confirm')
            ->willReturn(false); // Cancel deletion

        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithMergeCheckException(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')
            ->willThrowException(new \RuntimeException('Merge check failed'));
        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithCurrentBranchSkipped(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/PROJ-123');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithCurrentBranchSkippedAndOtherBranchesToClean(): void
    {
        // Test scenario where current branch is skipped but there are other branches to clean
        // This ensures notifyCurrentBranchSkipped() is called
        $branches = ['feat/PROJ-123', 'feat/PROJ-456'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/PROJ-123'); // Current branch
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->logger->method('confirm')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-456'); // Only feat/PROJ-456 should be deleted (current branch is skipped)

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithBothLocalAndRemoteBranches(): void
    {
        $branches = ['feat/PROJ-123', 'feat/PROJ-456'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);
        $this->logger->method('confirm')
            ->willReturnOnConsecutiveCalls(true, false); // Confirm deletion, but don't delete remote
        $this->gitRepository->expects($this->exactly(2))
            ->method('deleteBranch')
            ->willReturnCallback(function ($branch) {
                $this->assertContains($branch, ['feat/PROJ-456', 'feat/PROJ-123']);
            });

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithRemoteBranchInQuietMode(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);

        $this->logger->expects($this->any())->method('success');

        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');
        $this->gitRepository->expects($this->never())
            ->method('deleteRemoteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithNonMergedBranch(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false); // Not merged
        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testDeleteBranchesWithProtectedBranchInLocalOnly(): void
    {
        // Test the defensive check in deleteBranches for protected branches
        // Use reflection to directly call deleteBranches with a protected branch
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('deleteBranches');
        $method->setAccessible(true);

        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');

        // Call deleteBranches directly with a protected branch
        $result = $method->invoke($this->handler, ['main'], [], true);

        $this->assertSame(0, $result);
    }

    public function testDeleteBranchesWithProtectedBranchInWithRemote(): void
    {
        // Test the defensive check in deleteBranches for protected branches with remote
        // Use reflection to directly call deleteBranches with a protected branch
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('deleteBranches');
        $method->setAccessible(true);

        $this->gitRepository->expects($this->never())
            ->method('deleteBranch');

        // Call deleteBranches directly with a protected branch that has remote
        $result = $method->invoke($this->handler, [], ['main'], true);

        $this->assertSame(0, $result);
    }

    public function testDeleteBranchesWithRemoteThrowsException(): void
    {
        // Test exception handling in deleteBranches for branches with remote
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->logger->method('confirm')
            ->willReturnOnConsecutiveCalls(true, false); // Confirm deletion, but don't delete remote


        $this->gitRepository->method('deleteBranch')
            ->willThrowException(new \RuntimeException('Local deletion failed'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testDeleteBranchesWithStaleRemoteRef(): void
    {
        // Test stale remote-tracking ref scenario: branch merged locally, remote deleted, stale ref exists
        // CRITICAL: Set up ALL mocks FIRST, then create the handler
        // This ensures all mocks are configured before the handler is created
        $successfulProcess = $this->createMock(Process::class);
        $successfulProcess->method('isSuccessful')->willReturn(true);

        // Set up all other mocks FIRST (using method() without expects)
        // This ensures they don't interfere with the expects() setup
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]); // Remote branch was deleted
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->logger->method('confirm')->willReturn(true);

        // remoteBranchExists is called in try block (line 247), and again in catch block (line 259) for force delete check
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->with('origin', 'feat/PROJ-123')
            ->willReturn(false); // Remote doesn't actually exist

        // First deleteBranch call fails due to stale ref, then force delete succeeds
        // The exception message must contain "not fully merged" for the force delete path to be taken
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123', false)
            ->willThrowException(new \RuntimeException('error: the branch is not fully merged.'));

        // CRITICAL: Set up deleteBranchForce mock AFTER all other mocks
        // Set up deleteBranchForce to return the Process
        // Using expects() to verify it's called, and willReturn() to prevent real method execution
        // IMPORTANT: willReturn() should prevent the real method from executing
        $this->gitRepository->expects($this->once())
            ->method('deleteBranchForce')
            ->with('feat/PROJ-123')
            ->willReturn($successfulProcess);

        // Also mock run() as a safety net (though it shouldn't be called if deleteBranchForce mock works)
        $this->gitRepository->method('run')
            ->willReturn($successfulProcess);

        // Create handler AFTER all mocks are set up
        $handler = new BranchCleanHandler($this->gitRepository, $this->githubProvider, 'origin/develop', $this->translationService, $this->logger);

        // Use reflection to call deleteBranches directly to avoid the full handle() flow
        $reflection = new \ReflectionClass($handler);
        $deleteBranchesMethod = $reflection->getMethod('deleteBranches');
        $deleteBranchesMethod->setAccessible(true);

        // Since deleteBranchForce() is mocked to return successfully, we should only see the "force delete" warning
        // The logger is already set up in setUp() to allow any calls, so we don't need to set expectations here
        // If deleteBranchForce() works, we'll see one "force delete" warning
        // If it doesn't work, we'll see both "force delete" and "error" warnings

        // Call deleteBranches directly with the branch in local_only array
        $result = $deleteBranchesMethod->invoke($handler, ['feat/PROJ-123'], [], false);

        // Branch should be deleted via force delete
        $this->assertSame(1, $result); // One branch deleted
    }

    public function testDeleteBranchesWithStaleRemoteRefForceDeleteFails(): void
    {
        // Test scenario where force delete also fails
        $branches = ['feat/PROJ-456'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->logger->method('confirm')->willReturn(true);

        $this->gitRepository->method('remoteBranchExists')
            ->with('origin', 'feat/PROJ-456')
            ->willReturn(false);

        $this->gitRepository->method('deleteBranch')
            ->with('feat/PROJ-456', false)
            ->willThrowException(new \RuntimeException('not fully merged'));

        // Force delete also fails
        $this->gitRepository->method('deleteBranchForce')
            ->with('feat/PROJ-456')
            ->willThrowException(new \RuntimeException('Force delete failed'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false);

        // Branch deletion failed, count should be 0
        $this->assertSame(0, $result);
    }

    public function testDeleteBranchesWithStaleRemoteRefInWithRemoteBranches(): void
    {
        // Test stale remote-tracking ref scenario for branches in withRemoteBranches loop
        // This covers lines 325-328 (force delete success path in withRemoteBranches)
        $successfulProcess = $this->createMock(Process::class);
        $successfulProcess->method('isSuccessful')->willReturn(true);

        // Set up mocks
        $branches = ['feat/PROJ-789'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-789']); // Branch exists on remote initially
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->logger->method('confirm')->willReturn(true); // Confirm deletion

        // remoteBranchExists is called in try block (line 287), and again in catch block (line 322) for force delete check
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->with('origin', 'feat/PROJ-789')
            ->willReturn(false); // Remote doesn't actually exist (stale ref)

        // First deleteBranch call fails due to stale ref, then force delete succeeds
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-789', false)
            ->willThrowException(new \RuntimeException('error: the branch is not fully merged.'));

        // Set up deleteBranchForce to return successfully
        $this->gitRepository->expects($this->once())
            ->method('deleteBranchForce')
            ->with('feat/PROJ-789')
            ->willReturn($successfulProcess);

        // Also mock run() as a safety net
        $this->gitRepository->method('run')
            ->willReturn($successfulProcess);

        // Create handler AFTER all mocks are set up
        $handler = new BranchCleanHandler($this->gitRepository, $this->githubProvider, 'origin/develop', $this->translationService, $this->logger);

        // Use reflection to call deleteBranches directly
        $reflection = new \ReflectionClass($handler);
        $deleteBranchesMethod = $reflection->getMethod('deleteBranches');
        $deleteBranchesMethod->setAccessible(true);

        // Call deleteBranches with branch in withRemoteBranches array (empty localOnlyBranches)
        $result = $deleteBranchesMethod->invoke($handler, [], ['feat/PROJ-789'], false);

        // Branch should be deleted via force delete
        $this->assertSame(1, $result); // One branch deleted
    }

    public function testDeleteBranchesWithStaleRemoteRefInWithRemoteBranchesForceDeleteFails(): void
    {
        // Test scenario where force delete also fails in withRemoteBranches loop
        // This covers lines 329-331 (force delete failure path in withRemoteBranches)
        $successfulProcess = $this->createMock(Process::class);
        $successfulProcess->method('isSuccessful')->willReturn(true);

        // Set up mocks
        $branches = ['feat/PROJ-999'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-999']); // Branch exists on remote initially
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
        $this->logger->method('confirm')->willReturn(true); // Confirm deletion

        // remoteBranchExists is called in try block (line 287), and again in catch block (line 322) for force delete check
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->with('origin', 'feat/PROJ-999')
            ->willReturn(false); // Remote doesn't actually exist (stale ref)

        // First deleteBranch call fails due to stale ref
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-999', false)
            ->willThrowException(new \RuntimeException('error: the branch is not fully merged.'));

        // Force delete also fails
        $this->gitRepository->expects($this->once())
            ->method('deleteBranchForce')
            ->with('feat/PROJ-999')
            ->willThrowException(new \RuntimeException('Force delete failed'));

        // Create handler AFTER all mocks are set up
        $handler = new BranchCleanHandler($this->gitRepository, $this->githubProvider, 'origin/develop', $this->translationService, $this->logger);

        // Use reflection to call deleteBranches directly
        $reflection = new \ReflectionClass($handler);
        $deleteBranchesMethod = $reflection->getMethod('deleteBranches');
        $deleteBranchesMethod->setAccessible(true);

        // Call deleteBranches with branch in withRemoteBranches array (empty localOnlyBranches)
        $result = $deleteBranchesMethod->invoke($handler, [], ['feat/PROJ-999'], false);

        // Branch deletion failed, count should be 0
        $this->assertSame(0, $result);
    }

    public function testHandleWithPrMapOptimization(): void
    {
        $branches = ['feat/PROJ-123', 'feat/PROJ-456'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);

        // Mock getAllPullRequests to return PRs
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open', // Open PR - should skip deletion
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'closed', // Closed PR - can be deleted
                'head' => [
                    'ref' => 'feat/PROJ-456',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);
        // Should not call findPullRequestByBranchName when PR map is used
        $this->githubProvider->expects($this->never())->method('findPullRequestByBranchName');

        $this->logger->method('confirm')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-456'); // Only closed PR branch should be deleted

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrMapFallbackOnError(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);

        // getAllPullRequests fails, should fall back to per-branch calls
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        $this->logger->method('confirm')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('deleteBranch')
            ->with('feat/PROJ-123');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testBuildPrMapExcludesForkPrs(): void
    {
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-456',
                    'repo' => ['full_name' => 'fork_owner/test_repo'], // Fork PR
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should only include PR from same repo, exclude fork PR
        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
        $this->assertArrayNotHasKey('feat/PROJ-456', $prMap);
    }

    public function testBuildPrMapSkipsPrsWithoutHeadRef(): void
    {
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'open',
                'head' => [], // Missing ref
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should only include PR with head.ref
        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
    }

    public function testHasOpenPullRequestWithPrMap(): void
    {
        $prMap = [
            'feat/PROJ-123' => [
                'number' => 123,
                'state' => 'open',
                'head' => ['ref' => 'feat/PROJ-123'],
            ],
            'feat/PROJ-456' => [
                'number' => 456,
                'state' => 'closed',
                'head' => ['ref' => 'feat/PROJ-456'],
            ],
        ];

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        // Branch with open PR
        $this->assertTrue($method->invoke($this->handler, 'feat/PROJ-123', $prMap));

        // Branch with closed PR
        $this->assertFalse($method->invoke($this->handler, 'feat/PROJ-456', $prMap));

        // Branch without PR
        $this->assertFalse($method->invoke($this->handler, 'feat/PROJ-789', $prMap));
    }

    public function testBuildPrMapHandlesException(): void
    {
        // Test that buildPrMap handles exceptions gracefully
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should return empty map on exception
        $this->assertSame([], $prMap);
    }

    public function testBuildPrMapSkipsPrsWithMissingRepoInfo(): void
    {
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-456',
                    // Missing repo info
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 789,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-789',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                // Missing base repo info
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should only include PR with complete repo info
        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
        $this->assertArrayNotHasKey('feat/PROJ-456', $prMap);
        $this->assertArrayNotHasKey('feat/PROJ-789', $prMap);
    }

    public function testHasOpenPullRequestWithNullGithubProvider(): void
    {
        $handler = new BranchCleanHandler($this->gitRepository, null, 'origin/develop', $this->translationService, $this->logger);

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($handler, 'feat/PROJ-123', null);

        $this->assertFalse($result);
    }

    public function testHasOpenPullRequestWithFallbackPathOpenPr(): void
    {
        // Test hasOpenPullRequest fallback when PR is open
        $pr = [
            'number' => 123,
            'state' => 'open',
            'head' => ['ref' => 'feat/PROJ-123'],
        ];

        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willReturn($pr);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', null);

        $this->assertTrue($result);
    }

    public function testHasOpenPullRequestWithFallbackPathClosedPr(): void
    {
        // Test hasOpenPullRequest fallback when PR is closed
        $pr = [
            'number' => 123,
            'state' => 'closed',
            'head' => ['ref' => 'feat/PROJ-123'],
        ];

        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willReturn($pr);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', null);

        $this->assertFalse($result);
    }

    public function testHasOpenPullRequestWithFallbackPathNoPr(): void
    {
        // Test hasOpenPullRequest fallback when no PR is found
        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willReturn(null);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', null);

        $this->assertFalse($result);
    }

    public function testHasOpenPullRequestWithFallbackPathException(): void
    {
        // Test hasOpenPullRequest fallback when API call throws exception
        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willThrowException(new \Exception('API error'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', null);

        $this->assertFalse($result);
    }

    public function testHasOpenPullRequestWithPrMapOpenPr(): void
    {
        // Test hasOpenPullRequest with PR map when PR is open
        $prMap = [
            'feat/PROJ-123' => [
                'number' => 123,
                'state' => 'open',
                'head' => ['ref' => 'feat/PROJ-123'],
            ],
        ];

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', $prMap);

        $this->assertTrue($result);
    }

    public function testHasOpenPullRequestWithPrMapClosedPr(): void
    {
        // Test hasOpenPullRequest with PR map when PR is closed
        $prMap = [
            'feat/PROJ-123' => [
                'number' => 123,
                'state' => 'closed',
                'head' => ['ref' => 'feat/PROJ-123'],
            ],
        ];

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasOpenPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', $prMap);

        $this->assertFalse($result);
    }
}
