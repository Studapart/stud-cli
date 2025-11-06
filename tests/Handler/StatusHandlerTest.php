<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Handler\StatusHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class StatusHandlerTest extends CommandTestCase
{
    private StatusHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new StatusHandler($this->gitRepository, $this->jiraService);
    }

    public function testHandle(): void
    {
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getPorcelainStatus')->willReturn(" M file1.php\n D file2.php");

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope']
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Jira:   [In Progress] TPW-35: My feature', $outputText);
        $this->assertStringContainsString('Git:    On branch \'feat/TPW-35-my-feature\'', $outputText);
        $this->assertStringContainsString('Local:  You have 2 uncommitted changes.', $outputText);
    }

    public function testHandleWithNoJiraKey(): void
    {
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn(null);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('getPorcelainStatus')->willReturn("");

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Jira:   No Jira key found in branch name.', $outputText);
        $this->assertStringContainsString('Git:    On branch \'main\'', $outputText);
        $this->assertStringContainsString('Local:  Working directory is clean.', $outputText);
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getPorcelainStatus')->willReturn("");

        $this->jiraService->method('getIssue')->willThrowException(new \Exception('Jira API error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Jira:   Could not fetch Jira issue details: Jira API error', $outputText);
        $this->assertStringContainsString('Git:    On branch \'feat/TPW-35-my-feature\'', $outputText);
        $this->assertStringContainsString('Local:  Working directory is clean.', $outputText);
    }

    public function testHandleWithCleanWorkingDirectory(): void
    {
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getPorcelainStatus')->willReturn("");

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope']
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Jira:   [In Progress] TPW-35: My feature', $outputText);
        $this->assertStringContainsString('Git:    On branch \'feat/TPW-35-my-feature\'', $outputText);
        $this->assertStringContainsString('Local:  Working directory is clean.', $outputText);
    }

    public function testHandleWithVerboseOutput(): void
    {
        $this->gitRepository->method('getJiraKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getPorcelainStatus')->willReturn(" M file1.php");

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['my-scope']
        );
        $this->jiraService->method('getIssue')->willReturn($workItem);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Fetching status for Jira issue: TPW-35', $outputText);
        $this->assertStringContainsString('Jira:   [In Progress] TPW-35: My feature', $outputText);
        $this->assertStringContainsString('Git:    On branch \'feat/TPW-35-my-feature\'', $outputText);
        $this->assertStringContainsString('Local:  You have 1 uncommitted changes.', $outputText);
    }
}
