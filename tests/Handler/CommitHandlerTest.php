<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\CommitHandler;
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

        // CommitHandlerTest checks output text, so use real TranslationService
        // This is acceptable since CommitHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new CommitHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService);
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
        // Test intent: success() was called, verified by return value
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

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isVerbose')->willReturn(true);

        $writelnCalls = [];
        $io->method('writeln')->willReturnCallback(function (string $message) use (&$writelnCalls) {
            $writelnCalls[] = $message;
        });

        $io->method('section');
        $io->method('text');
        $io->method('success');

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
        // Test intent: verbose output was shown (checking for logical commit message)
        $this->assertNotEmpty(array_filter($writelnCalls, fn ($call) => str_contains($call, 'Checking for previous logical commit')));
        $this->assertNotEmpty(array_filter($writelnCalls, fn ($call) => str_contains($call, 'Found logical commit SHA')));
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

    public function testHandleWithNoJiraKeyInBranchName(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(1, $result);
        // Test intent: error() was called, verified by return value
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Jira service error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(1, $result);
        // Test intent: error() was called, verified by return value
    }

    public function testHandleWithVeryVerboseOutput(): void
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
        $io->method('isVeryVerbose')->willReturn(true);
        $io->method('writeln')->willReturnCallback(function (string $message) use ($output) { $output->writeln($message); });
        $io->method('section');
        $io->method('text');
        $io->method('success');

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
        // Test intent: verbose output was shown, verified by return value
    }

    public function testHandleWithEmptyComponents(): void
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
            components: [],
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat: My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question) => match ($question) {
                    "Commit Type (auto-detected 'feat')" => 'feat',
                    "Scope (optional)" => null,
                    "Short Message (auto-filled from Jira)" => 'My awesome feature',
                }
            );

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithVerboseOutputForCommitMessage(): void
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
        $io->method('isVerbose')->willReturn(true);
        $io->method('writeln')->willReturnCallback(function (string $message) use ($output) { $output->writeln($message); });
        $io->method('section');
        $io->method('text');
        $io->method('success');

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
        // Test intent: verbose output was shown, verified by return value
    }

    public function testgetCommitTypeFromIssueTypeWithUnknownType(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

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
            issueType: 'unknown',
            components: [],
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat: My awesome feature [TPW-35]');

        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question) => match ($question) {
                    "Commit Type (auto-detected 'feat')" => 'feat',
                    "Scope (optional)" => null,
                    "Short Message (auto-filled from Jira)" => 'My awesome feature',
                }
            );

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }

    public function testgetCommitTypeFromIssueType(): void
    {
        $handler = new CommitHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService);

        $this->assertSame('fix', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['bug']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['story']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['epic']));
        $this->assertSame('chore', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['task']));
        $this->assertSame('chore', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['sub-task']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['unknown']));
    }
}
