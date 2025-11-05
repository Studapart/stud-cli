<?php

namespace App\Tests\Projects;

use App\DTO\Project;
use App\Projects\ListHandler;
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

    public function testHandle(): void
    {
        $project = new Project(
            'PROJ',
            'My Project'
        );

        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([$project]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Fetching Jira Projects', $output->fetch());
    }
}
