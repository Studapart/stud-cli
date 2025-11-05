<?php

namespace App\Tests\Items;

use App\DTO\WorkItem;
use App\Items\ShowHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ShowHandlerTest extends CommandTestCase
{
    private ShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new ShowHandler($this->jiraService, [
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

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io, 'TPW-35');

        $this->assertStringContainsString('Details for issue TPW-35', $output->fetch());
    }
}
