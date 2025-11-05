<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemStartHandler;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemStartHandlerTest extends CommandTestCase
{
    private ItemStartHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop');
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

    public function testHandleWithIssueNotFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Issue not found'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
        $this->assertStringContainsString('Could not find Jira issue with key "TPW-35".', $output->fetch());
    }

    public function testHandleWithVerboseOutput(): void
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
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io, 'TPW-35');

        $fetchedOutput = $output->fetch();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Fetching details for issue: TPW-35', $fetchedOutput);
        $this->assertStringContainsString('Generated branch name: feat/TPW-35-my-awesome-feature', $fetchedOutput);
        $this->assertStringContainsString('Branch \'feat/TPW-35-my-awesome-feature\' created from \'origin/develop\'.', $fetchedOutput);
    }

    public function testGetBranchPrefixFromIssueType(): void
    {
        $this->assertSame('fix', $this->callPrivateMethod($this->handler, 'getBranchPrefixFromIssueType', ['bug']));
        $this->assertSame('feat', $this->callPrivateMethod($this->handler, 'getBranchPrefixFromIssueType', ['story']));
        $this->assertSame('feat', $this->callPrivateMethod($this->handler, 'getBranchPrefixFromIssueType', ['epic']));
        $this->assertSame('chore', $this->callPrivateMethod($this->handler, 'getBranchPrefixFromIssueType', ['task']));
        $this->assertSame('chore', $this->callPrivateMethod($this->handler, 'getBranchPrefixFromIssueType', ['sub-task']));
        $this->assertSame('feat', $this->callPrivateMethod($this->handler, 'getBranchPrefixFromIssueType', ['unknown']));
    }

    public function testSlugify(): void
    {
        $this->assertSame('my-awesome-feature', $this->callPrivateMethod($this->handler, 'slugify', ['My awesome feature']));
        $this->assertSame('my-awesome-feature', $this->callPrivateMethod($this->handler, 'slugify', ['My Awesome Feature']));
        $this->assertSame('my-awesome-feature', $this->callPrivateMethod($this->handler, 'slugify', ['My-Awesome-Feature']));
        $this->assertSame('my-awesome-feature', $this->callPrivateMethod($this->handler, 'slugify', ['My_Awesome_Feature']));
        $this->assertSame('my-awesome-feature', $this->callPrivateMethod($this->handler, 'slugify', ['  My awesome feature  ']));
        $this->assertSame('my-awesome-feature', $this->callPrivateMethod($this->handler, 'slugify', ['My!@#$%^&*()Awesome Feature']));
        $this->assertSame('my-awesome-feature', $this->callPrivateMethod($this->handler, 'slugify', ['My   awesome   feature']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a- b -c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a -b- c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a - b - c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a_b_c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a_ b _c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a _b_ c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a _ b _ c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a-b_c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a_b-c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a-b- c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a -b-c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a - b-c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a- b- c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a- b - c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a -b - c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a - b -c']));
        $this->assertSame('a-b-c', $this->callPrivateMethod($this->handler, 'slugify', ['a - b - c']));
    }
}
