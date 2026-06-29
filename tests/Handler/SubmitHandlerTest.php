<?php

namespace App\Tests\Handler;

use App\DTO\PullRequestData;
use App\DTO\SubmitOptions;
use App\DTO\WorkItem;
use App\Handler\SubmitHandler;
use App\Service\CanConvertToMarkdownInterface;
use App\Service\GithubGitHostingAdapter;
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
    private ?GithubGitHostingAdapter $githubProvider;
    private CanConvertToMarkdownInterface $htmlConverter;
    private \App\Service\Prompt\PromptInterface $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$issueTracker = $this->issueTracker;
        TestKernel::$translationService = $this->translationService;
        $this->prompt = $this->createMock(\App\Service\Logger::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->issueTracker,
            $this->githubProvider,
            $this->jiraConfig,
            'origin/develop',
            $this->translationService,
            $this->prompt,
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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
                    && $prData->draft === false
                    && $prData->assignToAuthor === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandlePassesAssignToAuthorIntentWhenRequested(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $this->issueTracker->method('getIssue')->willReturn(new WorkItem(
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
        ));
        $this->htmlConverter->method('toMarkdown')->willReturn('My rendered description');

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(fn ($prData): bool => $prData instanceof PullRequestData && $prData->assignToAuthor === true))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle(new SubmitOptions(assignToAuthor: true));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleReportsAssignmentFailureAfterPullRequestCreation(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $this->issueTracker->method('getIssue')->willReturn(new WorkItem(
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
        ));
        $this->htmlConverter->method('toMarkdown')->willReturn('My rendered description');

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(new \App\Exception\PullRequestAssignmentException(
                'GitHub did not confirm assignment.',
                'https://github.com/my-owner/my-repo/pull/1'
            ));


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle(new SubmitOptions(assignToAuthor: true));

        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleDerivesPrTitleFromFirstLineOfMultilineCommit(): void
    {
        $multilineMessage = "feat(my-scope): My feature [TPW-35]\n\n"
            . "Additional body paragraph describing the change.\n"
            . "It may span several lines and should not leak into the PR title.";

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn(null);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn($multilineMessage);

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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature [TPW-35]'
                    && ! str_contains($prData->title, "\n")
                    && ! str_contains($prData->title, 'Additional body paragraph');
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testExtractPrTitleFromCommitMessageReturnsEmptyForBlankInput(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('extractPrTitleFromCommitMessage');
        $method->setAccessible(true);

        $this->assertSame('', $method->invoke($this->handler, ''));
        $this->assertSame('', $method->invoke($this->handler, "\n\n  \n\t\n"));
    }

    public function testExtractPrTitleFromCommitMessageTrimsFirstLine(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('extractPrTitleFromCommitMessage');
        $method->setAccessible(true);

        $this->assertSame('subject', $method->invoke($this->handler, "  subject  \n\nbody"));
        $this->assertSame('subject', $method->invoke($this->handler, "\r\nsubject\r\nbody"));
    }

    public function testHandleResolvesJiraKeyFromCommitBodyWhenBranchHasNone(): void
    {
        $multilineMessage = "feat(my-scope): My feature\n\n"
            . "Body paragraph referencing ticket [TPW-77].";

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feature-branch');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn(null);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn($multilineMessage);

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-77',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope'],
            renderedDescription: 'My rendered description'
        );
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->title === 'feat(my-scope): My feature'
                    && str_contains($prData->body, 'TPW-77');
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleSuccessWithDraft(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle(new SubmitOptions(true));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithDirtyWorkingDirectoryLogsNoteAndSucceeds(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn(" M file1.php");
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->expects($this->once())
            ->method('toMarkdown')
            ->with('My rendered description')
            ->willReturn('My rendered description');

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleOnBaseBranch(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWhenPushFails(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithNoLogicalCommit(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(1, $response->exitCode);
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithNoJiraKeyInCommitOrBranch(): void
    {
        // Test error path when neither branch name nor commit message has Jira key
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feature-branch');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn(null);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature'); // No Jira key

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(1, $response->exitCode);
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleUnescapesCheckboxMarkdownInPrBody(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
            components: [],
            renderedDescription: '<p>Acceptance</p>'
        );
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturn("Acceptance Criteria\n\n- \\[ \\] Item one\n- \\[x] Item two");

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && str_contains($prData->body, '- [ ] Item one')
                    && str_contains($prData->body, '- [x] Item two')
                    && ! str_contains($prData->body, '\\[ \\]');
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithJiraApiClientExceptionForPrBody(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $this->issueTracker->method('getIssue')->willThrowException(new \Exception('Jira API error'));

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

        $response = $this->handler->handle();

        $outputText = $output->fetch();
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithEmptyJiraDescription(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle();

        $outputText = $output->fetch();
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPullRequestCreationApiExceptionNon422(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');

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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 500) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Internal Server Error"}',
                    500
                )
            );


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithJiraFetchApiExceptionVerbose(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');

        $this->issueTracker->method('getIssue')
            ->with('TPW-35', true)
            ->willThrowException(new \App\Exception\ApiException('Failed to fetch Jira issue.', 'HTTP 500: Internal Server Error', 500));

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithNoGitProviderConfigured(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->issueTracker,
            null, // No GithubGitHostingAdapter
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithGitProviderApiException(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider->method('createPullRequest')->willThrowException(new \Exception('GitHub API error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $outputText = $output->fetch();
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithPullRequestAlreadyExists(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider->method('createPullRequest')->willThrowException(
            new \App\Exception\ApiException(
                'Failed to create pull request.',
                'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                422
            )
        );

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $outputText = $output->fetch();
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithVerboseOutput(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider->method('createPullRequest')->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $this->handler->handle();

        $outputText = $output->fetch();
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithNullRemoteOwnerFallsBackToBranchName(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn(null);
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
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

    public function testValidateAndProcessLabelsRequiresGitProvider(): void
    {
        $handler = new SubmitHandler(
            $this->gitRepository,
            $this->issueTracker,
            null,
            $this->jiraConfig,
            'origin/develop',
            $this->translationService,
            $this->prompt,
            $this->htmlConverter
        );

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $this->expectException(\App\Exception\StudConfigException::class);
        $this->expectExceptionMessage('config.git_provider_not_configured');

        $method->invoke($handler, 'bug');
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle(new SubmitOptions(false, 'bug,enhancement'));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithLabelsFetchError(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle(new SubmitOptions(false, 'bug'));

        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithLabelsFetchErrorApiException(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $this->htmlConverter->method('toMarkdown')
            ->willReturnCallback(fn ($html) => $html);

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willThrowException(new \App\Exception\ApiException('Failed to get labels.', 'HTTP 500: Internal Server Error', 500));


        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $response = $this->handler->handle(new SubmitOptions(false, 'bug'));

        $this->assertSame(1, $response->exitCode);
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

        $this->prompt->method('choice')->willReturn('Create: Create the label on GitHub and add it to the PR');

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

        $this->prompt->method('choice')->willReturn('Ignore: Skip this label and remove it from the final list');

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

        $this->prompt->method('choice')->willReturn('Retry: Abort the command and re-run with a corrected list');

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,typo');

        $this->assertNull($result);
    }

    public function testValidateAndProcessLabelsQuietIgnoresUnknownLabels(): void
    {
        $remoteLabels = [
            ['name' => 'bug'],
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('getLabels')
            ->willReturn($remoteLabels);



        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,unknown-label', true);

        $this->assertSame(['bug'], $result);
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

        $this->prompt->method('choice')->willReturn('Create: Create the label on GitHub and add it to the PR');

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('validateAndProcessLabels');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'bug,new-label');

        $this->assertNull($result);
    }

    public function testValidateAndProcessLabelsCreateLabelFailsApiException(): void
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
            ->willThrowException(new \App\Exception\ApiException("Failed to create label 'new-label'.", 'HTTP 422: Validation Failed', 422));

        $this->prompt->method('choice')->willReturn('Create: Create the label on GitHub and add it to the PR');

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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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

        $response = $this->handler->handle(new SubmitOptions(false, 'bug'));

        // Adding labels failure causes the whole operation to fail
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithLabelsNoProvider(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->issueTracker,
            null, // No GithubGitHostingAdapter
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        // Labels should be ignored when no provider is configured
        $response = $this->handler->handle(new SubmitOptions(false, 'bug,enhancement'));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAndLabels(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                    422
                )
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

        $response = $this->handler->handle(new SubmitOptions(false, 'bug,enhancement'));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAndDraft(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                    422
                )
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

        $response = $this->handler->handle(new SubmitOptions(true));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAndLabelsAndDraft(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                    422
                )
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

        $response = $this->handler->handle(new SubmitOptions(true, 'bug'));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAlreadyDraft(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                    422
                )
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

        $response = $this->handler->handle(new SubmitOptions(true));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRFindFails(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                    422
                )
            );

        // Finding PR fails
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willThrowException(new \App\Exception\ApiException('Failed to find pull request by branch.', 'API Error', 500));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $this->handler->handle(new SubmitOptions(false, 'bug'));

        // Should still succeed even if finding PR fails
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAddLabelsFails(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

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
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                    422
                )
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
            ->willThrowException(new \App\Exception\ApiException("Failed to add labels to pull request #42.", 'Label API Error', 500));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $response = $this->handler->handle(new SubmitOptions(false, 'bug'));

        // Should still succeed even if adding labels fails
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRUpdateDraftFails(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        // PR creation fails because it already exists
        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(
                new \App\Exception\ApiException(
                    'Failed to create pull request.',
                    'GitHub API Error (Status: 422) when calling \'POST https://api.github.com/repos/owner/repo/pulls\'.' . "\n" .
                    'Response: {"message":"Validation Failed","errors":[{"resource":"PullRequest","code":"custom","message":"A pull request already exists for owner:branch."}]}',
                    422
                )
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
            ->willThrowException(new \App\Exception\ApiException('Failed to update pull request #123.', 'Draft API Error', 500));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle(new SubmitOptions(true));

        // Should still succeed even if updating draft fails
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRNoProvider(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->handler = new SubmitHandler(
            $this->gitRepository,
            $this->issueTracker,
            null, // No GithubGitHostingAdapter
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for choice() in case unknown labels trigger interactive prompt
        fwrite($inputStream, "0\n"); // Create option (in case needed)
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        // Should handle gracefully when no provider (labels/draft ignored)
        $response = $this->handler->handle(new SubmitOptions(true, 'bug'));

        $this->assertSame(0, $response->exitCode);
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
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        // Mock converter to throw DOMDocument exception
        $this->htmlConverter->expects($this->once())
            ->method('toMarkdown')
            ->with('<p>Test HTML</p>')
            ->willThrowException(new \Exception("Class 'DOMDocument' not found"));


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

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleExistingPrWithNoProviderLogsSuccessAndReturns(): void
    {
        $handler = new SubmitHandler(
            $this->gitRepository,
            $this->issueTracker,
            null,
            $this->jiraConfig,
            'origin/develop',
            $this->translationService,
            $this->prompt,
            $this->htmlConverter
        );


        $result = $this->callPrivateMethod($handler, 'handleExistingPr', ['feat/TPW-35', new SubmitOptions(), []]);

        $this->assertSame(0, $result);
    }

    public function testHandleExistingPrAssignsAuthorWhenRequested(): void
    {
        $existingPr = [
            'number' => 123,
            'draft' => false,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/123',
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('feat/TPW-35')
            ->willReturn($existingPr);
        $this->githubProvider
            ->expects($this->once())
            ->method('assignPullRequestToAuthor')
            ->with($existingPr);

        $result = $this->callPrivateMethod($this->handler, 'handleExistingPr', ['feat/TPW-35', new SubmitOptions(assignToAuthor: true), []]);

        $this->assertSame(0, $result);
    }

    public function testHandleExistingPrReportsAuthorAssignmentFailure(): void
    {
        $existingPr = [
            'number' => 123,
            'draft' => false,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/123',
        ];

        $this->githubProvider
            ->method('findPullRequestByBranch')
            ->willReturn($existingPr);
        $this->githubProvider
            ->expects($this->once())
            ->method('assignPullRequestToAuthor')
            ->with($existingPr)
            ->willThrowException(new \RuntimeException('Assignment failed'));
        $result = $this->callPrivateMethod($this->handler, 'handleExistingPr', ['feat/TPW-35', new SubmitOptions(assignToAuthor: true), []]);

        $this->assertSame(1, $result);
    }

    public function testHandleAssignsExistingPullRequestAuthorWhenCreateReportsAlreadyExists(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');

        $this->issueTracker->method('getIssue')->willReturn(new WorkItem(
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
        ));
        $this->htmlConverter->method('toMarkdown')->willReturn('My rendered description');

        $existingPr = [
            'number' => 123,
            'draft' => false,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/123',
        ];

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willThrowException(new \App\Exception\ApiException('Failed to create pull request.', 'pull request already exists', 422));
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->willReturn($existingPr);
        $this->githubProvider
            ->expects($this->once())
            ->method('assignPullRequestToAuthor')
            ->with($existingPr);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle(new SubmitOptions(assignToAuthor: true));

        $this->assertSame(0, $response->exitCode);
    }
}
