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

        $this->handler = new BranchCleanHandler($this->gitRepository, $this->githubProvider, $this->translationService, $this->logger);
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
            return $branch === 'feat/PROJ-123' && $base === 'develop';
        });
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);
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
            return $branch === 'feat/PROJ-123' && $base === 'develop';
        });
        $this->githubProvider->method('findPullRequestByBranchName')->willReturnCallback(function ($branch) {
            // Return closed PR for feat/PROJ-123, null for others
            if ($branch === 'feat/PROJ-123') {
                return ['state' => 'closed', 'number' => 123];
            }

            return null;
        });
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
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'open', 'number' => 123]);
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
        $handler = new BranchCleanHandler($this->gitRepository, null, $this->translationService, $this->logger);

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
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['state' => 'closed']);
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
}
