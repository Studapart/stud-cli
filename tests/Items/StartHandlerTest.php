<?php

namespace App\Tests\Items;

use App\DTO\WorkItem;
use App\Items\StartHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class StartHandlerTest extends CommandTestCase
{
    private StartHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new StartHandler($this->gitRepository, $this->jiraService, 'origin/develop');
    }

    public function testHandle(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetchOrigin');

        $this->gitRepository->expects($this->once())
            ->method('switch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Branch \'feat/TPW-35-my-awesome-feature\' created from \'origin/develop\'.', $output->fetch());
    }
}
