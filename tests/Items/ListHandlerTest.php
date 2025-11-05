<?php

namespace App\Tests\Items;

use App\DTO\WorkItem;
use App\Items\ListHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListHandlerTest extends CommandTestCase
{
    private ListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new ListHandler($this->jiraService);
    }

    public function testHandleDefault(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND status in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io, false, null);
    }

    public function testHandleAll(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('status in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io, true, null);
    }

    public function testHandleProject(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND status in (\'To Do\', \'In Progress\') AND project = MYPROJ ORDER BY updated DESC')
            ->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io, false, 'MYPROJ');
    }

    public function testHandleAllAndProject(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('status in (\'To Do\', \'In Progress\') AND project = MYPROJ ORDER BY updated DESC')
            ->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io, true, 'MYPROJ');
    }
}
