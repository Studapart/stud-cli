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
        $this->handler = new FilterShowHandler($this->jiraService, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $this->translationService);
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

    public function testHandleWithPriorityColumn(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            'description',
            ['tests'],
            'Task',
            [],
            'High'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('filter = "My Filter"')
            ->willReturn([$issue]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('My Filter'));
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return in_array('table.key', $headers) &&
                        in_array('table.status', $headers) &&
                        in_array('table.priority', $headers) &&
                        in_array('table.description', $headers) &&
                        in_array('table.jira_url', $headers);
                }),
                $this->callback(function ($rows) {
                    return count($rows) === 1 &&
                        count($rows[0]) === 5 &&
                        $rows[0][0] === 'TPW-35' &&
                        $rows[0][1] === 'To Do' &&
                        $rows[0][2] === 'High' &&
                        $rows[0][3] === 'Create PHPUnit Test Suite for stud-cli Command Logic' &&
                        $rows[0][4] === 'https://your-company.atlassian.net/browse/TPW-35';
                })
            );

        $result = $this->handler->handle($io, 'My Filter');

        $this->assertSame(0, $result);
    }

    public function testHandleWithoutPriorityColumn(): void
    {
        $issue1 = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            'description',
            ['tests'],
            'Task',
            [],
            null
        );

        $issue2 = new WorkItem(
            '1001',
            'TPW-36',
            'Another Task',
            'In Progress',
            'John Doe',
            'description',
            [],
            'Task',
            [],
            null
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('filter = "My Filter"')
            ->willReturn([$issue1, $issue2]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('My Filter'));
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return in_array('table.key', $headers) &&
                        in_array('table.status', $headers) &&
                        ! in_array('table.priority', $headers) &&
                        in_array('table.description', $headers) &&
                        in_array('table.jira_url', $headers);
                }),
                $this->callback(function ($rows) {
                    return count($rows) === 2 &&
                        count($rows[0]) === 4 &&
                        $rows[0][0] === 'TPW-35' &&
                        $rows[0][1] === 'To Do' &&
                        $rows[0][2] === 'Create PHPUnit Test Suite for stud-cli Command Logic' &&
                        $rows[0][3] === 'https://your-company.atlassian.net/browse/TPW-35';
                })
            );

        $result = $this->handler->handle($io, 'My Filter');

        $this->assertSame(0, $result);
    }

    public function testHandleWithMixedPriorities(): void
    {
        $issue1 = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            'description',
            ['tests'],
            'Task',
            [],
            'High'
        );

        $issue2 = new WorkItem(
            '1001',
            'TPW-36',
            'Another Task',
            'In Progress',
            'John Doe',
            'description',
            [],
            'Task',
            [],
            null
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('filter = "My Filter"')
            ->willReturn([$issue1, $issue2]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('My Filter'));
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return in_array('table.priority', $headers);
                }),
                $this->callback(function ($rows) {
                    return count($rows) === 2 &&
                        count($rows[0]) === 5 &&
                        $rows[0][2] === 'High' &&
                        $rows[1][2] === '';
                })
            );

        $result = $this->handler->handle($io, 'My Filter');

        $this->assertSame(0, $result);
    }
}
