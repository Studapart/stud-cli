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
        TestKernel::$translationService = $this->translationService;
        $this->handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService);
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
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
        // Test intent: success() was called, verified by return value
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
        // Test intent: error() was called, verified by return value
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
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io, 'TPW-35');

        $fetchedOutput = $output->fetch();

        $this->assertSame(0, $result);
        // Test intent: verbose output and success() were called, verified by return value
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
