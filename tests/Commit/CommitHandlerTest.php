<?php

namespace App\Tests\Commit;

use App\Commit\CommitHandler;
use App\DTO\WorkItem;
use App\Git\GitRepository;
use App\Jira\JiraService;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class CommitHandlerTest extends CommandTestCase
{
    private CommitHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        $this->handler = new CommitHandler($this->gitRepository, $this->jiraService, 'origin/develop');
    }

    public function testHandleWithMessage(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('my message');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, 'my message');

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Commit created successfully!', $output->fetch());
    }

    public function testHandleWithAutoFixup(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('findLatestLogicalSha')
            ->with('origin/develop')
            ->willReturn('abcdef');

        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commitFixup')
            ->with('abcdef');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
        $this->assertStringContainsString('✅ Changes saved as a fixup for commit abcdef.', $output->fetch());
    }

    public function testHandleWithInteractivePrompter(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');

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
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question) => match ($question) {
                    "Commit Type (auto-detected 'feat')" => 'feat',
                    "Scope (auto-detected 'api')" => 'api',
                    "Short Message (auto-filled from Jira)" => 'My awesome feature',
                }
            );

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }
}
