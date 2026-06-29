<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\StatusHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;

class StatusHandlerTest extends CommandTestCase
{
    private StatusHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$issueTracker = $this->issueTracker;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new StatusHandler($this->gitRepository, $this->issueTracker, $this->translationService);
    }

    public function testHandle(): void
    {
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
        $this->assertTrue($response->isSuccess());
    }

    public function testHandleWithNoJiraKey(): void
    {
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn(null);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithJiraApiClientException(): void
    {
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');

        $this->issueTracker->method('getIssue')->willThrowException(new \Exception('Jira API error'));

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithJiraApiClientApiException(): void
    {
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');

        $this->issueTracker->method('getIssue')->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "TPW-35".', 'HTTP 404: Not Found', 404));

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
        $this->assertNotEmpty($response->entries);
    }

    public function testHandleWithCleanWorkingDirectory(): void
    {
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');

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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }

    public function testHandleWithVerboseOutput(): void
    {
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
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
        $this->issueTracker->method('getIssue')->willReturn($workItem);

        $response = $this->handler->handle();

        $this->assertSame(0, $response->exitCode);
    }
}
