<?php

namespace App\Tests\Handler;

use App\DTO\PullRequestData;
use App\DTO\SubmitOptions;
use App\DTO\WorkItem;
use App\Handler\SubmitHandler;
use App\Service\CanConvertToMarkdownInterface;
use App\Service\GithubGitHostingAdapter;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\Prompt\PromptInterface;
use App\Service\SubmitLabelPlanner;
use App\Service\SubmitLabelResolver;
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
    private PromptInterface $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createStub(GitRepository::class);
        $this->issueTracker = $this->createStub(IssueTrackerPort::class);

        $this->githubProvider = $this->createStub(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createStub(CanConvertToMarkdownInterface::class);
        $this->prompt = $this->createStub(PromptInterface::class);
        $this->bindSubject();
    }

    public function testHandleSuccess(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
        $this->issueTracker->expects($this->once())->method('getIssue')->with('TPW-35')->willReturn($workItem);

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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === false
                    && $prData->assignToAuthor === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1', 'number' => 1]);


        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
        $this->assertSame(1, $response->pullNumber);
    }

    public function testAlreadyPublishedSkipsSecondPushAndKeepsRewritten(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->expects($this->never())->method('pushHeadToOrigin');
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
            renderedDescription: 'My rendered description',
        ));
        $this->htmlConverter->method('toMarkdown')->willReturn('My rendered description');
        $this->githubProvider->method('createPullRequest')->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/2', 'number' => 2]);

        $response = $this->handler->handle(new SubmitOptions(
            alreadyPublished: true,
            rewritten: true,
            priorMessages: [
                \App\DTO\ResponseMessage::error('flatten failed detail'),
                \App\DTO\ResponseMessage::warning('history rewritten'),
                \App\DTO\ResponseMessage::notice('flatten skipped'),
                \App\DTO\ResponseMessage::info('flatten info'),
            ],
        ));

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/2']);
        $this->assertSame(0, $response->exitCode);
        $this->assertTrue($response->rewritten);
        $this->assertSame(2, $response->pullNumber);
        $this->assertNotEmpty($response->getErrors());
        $this->assertNotEmpty($response->getWarnings());
        $this->assertNotEmpty($response->getNotices());
        $this->assertContains('text', array_map(static fn ($entry) => $entry->type, $response->entries));
    }

    public function testHandleUsesWorkItemUrlForLinearPrBody(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('chore/SCIL-195-x');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('SCIL-195');
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('chore(cli): work [SCIL-195]');

        $workItem = new WorkItem(
            id: 'uuid',
            key: 'SCIL-195',
            title: 'Dead code',
            status: 'Todo',
            assignee: 'Ada',
            description: "## Spec\n\nMarkdown body",
            labels: [],
            issueType: 'Task',
            renderedDescription: "## Spec\n\nMarkdown body",
            url: 'https://linear.app/studapart/issue/SCIL-195/dead-code',
        );
        $this->issueTracker->method('getIssue')->willReturn($workItem);
        $this->htmlConverter->expects($this->never())->method('toMarkdown');

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->with($this->callback(function ($prData) {
                return $prData instanceof PullRequestData
                    && $prData->body === "🔗 **Issue:** [SCIL-195](https://linear.app/studapart/issue/SCIL-195/dead-code)\n\n## Spec\n\nMarkdown body";
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/2']);

        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/2']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandlePassesAssignToAuthorIntentWhenRequested(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle(new SubmitOptions(assignToAuthor: true));

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleReportsAssignmentFailureAfterPullRequestCreation(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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



        $response = $this->handler->handle(new SubmitOptions(assignToAuthor: true));

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_assign_author');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleDerivesPrTitleFromFirstLineOfMultilineCommit(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $multilineMessage = "feat(my-scope): My feature [TPW-35]\n\n"
            . "Additional body paragraph describing the change.\n"
            . "It may span several lines and should not leak into the PR title.";

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn(null);
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testExtractPrTitleFromCommitMessageReturnsEmptyForBlankInput(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('extractPrTitleFromCommitMessage');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertSame('', $method->invoke($this->handler, ''));
        $this->assertSame('', $method->invoke($this->handler, "\n\n  \n\t\n"));
    }

    public function testExtractPrTitleFromCommitMessageTrimsFirstLine(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('extractPrTitleFromCommitMessage');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertSame('subject', $method->invoke($this->handler, "  subject  \n\nbody"));
        $this->assertSame('subject', $method->invoke($this->handler, "\r\nsubject\r\nbody"));
    }

    public function testHandleResolvesJiraKeyFromCommitBodyWhenBranchHasNone(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $multilineMessage = "feat(my-scope): My feature\n\n"
            . "Body paragraph referencing ticket [TPW-77].";

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feature-branch');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn(null);
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleSuccessWithDraft(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === true;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);


        $response = $this->handler->handle(new SubmitOptions(true));

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithDirtyWorkingDirectoryLogsNoteAndSucceeds(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn(" M file1.php");
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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



        $response = $this->handler->handle();

        $this->assertMessageRef($response->getNotices()[0]->message, 'submit.note_dirty_working');
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleOnBaseBranch(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');


        $response = $this->handler->handle();

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_base_branch');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWhenPushFails(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);


        $response = $this->handler->handle();

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_push');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithNoLogicalCommit(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn(null);


        $response = $this->handler->handle();

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_no_logical');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithNoJiraKeyInCommitMessage(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        // Test that branch name is used first when commit message doesn't have Jira key
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $process = $this->createStub(Process::class);
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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);


        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithNoJiraKeyInCommitOrBranch(): void
    {
        // Test error path when neither branch name nor commit message has Jira key
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feature-branch');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn(null);
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature'); // No Jira key


        $response = $this->handler->handle();

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_no_jira_key');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithJiraKeyInBranchNameTakesPriority(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        // Test that branch name Jira key takes priority even if commit message has different key
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleUnescapesCheckboxMarkdownInPrBody(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithJiraApiClientExceptionForPrBody(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createStub(Process::class);
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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nResolves: https://my-jira.com/browse/TPW-35"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $outputText = $output->fetch();
        $this->assertMessageRef($response->getWarnings()[0]->message, 'submit.warning_issue_fetch');
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithResolutionHintOnPrBodyFetchFailure(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('chore/SCIL-195-x');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('SCIL-195');
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('chore(cli): work [SCIL-195]');

        $hint = \App\DTO\MessageRef::key('issue_tracker_provider.fetch_failed_prefix_matches_other', [
            '%key%' => 'SCIL-195',
            '%attempted%' => 'jira',
            '%prefix%' => 'SCIL',
            '%alternate%' => 'linear',
        ]);
        $this->issueTracker->method('getIssue')->willThrowException(
            new \App\Exception\ApiException('not found', 'HTTP 404', 404, null, $hint),
        );

        $this->githubProvider
            ->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $response = $this->handler->handle();

        $this->assertMessageRef($response->getWarnings()[0]->message, 'issue_tracker_provider.fetch_failed_prefix_matches_other', [
            '%key%' => 'SCIL-195',
            '%attempted%' => 'jira',
            '%prefix%' => 'SCIL',
            '%alternate%' => 'linear',
        ]);
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithEmptyJiraDescription(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createStub(Process::class);
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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nResolves: https://my-jira.com/browse/TPW-35"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle();

        $outputText = $output->fetch();
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithPullRequestCreationApiExceptionNon422(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');

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



        $response = $this->handler->handle();

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_create_pr');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithJiraFetchApiExceptionVerbose(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $this->gitRepository->method('getMergeBase')->willReturn('abcdef');
        $this->gitRepository->method('findFirstLogicalSha')->willReturn('ghijkl');
        $this->gitRepository->method('getCommitMessage')->willReturn('feat(my-scope): My feature [TPW-35]');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');

        $this->issueTracker->expects($this->once())->method('getIssue')
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

        $this->assertMessageRef($response->getWarnings()[0]->message, 'submit.warning_issue_fetch');
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithNoGitProviderConfigured(): void
    {
        $htmlConverter = $this->createStub(CanConvertToMarkdownInterface::class);
        $this->handler = \App\Tests\Support\ConstructorFixtures::submitHandler(
            new \App\Service\SubmitGit($this->gitRepository, 'origin/develop'),
            $this->issueTracker,
            null,
            new \App\Service\SubmitAssembly(
                new \App\Service\SubmitPrBodyBuilder($this->jiraConfig, $htmlConverter),
                new SubmitLabelPlanner($this->gitRepository, null),
            ),
        );

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle();

        $this->assertMessageRef($response->getWarnings()[0]->message, 'submit.warning_no_provider');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithGitProviderApiException(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_create_pr');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithPullRequestAlreadyExists(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
        $this->assertMessageRef($response->getNotices()[0]->message, 'submit.note_pr_exists');
        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithVerboseOutput(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithNullRemoteOwnerFallsBackToBranchName(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn(null);
        $process = $this->createStub(Process::class);
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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
                    && $prData->draft === false;
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);


        $response = $this->handler->handle();

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleSuccessWithLabels(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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

        $this->htmlConverter->expects($this->once())->method('toMarkdown')
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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\nMy rendered description"
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

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithLabelsFetchError(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_fetch_labels');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithLabelsFetchErrorApiException(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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

        $this->assertMessageRef($response->getErrorMessage(), 'submit.error_fetch_labels');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithLabelsAddLabelsError(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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

        $this->assertWorkflowEntry($response, 'submit.error_create_pr');
        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleWithLabelsNoProvider(): void
    {
        $htmlConverter = $this->createStub(CanConvertToMarkdownInterface::class);
        $this->handler = \App\Tests\Support\ConstructorFixtures::submitHandler(
            new \App\Service\SubmitGit($this->gitRepository, 'origin/develop'),
            $this->issueTracker,
            null,
            new \App\Service\SubmitAssembly(
                new \App\Service\SubmitPrBodyBuilder($this->jiraConfig, $htmlConverter),
                new SubmitLabelPlanner($this->gitRepository, null),
            ),
        );

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createStub(Process::class);
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
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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

        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
        $this->assertSame(42, $response->pullNumber);
    }

    public function testHandleWithExistingPRAndDraft(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle(new SubmitOptions(true));

        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAndLabelsAndDraft(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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

        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAlreadyDraft(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle(new SubmitOptions(true));

        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRFindFails(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRAddLabelsFails(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
        $this->assertMessageRef($response->getWarnings()[0]->message, 'submit.error_add_labels');
        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRUpdateDraftFails(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle(new SubmitOptions(true));

        // Should still succeed even if updating draft fails
        $this->assertMessageRef($response->getWarnings()[0]->message, 'submit.error_update_draft');
        $this->assertWorkflowEntry($response, 'submit.success_pushed');
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithExistingPRNoProvider(): void
    {
        $htmlConverter = $this->createStub(CanConvertToMarkdownInterface::class);
        $this->handler = \App\Tests\Support\ConstructorFixtures::submitHandler(
            new \App\Service\SubmitGit($this->gitRepository, 'origin/develop'),
            $this->issueTracker,
            null,
            new \App\Service\SubmitAssembly(
                new \App\Service\SubmitPrBodyBuilder($this->jiraConfig, $htmlConverter),
                new SubmitLabelPlanner($this->gitRepository, null),
            ),
        );

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $process = $this->createStub(Process::class);
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

        $this->assertWorkflowEntry($response, 'submit.warning_no_provider');
        $this->assertSame(0, $response->exitCode);
    }

    // Note: HTML-to-Markdown conversion tests were moved to JiraHtmlConverterTest
    // as the conversion logic is now in the JiraHtmlConverter service

    public function testHandleWithHtmlConverterDOMDocumentException(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('my-repo');
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $process = $this->createStub(Process::class);
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
                    && $prData->body === "🔗 **Issue:** [TPW-35](https://my-jira.com/browse/TPW-35)\n\n<p>Test HTML</p>";
            }))
            ->willReturn(['html_url' => 'https://github.com/my-owner/my-repo/pull/1']);


        $response = $this->handler->handle();

        $this->assertMessageRef($response->getWarnings()[0]->message, 'submit.warning_html_xml_missing');
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/1']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleExistingPrWithNoProviderLogsSuccessAndReturns(): void
    {
        $handler = \App\Tests\Support\ConstructorFixtures::submitHandler(
            new \App\Service\SubmitGit($this->gitRepository, 'origin/develop'),
            $this->issueTracker,
            null,
            new \App\Service\SubmitAssembly(new \App\Service\SubmitPrBodyBuilder($this->jiraConfig, $this->htmlConverter), new SubmitLabelPlanner($this->gitRepository, null)),
        );


        $result = $this->callPrivateMethod($handler, 'handleExistingPr', ['feat/TPW-35', new SubmitOptions(), []]);

        $this->assertSame(0, $result);
    }

    public function testHandleExistingPrAssignsAuthorWhenRequested(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

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
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

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
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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


        $response = $this->handler->handle(new SubmitOptions(assignToAuthor: true));

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleAppliesPullRequestLabelDefaultWhenOpeningNewPullRequest(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willReturn(['pullRequestLabels' => ['bug']]);
        $this->githubProvider->method('getLabels')->willReturn([['name' => 'bug']]);
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['number' => 7, 'html_url' => 'https://github.com/my-owner/my-repo/pull/7']);
        $this->githubProvider->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->with(7, ['bug']);
        $this->githubProvider->expects($this->never())->method('findPullRequestByBranch');

        $response = $this->handler->handle(new SubmitOptions());

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/7']);
        $this->assertSame(0, $response->exitCode);
        $this->assertSame(7, $response->pullNumber);
    }

    public function testHandleOpensPullRequestWithoutLabelsWhenDefaultLabelCatalogCannotBeRead(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willReturn(['pullRequestLabels' => ['bug']]);
        $this->githubProvider->method('getLabels')->willThrowException(
            new \App\Exception\ApiException('Failed to fetch labels.', 'network', 500),
        );
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['number' => 11, 'html_url' => 'https://github.com/my-owner/my-repo/pull/11']);
        $this->githubProvider->expects($this->never())->method('addLabelsToPullRequest');

        $response = $this->handler->handle(new SubmitOptions());

        $this->assertMessageRef($response->getWarnings()[0]->message, 'submit.warning_label_default_unavailable');
        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/11']);
        $this->assertSame(0, $response->exitCode);
        $this->assertSame(11, $response->pullNumber);
        $warningParameters = [];
        foreach ($response->entries as $entry) {
            if ($entry->type === 'warning' && $entry->message instanceof \App\DTO\MessageRef) {
                $warningKeys[] = $entry->message->key;
                $warningParameters[] = $entry->message->parameters;
            }
        }
        $this->assertContains('submit.warning_label_default_unavailable', $warningKeys);
        $this->assertContains(['error' => 'Failed to fetch labels.'], $warningParameters);
    }

    public function testHandleDoesNotCreatePullRequestWhenDefaultLabelRetryAborts(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willReturn(['pullRequestLabels' => ['bug']]);
        $this->githubProvider->method('getLabels')->willReturn([]);
        $this->prompt->method('choice')->willReturn('Retry: Abort the command and re-run with a corrected list');
        $this->githubProvider->expects($this->never())->method('createPullRequest');

        $response = $this->handler->handle(new SubmitOptions());

        $this->assertSame(1, $response->exitCode);
    }

    public function testHandleDoesNotApplyPullRequestLabelDefaultToExistingPullRequest(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willReturn(['pullRequestLabels' => ['bug']]);
        $this->githubProvider->method('getLabels')->willReturn([['name' => 'bug']]);
        $this->githubProvider->method('createPullRequest')->willThrowException(
            new \App\Exception\ApiException('Failed to create pull request.', 'pull request already exists', 422),
        );
        $this->githubProvider->method('findPullRequestByBranch')->willReturn([
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => false,
        ]);
        $this->githubProvider->expects($this->never())->method('addLabelsToPullRequest');

        $response = $this->handler->handle(new SubmitOptions());

        $this->assertSame(0, $response->exitCode);
        $this->assertSame(42, $response->pullNumber);
    }

    public function testHandleOpensPullRequestWithoutLabelsWhenProjectConfigIsUnreadable(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willThrowException(new \RuntimeException('Not in a git repository.'));
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['number' => 8, 'html_url' => 'https://github.com/my-owner/my-repo/pull/8']);
        $this->githubProvider->expects($this->never())->method('addLabelsToPullRequest');

        $response = $this->handler->handle(new SubmitOptions());

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/8']);
        $this->assertSame(0, $response->exitCode);
        $this->assertSame(8, $response->pullNumber);
    }

    public function testHandleUsesExplicitLabelsInsteadOfPullRequestDefault(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willReturn(['pullRequestLabels' => ['bug']]);
        $this->githubProvider->method('getLabels')->willReturn([['name' => 'enhancement'], ['name' => 'bug']]);
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['number' => 9, 'html_url' => 'https://github.com/my-owner/my-repo/pull/9']);
        $this->githubProvider->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->with(9, ['enhancement']);

        $response = $this->handler->handle(new SubmitOptions(labels: 'enhancement'));

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/9']);
        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleUsesExplicitLabelsOnExistingPullRequestInsteadOfDefault(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willReturn(['pullRequestLabels' => ['bug']]);
        $this->githubProvider->method('getLabels')->willReturn([['name' => 'enhancement'], ['name' => 'bug']]);
        $this->githubProvider->method('createPullRequest')->willThrowException(
            new \App\Exception\ApiException('Failed to create pull request.', 'pull request already exists', 422),
        );
        $this->githubProvider->method('findPullRequestByBranch')->willReturn([
            'number' => 42,
            'html_url' => 'https://github.com/my-owner/my-repo/pull/42',
            'draft' => false,
        ]);
        $this->githubProvider->expects($this->once())
            ->method('addLabelsToPullRequest')
            ->with(42, ['enhancement']);

        $response = $this->handler->handle(new SubmitOptions(labels: 'enhancement'));

        $this->assertSame(0, $response->exitCode);
        $this->assertSame(42, $response->pullNumber);
    }

    public function testHandleTreatsEmptyLabelStringAsOptOutWhenPullRequestDefaultIsSet(): void
    {
        $this->githubProvider = $this->createMock(GithubGitHostingAdapter::class);
        $this->bindSubject();

        $this->stubSubmitPreflight();
        $this->gitRepository->method('readProjectConfig')->willReturn(['pullRequestLabels' => ['bug']]);
        $this->githubProvider->expects($this->once())
            ->method('createPullRequest')
            ->willReturn(['number' => 10, 'html_url' => 'https://github.com/my-owner/my-repo/pull/10']);
        $this->githubProvider->expects($this->never())->method('addLabelsToPullRequest');
        $this->githubProvider->expects($this->never())->method('getLabels');

        $response = $this->handler->handle(new SubmitOptions(labels: ''));

        $this->assertWorkflowEntry($response, 'submit.success_created', ['url' => 'https://github.com/my-owner/my-repo/pull/10']);
        $this->assertSame(0, $response->exitCode);
        $this->assertSame(10, $response->pullNumber);
    }

    private function stubSubmitPreflight(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $process = $this->createStub(Process::class);
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
            renderedDescription: 'My rendered description',
        ));
    }

    private function bindSubject(): void
    {
        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$issueTracker = $this->issueTracker;
        TestKernel::$translationService = $this->translationService;
        $this->handler = \App\Tests\Support\ConstructorFixtures::submitHandler(
            new \App\Service\SubmitGit($this->gitRepository, 'origin/develop'),
            $this->issueTracker,
            $this->githubProvider,
            new \App\Service\SubmitAssembly(new \App\Service\SubmitPrBodyBuilder($this->jiraConfig, $this->htmlConverter), new SubmitLabelPlanner(
                $this->gitRepository,
                $this->githubProvider !== null ? new SubmitLabelResolver($this->githubProvider, $this->prompt) : null,
            )),
        );
    }
}
