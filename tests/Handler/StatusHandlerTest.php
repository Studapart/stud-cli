<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
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
        TestKernel::$translationService = $this->translationService;
        $this->handler = new StatusHandler($this->gitRepository, $this->jiraService, $this->translationService);
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
    }
}
