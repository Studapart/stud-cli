<?php

namespace App\Tests\Submit;

use App\DTO\WorkItem;
use App\Git\GitRepository;
use App\GitProvider\GithubProvider;
use App\Jira\JiraService;
use App\Submit\SubmitHandler;
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

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $this->assertStringContainsString('✅ Pull Request created: https://github.com/my-owner/my-repo/pull/1', $output->fetch());
    }
}
