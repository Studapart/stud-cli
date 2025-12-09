<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\FilterShowHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterShowHandlerTest extends CommandTestCase
{
    private FilterShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new FilterShowHandler($this->jiraService, $this->translationService);
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
            ->with('filter = "My Filter"')
            ->willReturn([$issue]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'My Filter');

        $this->assertSame(0, $result);
    }

    public function testHandleWithNoIssuesFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('filter = "My Filter"')
            ->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'My Filter');

        $this->assertSame(0, $result);
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('filter = "My Filter"')
            ->willThrowException(new \Exception('Jira API error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'My Filter');

        $this->assertSame(1, $result);
    }

    public function testHandleWithVerboseOutput(): void
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
            ->with('filter = "My Filter"')
            ->willReturn([$issue]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io, 'My Filter');

        $this->assertSame(0, $result);
    }
}
