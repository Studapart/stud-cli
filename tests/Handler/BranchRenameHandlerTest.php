<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\BranchRenameHandler;
use App\Handler\SubmitHandler;
use App\Service\BranchNameGenerator;
use App\Service\BranchNameValidator;
use App\Service\BranchRenamePrCoordinator;
use App\Service\CanConvertToMarkdownInterface;
use App\Service\GitBranchService;
use App\Service\GithubGitHostingAdapter;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\Prompt\PromptInterface;
use App\Service\SubmitAssembly;
use App\Service\SubmitGit;
use App\Service\SubmitLabelPlanner;
use App\Service\SubmitLabelResolver;
use App\Service\SubmitPrBodyBuilder;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\Stub;

class BranchRenameHandlerTest extends CommandTestCase
{
    private BranchRenameHandler $handler;
    private GithubGitHostingAdapter $githubProvider;
    private PromptInterface&Stub $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createStub(GitRepository::class);
        $this->gitBranchService = $this->createStub(GitBranchService::class);
        $this->issueTracker = $this->createStub(IssueTrackerPort::class);

        $this->githubProvider = $this->createStub(GithubGitHostingAdapter::class);
        $this->prompt = $this->createStub(PromptInterface::class);
        $this->bindSubject();
    }

    private function createBranchRenameHandler(
        ?PromptInterface $prompt = null,
        bool $withGithubGitHostingAdapter = true,
    ): BranchRenameHandler {
        $prompt ??= $this->prompt;
        $githubProvider = $withGithubGitHostingAdapter ? $this->githubProvider : null;

        return new BranchRenameHandler(
            new \App\Service\BranchRenameGit($this->gitRepository, $this->gitBranchService),
            new \App\Service\BranchRenameNames(new BranchNameGenerator($this->issueTracker), new BranchNameValidator()),
            new BranchRenamePrCoordinator(
                $this->gitRepository,
                $githubProvider,
                $this->submitHandler($githubProvider, $prompt),
                $prompt,
            ),
            $prompt,
        );
    }

    public function testHandleWithDirtyWorkingDirectory(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');


        $response = $this->handler->handle(null, null, null);

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_dirty_working');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithBranchNotFound(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('current-branch');
        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');
        $this->issueTracker
            ->method('getIssue')
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
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'feat/TPW-35-test' ? false : false; // Both return false
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);


        $response = $this->handler->handle(null, null, null);

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_branch_not_found', ['branch' => 'current-branch']);
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithExplicitName(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithKeyParameter(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'feat/TPW-35-my-awesome-feature');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, 'TPW-35', null);

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'feat/TPW-35-my-awesome-feature']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithJiraKeyAsFirstArgument(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('current-branch');
        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('SCI-34')
            ->willReturn($workItem);
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'current-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService->expects($this->once())
            ->method('renameLocalBranch')
            ->with('current-branch', 'feat/SCI-34-test-feature');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        // Pass SCI-34 as branchName (first arg), null as key (second arg)
        // Handler should detect it's a Jira key and treat it as key instead
        $response = $this->handler->handle('SCI-34', null, null);

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'current-branch', 'newName' => 'feat/SCI-34-test-feature']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithKeyExtractedFromBranch(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

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

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('feat/TPW-35-old-name');
        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');
        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'feat/TPW-35-old-name';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService->expects($this->once())
            ->method('renameLocalBranch')
            ->with('feat/TPW-35-old-name', 'feat/TPW-35-my-awesome-feature');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, null);

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'feat/TPW-35-old-name', 'newName' => 'feat/TPW-35-my-awesome-feature']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithNewNameAlreadyExists(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturn(true);


        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_new_name_exists', ['name' => 'new-branch']);
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithInvalidBranchName(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');


        $response = $this->handler->handle(null, null, 'invalid..branch');

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_invalid_name', ['name' => 'invalid..branch']);
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithInvalidBranchNameAtSymbol(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');


        // This will fail the regex first, but test the flow
        $response = $this->handler->handle(null, null, 'branch@{ref}');

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_invalid_name', ['name' => 'branch@{ref}']);
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithInvalidBranchNameBackslash(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');


        // This will fail the regex first, but test the flow
        $response = $this->handler->handle(null, null, 'branch\\name');

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_invalid_name', ['name' => 'branch\\name']);
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithRemoteOnlyBranch(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                // First call: check if new branch exists (should be false)
                // Second call: check if old branch exists locally (should be false)
                return false;
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                // First call: check if new branch exists remotely (should be false)
                // Second call: check if old branch exists remotely (should be true)
                return $branch === 'old-branch';
            });
        $this->gitBranchService->expects($this->once())
            ->method('renameRemoteBranch')
            ->with('old-branch', 'new-branch', 'origin');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithBothLocalAndRemote(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitBranchService
            ->method('getBranchCommitsBehind')
            ->willReturn(0);
        $this->gitBranchService
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitBranchService
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->gitBranchService->expects($this->once())
            ->method('renameRemoteBranch')
            ->with('old-branch', 'new-branch', 'origin');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPullRequestUpdate(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn(''); // Once for BranchRenameHandler, once for SubmitHandler
        // getCurrentBranchName is called multiple times:
        // 1. In handle() to determine target branch (returns 'old-branch' initially)
        // 2. In findAssociatedPullRequest() (returns 'old-branch')
        // 3. In SubmitHandler preflight (returns 'new-branch' after rename)
        // 4. In commentOnNewPullRequest() (returns 'new-branch')
        $this->gitRepository->expects($this->atLeast(4))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(2))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        // SubmitHandler calls
        $pushProcess = $this->createStub(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository
            ->method('pushHeadToOrigin')
            ->willReturn($pushProcess);
        $this->gitRepository
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository
            ->method('findFirstLogicalSha')
            ->willReturn('def456');
        $this->gitRepository
            ->method('getCommitMessage')
            ->willReturn('[SCI-34] Test commit');
        $this->issueTracker
            ->method('getIssue')
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
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturnOnConsecutiveCalls($pr, ['number' => 456]); // First call finds old PR, second finds new PR
        $this->githubProvider
            ->method('createPullRequest')
            ->willReturn(['number' => 456, 'html_url' => 'https://github.com/test/pr/456']);
        $this->githubProvider
            ->expects($this->once())
            ->method('createComment')
            ->with(456, 'Branch renamed from `old-branch` to `new-branch`');

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithKeyNotFound(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->issueTracker
            ->method('getIssue')
            ->willThrowException(new \Exception('Issue not found'));


        $response = $this->handler->handle(null, 'TPW-35', null);

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_key_not_found', ['key' => 'TPW-35']);
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithNoKeyInBranch(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn(null);


        $response = $this->handler->handle(null, null, null);

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.error_no_key_in_branch', ['branch' => 'old-branch']);
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithRemoteOnlyBranchUserDeclines(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturn(false);
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });

        $this->prompt->method('confirm')->willReturn(false);

        $this->gitBranchService->expects($this->never())
            ->method('renameLocalBranch');

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithBranchSyncAndRebase(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitBranchService
            ->method('getBranchCommitsBehind')
            ->willReturn(2);
        $this->gitBranchService
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitBranchService
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitRepository
            ->expects($this->once())
            ->method('rebase')
            ->with('origin/old-branch');
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameRemoteBranch')
            ->with('old-branch', 'new-branch', 'origin');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithBranchSyncRebaseFails(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitBranchService
            ->method('getBranchCommitsBehind')
            ->willReturn(2);
        $this->gitBranchService
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitBranchService
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitRepository
            ->method('rebase')
            ->willThrowException(new \RuntimeException('Rebase failed'));

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertMessageRef($response->getErrorMessage(), 'branch.rename.rebase_failed');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithUserDeclinesConfirmation(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(false);

        $this->gitBranchService->expects($this->never())
            ->method('renameLocalBranch');

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithRemoteBranchRenameException(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturnCallback(function ($remote, $branch) {
                return $branch === 'old-branch';
            });
        $this->gitBranchService
            ->method('getBranchCommitsBehind')
            ->willReturn(0);
        $this->gitBranchService
            ->method('getBranchCommitsAhead')
            ->willReturn(0);
        $this->gitBranchService
            ->method('canRebaseBranch')
            ->willReturn(true);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->gitBranchService
            ->method('renameRemoteBranch')
            ->willThrowException(new \RuntimeException('Remote rename failed'));
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn(null);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPrFindException(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willThrowException(new \RuntimeException('PR not found'));

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPrUpdateSuccess(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->atLeast(4))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(2))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $pushProcess = $this->createStub(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository
            ->method('pushHeadToOrigin')
            ->willReturn($pushProcess);
        $this->gitRepository
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository
            ->method('findFirstLogicalSha')
            ->willReturn('def456');
        $this->gitRepository
            ->method('getCommitMessage')
            ->willReturn('[SCI-34] Test commit');
        $this->issueTracker
            ->method('getIssue')
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
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturnOnConsecutiveCalls($pr, ['number' => 456]);
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->with($this->isInstanceOf(\App\DTO\PullRequestData::class))
            ->willReturn(['number' => 456, 'html_url' => 'https://github.com/test/pr/456']);
        $this->githubProvider->expects($this->once())
            ->method('createComment')
            ->with(456, 'Branch renamed from `old-branch` to `new-branch`');

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPrUpdateNoNumber(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $pr = ['head' => ['ref' => 'old-branch']]; // Missing 'number' key

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn($pr);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPrCommentException(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->atLeast(4))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(2))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        $pushProcess = $this->createStub(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository
            ->method('pushHeadToOrigin')
            ->willReturn($pushProcess);
        $this->gitRepository
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository
            ->method('findFirstLogicalSha')
            ->willReturn('def456');
        $this->gitRepository
            ->method('getCommitMessage')
            ->willReturn('[SCI-34] Test commit');
        $this->issueTracker
            ->method('getIssue')
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
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturnOnConsecutiveCalls($pr, ['number' => 456]);
        $this->githubProvider
            ->method('createPullRequest')
            ->willReturn(['number' => 456, 'html_url' => 'https://github.com/test/pr/456']);
        $this->githubProvider
            ->method('createComment')
            ->willThrowException(new \RuntimeException('Comment failed'));

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPrCreationFailed(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $pr = ['number' => 123, 'head' => ['ref' => 'old-branch']];

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository->expects($this->atLeast(3))
            ->method('getCurrentBranchName')
            ->willReturnOnConsecutiveCalls('old-branch', 'old-branch', 'new-branch');
        $this->gitRepository->expects($this->atLeast(1))
            ->method('getRepositoryOwner')
            ->willReturn('owner');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');
        // SubmitHandler will fail because findFirstLogicalSha returns null
        $pushProcess = $this->createStub(\Symfony\Component\Process\Process::class);
        $pushProcess->method('isSuccessful')->willReturn(true);
        $this->gitRepository
            ->method('pushHeadToOrigin')
            ->willReturn($pushProcess);
        $this->gitRepository
            ->method('getMergeBase')
            ->willReturn('abc123');
        $this->gitRepository
            ->method('findFirstLogicalSha')
            ->willReturn(null); // This will cause SubmitHandler to return 1
        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn($pr);

        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testValidateBranchNameWithVariousInvalidNames(): void
    {
        $handler = new \ReflectionClass($this->handler);
        $method = $handler->getMethod('validateBranchName');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertSame('fix', $method->invoke($this->handler, 'bug'));
        $this->assertSame('feat', $method->invoke($this->handler, 'story'));
        $this->assertSame('feat', $method->invoke($this->handler, 'epic'));
        $this->assertSame('chore', $method->invoke($this->handler, 'task'));
        $this->assertSame('chore', $method->invoke($this->handler, 'sub-task'));
        $this->assertSame('feat', $method->invoke($this->handler, 'unknown'));
    }

    public function testHandleWithKeyExtractedFromBranchException(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('feat/TPW-35-old-name');
        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');
        $this->issueTracker
            ->method('getIssue')
            ->willThrowException(new \Exception('Issue not found'));


        $response = $this->handler->handle(null, null, null);

        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithNoGithubGitHostingAdapter(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $prompt = $this->createStub(PromptInterface::class);
        $prompt->method('confirm')->willReturn(true);
        $handler = $this->createBranchRenameHandler($prompt, false);

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');
        $this->gitRepository
            ->method('getCurrentBranchName')
            ->willReturn('old-branch');
        $this->gitRepository
            ->method('localBranchExists')
            ->willReturnCallback(function ($branch) {
                return $branch === 'old-branch';
            });
        $this->gitRepository
            ->method('remoteBranchExists')
            ->willReturn(false);
        $this->gitBranchService
            ->expects($this->once())
            ->method('renameLocalBranch')
            ->with('old-branch', 'new-branch');


        $response = $handler->handle(null, null, 'new-branch');

        $this->assertWorkflowEntry($response, 'branch.rename.success', ['oldName' => 'old-branch', 'newName' => 'new-branch']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPrUpdateNoGithubGitHostingAdapter(): void
    {
        $pr = ['number' => 123];

        $prompt = $this->createStub(PromptInterface::class);
        $prompt->method('confirm')->willReturn(true);
        $handler = $this->createBranchRenameHandler($prompt, false);

        $handlerReflection = new \ReflectionClass($handler);
        $method = $handlerReflection->getMethod('updatePullRequestAfterRename');
        \App\Util\ReflectionAccessor::ensureAccessible($method);
        $recorderProperty = $handlerReflection->getProperty('recorder');
        \App\Util\ReflectionAccessor::ensureAccessible($recorderProperty);
        $recorderProperty->setValue($handler, new \App\DTO\WorkflowRecorder());

        // Should return early without error
        $method->invoke($handler, $pr, 'old-branch', 'new-branch');
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testCommentOnNewPullRequestDelegatesToCoordinator(): void
    {
        $recorder = new \App\DTO\WorkflowRecorder();
        $reflection = new \ReflectionClass($this->handler);
        $recorderProperty = $reflection->getProperty('recorder');
        \App\Util\ReflectionAccessor::ensureAccessible($recorderProperty);
        $recorderProperty->setValue($this->handler, $recorder);

        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/test');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('owner');
        $this->githubProvider->method('findPullRequestByBranch')->willReturn(null);

        $this->callPrivateMethod($this->handler, 'commentOnNewPullRequest', ['old', 'new']);
        $this->assertTrue(true);
    }

    private function bindSubject(): void
    {
        $this->handler = $this->createBranchRenameHandler();
    }

    private function submitHandler(?GithubGitHostingAdapter $githubProvider, PromptInterface $prompt): SubmitHandler
    {
        return new SubmitHandler(
            new SubmitGit($this->gitRepository, 'origin/develop'),
            $this->issueTracker,
            $githubProvider,
            new SubmitAssembly(
                new SubmitPrBodyBuilder(
                    ['JIRA_URL' => 'https://jira.example.com'],
                    $this->createStub(CanConvertToMarkdownInterface::class),
                ),
                new SubmitLabelPlanner(
                    $this->gitRepository,
                    $githubProvider !== null ? new SubmitLabelResolver($githubProvider, $prompt) : null,
                ),
            ),
        );
    }
}
