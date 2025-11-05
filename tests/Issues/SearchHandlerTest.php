<?php

namespace App\Tests\Issues;

use App\DTO\WorkItem;
use App\Issues\SearchHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchHandlerTest extends CommandTestCase
{
    private SearchHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new SearchHandler($this->jiraService);
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
            ->method('searchIssues')
            ->with('project = TPW')
            ->willReturn([$issue]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io, 'project = TPW');

        $this->assertStringContainsString('Searching Jira issues with JQL', $output->fetch());
    }
}
