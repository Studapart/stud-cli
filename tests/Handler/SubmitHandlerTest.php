<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Service\GitRepository;
use App\Service\GithubProvider;
use App\Service\JiraService;
use App\Handler\SubmitHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class SubmitHandlerTest extends CommandTestCase
{
    private SubmitHandler $handler;
    private array $jiraConfig = [
        'JIRA_URL' => 'https://my-jira.com',
    ];
    private ?GithubProvider $githubProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubProvider::class);
        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->githubProvider,
            $this->jiraConfig,
            'origin/develop'
        );
    }

    public function testHandleSuccess(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: 'My rendered description'
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with(
                'feat(my-scope): My feature [TPW-35]',
                'studapart:feat/TPW-35-my-feature',
                'develop',
                'My rendered description'
            )
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithDirtyWorkingDirectory(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn(" M file1.php");

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }

    public function testHandleOnBaseBranch(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }

    public function testHandleWhenPushFails(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }

    public function testHandleWithNoLogicalCommit(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }

    public function testHandleWithNoJiraKeyInCommitMessage(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature'); // No Jira key

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }

    public function testHandleWithJiraServiceExceptionForPrBody(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $this->jiraService->method('getIssue')->willThrowException(new \Exception('Jira API error'));

        $this->githubProvider->method('createPullRequest')->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $outputText = $output->fetch();
        $this->assertSame(0, $result);
    }

    public function testHandleWithEmptyJiraDescription(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: null // Empty description
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $this->githubProvider->method('createPullRequest')->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $outputText = $output->fetch();
        $this->assertSame(0, $result);
    }

    public function testHandleWithNoGitProviderConfigured(): void
    {
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->jiraService,
            null, // No GithubProvider
            $this->jiraConfig,
            'origin/develop'
        );

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: 'My rendered description'
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithGitProviderApiException(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: 'My rendered description'
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $this->githubProvider->method('createPullRequest')->willThrowException(new \Exception('GitHub API error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $outputText = $output->fetch();
        $this->assertSame(1, $result);
    }

    public function testHandleWithPullRequestAlreadyExists(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: 'My rendered description'
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $this->githubProvider->method('createPullRequest')->willThrowException(
            new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" . 
                          'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
        );

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $outputText = $output->fetch();
        $this->assertSame(0, $result);
    }

    public function testHandleWithVerboseOutput(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: 'My rendered description'
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $this->githubProvider->method('createPullRequest')->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io);

        $outputText = $output->fetch();
        $this->assertSame(0, $result);
    }

    public function testHandleWithNullRemoteOwnerFallsBackToBranchName(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn(null);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: 'My rendered description'
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with(
                'feat(my-scope): My feature [TPW-35]',
                'feat/TPW-35-my-feature',
                'develop',
                'My rendered description'
            )
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }
}
