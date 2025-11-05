<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemShowHandler;
use App\Service\JiraService;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\TableSeparator;

class ItemShowHandlerTest extends CommandTestCase
{
    private ItemShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new ItemShowHandler($this->jiraService, [
            'JIRA_URL' => 'https://studapart.atlassian.net',
        ]);
    }

    public function testHandle(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                ['Key' => 'TPW-35'],
                ['Title' => 'Create PHPUnit Test Suite for stud-cli Command Logic'],
                ['Status' => 'To Do'],
                ['Assignee' => 'Pierre-Emmanuel MANTEAU'],
                ['Type' => 'Task'],
                ['Labels' => 'tests'],
                new TableSeparator(),
                ['Description' => 'description'],
                new TableSeparator(),
                ['Link' => 'https://studapart.atlassian.net/browse/TPW-35']
            );

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Issue not found'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('error')
            ->with('Could not find Jira issue with key "TPW-35".');

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithVerboseOutput(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with('  <fg=gray>Fetching details for issue: TPW-35</>');
        $io->expects($this->once())
            ->method('definitionList'); // We don't care about the content here, just that it's called

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithNoLabels(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            [], // No labels
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                ['Key' => 'TPW-35'],
                ['Title' => 'My awesome feature'],
                ['Status' => 'In Progress'],
                ['Assignee' => 'John Doe'],
                ['Type' => 'Task'],
                ['Labels' => 'None'],
                new TableSeparator(),
                ['Description' => 'description'],
                new TableSeparator(),
                ['Link' => 'https://studapart.atlassian.net/browse/TPW-35']
            );

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithMultipleLabels(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['label1', 'label2', 'label3'], // Multiple labels
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                ['Key' => 'TPW-35'],
                ['Title' => 'My awesome feature'],
                ['Status' => 'In Progress'],
                ['Assignee' => 'John Doe'],
                ['Type' => 'Task'],
                ['Labels' => 'label1, label2, label3'],
                new TableSeparator(),
                ['Description' => 'description'],
                new TableSeparator(),
                ['Link' => 'https://studapart.atlassian.net/browse/TPW-35']
            );

        $this->handler->handle($io, 'TPW-35');
    }
}
