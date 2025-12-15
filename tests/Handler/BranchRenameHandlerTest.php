<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\BranchRenameHandler;
use App\Service\GithubProvider;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchRenameHandlerTest extends CommandTestCase
{
    private BranchRenameHandler $handler;
    private GithubProvider&MockObject $githubProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubProvider::class);
        $logger = $this->createMock(\App\Service\Logger::class);
        $this->handler = new BranchRenameHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->githubProvider,
            $this->translationService,
            ['JIRA_URL' => 'https://jira.example.com'],
            'origin/develop',
            $logger
        );
    }

    public function testHandleWithDirtyWorkingDirectory(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null, null, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithBranchNotFound(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('current-branch');
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn(new WorkItem(
                id: '10001',
                key: 'TPW-35',
                title: 'Test',
                status: 'In Progress',
                assignee: 'John Doe',
                description: 'A description',
                labels: [],
                issueType: 'story',
                components: ['api'],
            ));
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'feat/TPW-35-test' ? false : false; // Both return false
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null, null, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithExplicitName(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                // First call: check if new branch exists (should be false)
                // Second call: check if old branch exists (should be true)
                static $callCount = 0;
                $callCount++;
                if ($callCount === 1) {
                    return $branch === 'new-branch' ? false : true;
                }

                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithKeyParameter(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'feat/TPW-35-my-awesome-feature');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, 'TPW-35', null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithJiraKeyAsFirstArgument(): void
    {
        // Test case: stud rn SCI-34 (where SCI-34 is passed as first arg but should be treated as key)
        $workItem = new WorkItem(
            id: '10001',
            key: 'SCI-34',
            title: 'Test feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('current-branch');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('SCI-34')
            ->willReturn($workItem);
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'current-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('current-branch', 'feat/SCI-34-test-feature');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        // Pass SCI-34 as branchName (first arg), null as key (second arg)
        // Handler should detect it's a Jira key and treat it as key instead
        $result = $this->handler->handle($io, 'SCI-34', null, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithKeyExtractedFromBranch(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('feat/TPW-35-old-name');
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'feat/TPW-35-old-name';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('feat/TPW-35-old-name', 'feat/TPW-35-my-awesome-feature');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, null, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithNewNameAlreadyExists(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->once())
            ->method('localBranchExists')
            ->with('new-branch')
            ->willReturn(true);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(1, $result);
    }

    public function testHandleWithInvalidBranchName(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null, null, 'invalid..branch');

        $this->assertSame(1, $result);
    }

    public function testHandleWithInvalidBranchNameAtSymbol(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // This will fail the regex first, but test the flow
        $result = $this->handler->handle($io, null, null, 'branch@{ref}');

        $this->assertSame(1, $result);
    }

    public function testHandleWithInvalidBranchNameBackslash(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // This will fail the regex first, but test the flow
        $result = $this->handler->handle($io, null, null, 'branch\\name');

        $this->assertSame(1, $result);
    }

    public function testHandleWithRemoteOnlyBranch(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                // First call: check if new branch exists (should be false)
                // Second call: check if old branch exists locally (should be false)
                return false;
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                // First call: check if new branch exists remotely (should be false)
                // Second call: check if old branch exists remotely (should be true)
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->once())
            ->method('renameRemoteBranch')
            ->with('old-branch', 'new-branch', 'origin');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithBothLocalAndRemote(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsBehind')
            ->willReturn(0);
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitRepository->expects($this->once())
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->gitRepository->expects($this->once())
            ->method('renameRemoteBranch')
            ->with('old-branch', 'new-branch', 'origin');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithPullRequestUpdate(): void
    {
        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository->expects($this->exactly(2))
            ->method('getPorcelainStatus')
            ->willReturn(''); // Once for BranchRenameHandler, once for SubmitHandler
        // getCurrentBranchName is called multiple times:
        // 1. In handle() to determine target branch (returns 'old-branch' initially)
        // 2. In findAssociatedPullRequest() (returns 'old-branch')
        // 3. In SubmitHandler (returns 'new-branch' after rename)
        // 4. In updatePullRequestAfterRename() to find new PR (returns 'new-branch')
        $this->gitRepository->expects($this->atLeast(4))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(2))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        // SubmitHandler calls
        $pushProcess = $this->createMock(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('pushToOrigin')
            ->with('HEAD')
            ->willReturn($pushProcess);
        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository->expects($this->once())
            ->method('findFirstLogicalSha')
            ->willReturn('def456');
        $this->gitRepository->expects($this->once())
            ->method('getCommitMessage')
            ->willReturn('[SCI-34] Test commit');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('SCI-34', true)
            ->willReturn(new \App\DTO\WorkItem(
                id: '10001',
                key: 'SCI-34',
                title: 'Test',
                status: 'In Progress',
                assignee: 'John Doe',
                description: 'A description',
                labels: [],
                issueType: 'story',
                components: ['api'],
                renderedDescription: 'Test description',
            ));
        $this->githubProvider->expects($this->exactly(2))
            ->method('findPullRequestByBranch')
            ->willReturnOnConsecutiveCalls($pr, ['number' => 456]); // First call finds old PR, second finds new PR
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->with($this->isInstanceOf(\App\DTO\PullRequestData::class))
            ->willReturn(['number' => 456, 'html_url' => 'https://github.com/test/pr/456']);
        $this->githubProvider->expects($this->once())
            ->method('createComment')
            ->with(456, 'Branch renamed from `old-branch` to `new-branch`');

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithKeyNotFound(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Issue not found'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null, 'TPW-35', null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithNoKeyInBranch(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null, null, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithRemoteOnlyBranchUserDeclines(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('note');
        $io->method('confirm')->willReturn(false);

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchSyncAndRebase(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsBehind')
            ->willReturn(2);
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitRepository->expects($this->once())
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('rebase')
            ->with('origin/old-branch');
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->gitRepository->expects($this->once())
            ->method('renameRemoteBranch')
            ->with('old-branch', 'new-branch', 'origin');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchSyncRebaseFails(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsBehind')
            ->willReturn(2);
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitRepository->expects($this->once())
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('rebase')
            ->willThrowException(new \RuntimeException('Rebase failed'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('error');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(1, $result);
    }

    public function testHandleWithUserDeclinesConfirmation(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(false);

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithRemoteBranchRenameException(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsBehind')
            ->willReturn(0);
        $this->gitRepository->expects($this->once())
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitRepository->expects($this->once())
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->gitRepository->expects($this->once())
            ->method('renameRemoteBranch')
            ->willThrowException(new \RuntimeException('Remote rename failed'));
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('warning');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrFindException(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->once())
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willThrowException(new \RuntimeException('PR not found'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrUpdateSuccess(): void
    {
        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository->expects($this->exactly(2))
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->atLeast(4))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(2))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $pushProcess = $this->createMock(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('pushToOrigin')
            ->with('HEAD')
            ->willReturn($pushProcess);
        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository->expects($this->once())
            ->method('findFirstLogicalSha')
            ->willReturn('def456');
        $this->gitRepository->expects($this->once())
            ->method('getCommitMessage')
            ->willReturn('[SCI-34] Test commit');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('SCI-34', true)
            ->willReturn(new \App\DTO\WorkItem(
                id: '10001',
                key: 'SCI-34',
                title: 'Test',
                status: 'In Progress',
                assignee: 'John Doe',
                description: 'A description',
                labels: [],
                issueType: 'story',
                components: ['api'],
                renderedDescription: 'Test description',
            ));
        $this->githubProvider->expects($this->exactly(2))
            ->method('findPullRequestByBranch')
            ->willReturnOnConsecutiveCalls($pr, ['number' => 456]);
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->with($this->isInstanceOf(\App\DTO\PullRequestData::class))
            ->willReturn(['number' => 456, 'html_url' => 'https://github.com/test/pr/456']);
        $this->githubProvider->expects($this->once())
            ->method('createComment')
            ->with(456, 'Branch renamed from `old-branch` to `new-branch`');

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrUpdateNoNumber(): void
    {
        $pr = ['head' => ['ref' => 'old-branch']]; // Missing 'number' key

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->exactly(2))
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->once())
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn($pr);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('note');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrCommentException(): void
    {
        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository->expects($this->exactly(2))
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->atLeast(4))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(2))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $pushProcess = $this->createMock(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('pushToOrigin')
            ->with('HEAD')
            ->willReturn($pushProcess);
        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository->expects($this->once())
            ->method('findFirstLogicalSha')
            ->willReturn('def456');
        $this->gitRepository->expects($this->once())
            ->method('getCommitMessage')
            ->willReturn('[SCI-34] Test commit');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('SCI-34', true)
            ->willReturn(new \App\DTO\WorkItem(
                id: '10001',
                key: 'SCI-34',
                title: 'Test',
                status: 'In Progress',
                assignee: 'John Doe',
                description: 'A description',
                labels: [],
                issueType: 'story',
                components: ['api'],
                renderedDescription: 'Test description',
            ));
        $this->githubProvider->expects($this->exactly(2))
            ->method('findPullRequestByBranch')
            ->willReturnOnConsecutiveCalls($pr, ['number' => 456]);
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->with($this->isInstanceOf(\App\DTO\PullRequestData::class))
            ->willReturn(['number' => 456, 'html_url' => 'https://github.com/test/pr/456']);
        $this->githubProvider->expects($this->once())
            ->method('createComment')
            ->with(456, 'Branch renamed from `old-branch` to `new-branch`')
            ->willThrowException(new \RuntimeException('Comment failed'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('warning');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrCreationFailed(): void
    {
        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository->expects($this->exactly(2))
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->atLeast(3))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(1))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        // SubmitHandler will fail because findFirstLogicalSha returns null
        $pushProcess = $this->createMock(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('pushToOrigin')
            ->with('HEAD')
            ->willReturn($pushProcess);
        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository->expects($this->once())
            ->method('findFirstLogicalSha')
            ->willReturn(null); // This will cause SubmitHandler to return 1
        $this->githubProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn($pr);

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');
        $io->method('warning');
        $io->method('error');

        $result = $this->handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testValidateBranchNameWithVariousInvalidNames(): void
    {
        $handler = new \ReflectionClass($this->handler);
        $method = $handler->getMethod('validateBranchName');
        $method->setAccessible(true);

        // Test with consecutive dots (str_contains check)
        $this->assertFalse($method->invoke($this->handler, 'branch..name'));
        // Test with .lock suffix
        $this->assertFalse($method->invoke($this->handler, 'branch.lock'));
        // Test with @{ (line 176)
        $this->assertFalse($method->invoke($this->handler, 'branch@{'));
        $this->assertFalse($method->invoke($this->handler, 'branch@{ref'));
        // Test with backslash (line 179)
        $this->assertFalse($method->invoke($this->handler, 'branch\\name'));
        $this->assertFalse($method->invoke($this->handler, 'branch\\'));
        // Test with consecutive dots (preg_match check - line 182)
        $this->assertFalse($method->invoke($this->handler, 'branch..name'));
        $this->assertFalse($method->invoke($this->handler, '..branch'));
        // Test with invalid characters (regex fails)
        $this->assertFalse($method->invoke($this->handler, 'branch name'));
        $this->assertFalse($method->invoke($this->handler, 'branch@name'));
        // Test valid name
        $this->assertTrue($method->invoke($this->handler, 'valid-branch_name'));
        $this->assertTrue($method->invoke($this->handler, 'valid.branch_name'));
        $this->assertTrue($method->invoke($this->handler, 'valid/branch-name'));
    }

    public function testGetBranchPrefixFromIssueType(): void
    {
        $handler = new \ReflectionClass($this->handler);
        $method = $handler->getMethod('getBranchPrefixFromIssueType');
        $method->setAccessible(true);

        $this->assertSame('fix', $method->invoke($this->handler, 'bug'));
        $this->assertSame('feat', $method->invoke($this->handler, 'story'));
        $this->assertSame('feat', $method->invoke($this->handler, 'epic'));
        $this->assertSame('chore', $method->invoke($this->handler, 'task'));
        $this->assertSame('chore', $method->invoke($this->handler, 'sub-task'));
        $this->assertSame('feat', $method->invoke($this->handler, 'unknown'));
    }

    public function testHandleWithKeyExtractedFromBranchException(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('feat/TPW-35-old-name');
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Issue not found'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null, null, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithNoGithubProvider(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $handler = new BranchRenameHandler(
            $this->gitRepository,
            $this->jiraService,
            null, // No GitHub provider
            $this->translationService,
            ['JIRA_URL' => 'https://jira.example.com'],
            'origin/develop',
            $logger
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository->expects($this->exactly(2))
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository->expects($this->exactly(2))
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitRepository->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('section');
        $io->method('text');
        $io->method('confirm')->willReturn(true);
        $io->method('success');

        $result = $handler->handle($io, null, null, 'new-branch');

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrUpdateNoGithubProvider(): void
    {
        $pr = ['number' => 123];

        $logger = $this->createMock(\App\Service\Logger::class);
        $handler = new BranchRenameHandler(
            $this->gitRepository,
            $this->jiraService,
            null, // No GitHub provider
            $this->translationService,
            ['JIRA_URL' => 'https://jira.example.com'],
            'origin/develop',
            $logger
        );

        $handlerReflection = new \ReflectionClass($handler);
        $method = $handlerReflection->getMethod('updatePullRequestAfterRename');
        $method->setAccessible(true);

        $io = $this->createMock(SymfonyStyle::class);

        // Should return early without error
        $method->invoke($handler, $io, $pr, 'old-branch', 'new-branch');
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}

