<?php

namespace App\Tests\Status;

use App\DTO\WorkItem;
use App\Git\GitRepository;
use App\Jira\JiraService;
use App\Status\StatusHandler;
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
}
