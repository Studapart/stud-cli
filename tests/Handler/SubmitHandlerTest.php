<?php

namespace App\Tests\Handler;

use App\DTO\PullRequestData;
use App\DTO\WorkItem;
use App\Handler\SubmitHandler;
use App\Service\CanConvertToMarkdownInterface;
use App\Service\GithubProvider;
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
    private CanConvertToMarkdownInterface $htmlConverter;
    private \App\Service\Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubProvider::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $this->logger = $this->createMock(\App\Service\Logger::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->githubProvider,
            $this->jiraConfig,
            'origin/develop',
            $this->translationService,
            $this->logger,
            $this->htmlConverter
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

        $this->htmlConverter->expects($this->once())
            ->method('toMarkdown')
            ->with('My rendered description')
            ->willReturn('My rendered description');

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-35]'
                    && $prData->head === 'studapart:feat/TPW-35-my-feature'
                    && $prData->base === 'develop'
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleSuccessWithDraft(): void
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

        $this->htmlConverter->expects($this->once())
            ->method('toMarkdown')
            ->with('My rendered description')
            ->willReturn('My rendered description');

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-35]'
                    && $prData->head === 'studapart:feat/TPW-35-my-feature'
                    && $prData->base === 'develop'
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === true;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

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
        // Test that branch name is used first when commit message doesn't have Jira key
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature'); // No Jira key

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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature'
                    && $prData->head === 'studapart:feat/TPW-35-my-feature'
                    && $prData->base === 'develop'
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithNoJiraKeyInCommitOrBranch(): void
    {
        // Test error path when neither branch name nor commit message has Jira key
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feature-branch');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn(null);
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

    public function testHandleWithJiraKeyInBranchNameTakesPriority(): void
    {
        // Test that branch name Jira key takes priority even if commit message has different key
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-99]'); // Different key in commit

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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                // Should use TPW-35 from branch name, not TPW-99 from commit message
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-99]' // PR title still uses commit message
                    && $prData->head === 'studapart:feat/TPW-35-my-feature'
                    && $prData->base === 'develop'
                    && str_contains($prData->body, 'TPW-35') // But PR body uses branch name key
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
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

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-35]'
                    && $prData->base === 'develop'
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nResolves: https://my-jira.com/browse/TPW-35"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-35]'
                    && $prData->base === 'develop'
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nResolves: https://my-jira.com/browse/TPW-35"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $outputText = $output->fetch();
        $this->assertSame(0, $result);
    }

    public function testHandleWithNoGitProviderConfigured(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->jiraService,
            null, // No GithubProvider
            $this->jiraConfig,
            'origin/develop',
            $this->translationService,
            $logger,
            $htmlConverter
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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-35]'
                    && $prData->head === 'feat/TPW-35-my-feature'
                    && $prData->base === 'develop'
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testValidateAndProcessLabelsAllValid(): void
    {
        $remoteLabels = [
            ['name' => 'bug'],
            ['name' => 'enhancement'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,enhancement');

        $this->assertSame(['bug', 'enhancement'], $result);
    }

    public function testValidateAndProcessLabelsEmptyInput(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, '');

        $this->assertSame([], $result);
    }

    public function testValidateAndProcessLabelsNullInput(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        // Test with whitespace-only input
        $result = $method->invoke($this->handler, '  ,  ,  ');

        $this->assertSame([], $result);
    }

    public function testValidateAndProcessLabelsCaseInsensitive(): void
    {
        $remoteLabels = [
            ['name' => 'Bug'], // Note: capital B
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        // Request lowercase 'bug' but it should match 'Bug' from GitHub
        $result = $method->invoke($this->handler, 'bug');

        $this->assertSame(['Bug'], $result); // Should use the exact case from GitHub
    }

    public function testHandleSuccessWithLabels(): void
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

        $this->htmlConverter->method('toMarkdown')
            ->with('My rendered description')
            ->willReturn('My rendered description');

        $remoteLabels = [
            ['name' => 'bug'],
            ['name' => 'enhancement'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-35]'
                    && $prData->head === 'studapart:feat/TPW-35-my-feature'
                    && $prData->base === 'develop'
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1', 'number' => 1]);

        $this->githubProvider
            ->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->with(1, ['bug', 'enhancement']);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, false, 'bug,enhancement');

        $this->assertSame(0, $result);
    }

    public function testHandleWithLabelsFetchError(): void
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

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willThrowException(new \Exception('API Error'));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, false, 'bug');

        $this->assertSame(1, $result);
    }

    public function testValidateAndProcessLabelsCreateMissingLabel(): void
    {
        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $this->githubProvider
            ->expects($this->once())
            ->method('createLabel')
            ->with('new-label', $this->matchesRegularExpression('/^[0-9a-f]{6}$/i'), null)
            ->willReturn(['name' => 'new-label']);

        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        // Mock the methods that will be called
        $this->logger->expects($this->exactly(2))
            ->method('text')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->anything());

        $this->logger->expects($this->once())
            ->method('choice')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn($this->translationService->trans('submit.label_create_option'));

        $this->logger->expects($this->once())
            ->method('success')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->anything());

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,new-label');

        $this->assertCount(2, $result);
        $this->assertContains('bug', $result);
        $this->assertContains('new-label', $result);
    }

    public function testValidateAndProcessLabelsIgnoreMissingLabel(): void
    {
        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $this->githubProvider
            ->expects($this->never())
            ->method('createLabel');

        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('text')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->anything());

        $this->logger->expects($this->once())
            ->method('choice')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn($this->translationService->trans('submit.label_ignore_option'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,typo');

        $this->assertSame(['bug'], $result);
    }

    public function testValidateAndProcessLabelsRetryMissingLabel(): void
    {
        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $this->githubProvider
            ->expects($this->never())
            ->method('createLabel');

        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('text')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->anything());

        $this->logger->expects($this->once())
            ->method('choice')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn($this->translationService->trans('submit.label_retry_option'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,typo');

        $this->assertNull($result);
    }

    public function testValidateAndProcessLabelsCreateLabelFails(): void
    {
        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $this->githubProvider
            ->expects($this->once())
            ->method('createLabel')
            ->willThrowException(new \Exception('API Error'));

        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->exactly(2))
            ->method('text')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->anything());

        $this->logger->expects($this->once())
            ->method('choice')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn($this->translationService->trans('submit.label_create_option'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->anything());

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,new-label');

        $this->assertNull($result);
    }

    public function testValidateAndProcessLabelsIgnoreWithVerbose(): void
    {
        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('text')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->anything());

        $this->logger->expects($this->once())
            ->method('choice')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn($this->translationService->trans('submit.label_ignore_option'));

        $this->logger->expects($this->once())
            ->method('writeln')
            ->with(\App\Service\Logger::VERBOSITY_VERBOSE, $this->stringContains('ignored'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,typo');

        $this->assertSame(['bug'], $result);
    }

    public function testHandleWithLabelsAddLabelsError(): void
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

        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1', 'number' => 1]);

        $this->githubProvider
            ->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->willThrowException(new \Exception('API Error'));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, false, 'bug');

        // Adding labels failure causes the whole operation to fail
        $this->assertSame(1, $result);
    }

    public function testHandleWithLabelsNoProvider(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->jiraService,
            null, // No GithubProvider
            $this->jiraConfig,
            'origin/develop',
            $this->translationService,
            $logger,
            $htmlConverter
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
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        // Labels should be ignored when no provider is configured
        $result = $this->handler->handle($io, false, 'bug,enhancement');

        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRAndLabels(): void
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

        $remoteLabels = [
            ['name' => 'bug'],
            ['name' => 'enhancement'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                              'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
            );

        // Should find existing PR
        $existingPr = [
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => false,
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn($existingPr);

        // Should add labels to existing PR
        $this->githubProvider
            ->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->with(42, ['bug', 'enhancement']);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, false, 'bug,enhancement');

        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRAndDraft(): void
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

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                              'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
            );

        // Should find existing PR (not draft)
        $existingPr = [
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => false,
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn($existingPr);

        // Should update PR to draft
        $this->githubProvider
            ->expects($this->once())
            ->method('updatePullRequest')
            ->with(42, true);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRAndLabelsAndDraft(): void
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

        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                              'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
            );

        // Should find existing PR (not draft)
        $existingPr = [
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => false,
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn($existingPr);

        // Should add labels
        $this->githubProvider
            ->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->with(42, ['bug']);

        // Should update PR to draft
        $this->githubProvider
            ->expects($this->once())
            ->method('updatePullRequest')
            ->with(42, true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, true, 'bug');

        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRAlreadyDraft(): void
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

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                              'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
            );

        // Should find existing PR (already draft)
        $existingPr = [
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => true,
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn($existingPr);

        // Should NOT update PR to draft (already draft)
        $this->githubProvider
            ->expects($this->never())
            ->method('updatePullRequest');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRFindFails(): void
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

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                              'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
            );

        // Finding PR fails
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willThrowException(new \Exception('API Error'));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io, false, 'bug');

        // Should still succeed even if finding PR fails
        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRAddLabelsFails(): void
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

        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                              'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
            );

        $existingPr = [
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => false,
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn($existingPr);

        // Adding labels fails
        $this->githubProvider
            ->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->willThrowException(new \Exception('Label API Error'));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, false, 'bug');

        // Should still succeed even if adding labels fails
        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRUpdateDraftFails(): void
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

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \Exception('GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                              'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}')
            );

        $existingPr = [
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => false,
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn($existingPr);

        // Updating draft fails
        $this->githubProvider
            ->expects($this->once())
            ->method('updatePullRequest')
            ->willThrowException(new \Exception('Draft API Error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true);

        // Should still succeed even if updating draft fails
        $this->assertSame(0, $result);
    }

    public function testHandleWithExistingPRNoProvider(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->jiraService,
            null, // No GithubProvider
            $this->jiraConfig,
            'origin/develop',
            $this->translationService,
            $logger,
            $htmlConverter
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
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        // Should handle gracefully when no provider (labels/draft ignored)
        $result = $this->handler->handle($io, true, 'bug');

        $this->assertSame(0, $result);
    }

    // Note: HTML-to-Markdown conversion tests were moved to JiraHtmlConverterTest
    // as the conversion logic is now in the JiraHtmlConverter service

    public function testHandleWithHtmlConverterDOMDocumentException(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('my-repo');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
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
            renderedDescription: '<p>Test HTML</p>'
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        // Mock converter to throw DOMDocument exception
        $this->htmlConverter->expects($this->once())
            ->method('toMarkdown')
            ->with('<p>Test HTML</p>')
            ->willThrowException(new \Exception("Class 'DOMDocument' not found"));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                [
                    'HTML to Markdown conversion failed: PHP XML extension is missing.',
                    'Install it using:',
                    '  Ubuntu/Debian: sudo apt-get install php-xml',
                    '  Fedora/RHEL: sudo dnf install php-xml',
                    '  macOS (Homebrew): brew install php-xml',
                    'Using raw HTML for PR description.',
                ]
            );

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                // Should use original HTML when DOMDocument exception occurs
                return $prData instanceof PullRequestData
                    && $prData->body === "🔗 **Jira Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\n<p>Test HTML</p>";
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }
}
