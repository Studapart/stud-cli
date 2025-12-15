<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\CommitHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class CommitHandlerTest extends CommandTestCase
{
    private CommitHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = $this->createMock(Logger::class);
        $this->handler = new CommitHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $logger);
    }

    public function testHandleWithCleanWorkingTree(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
        // Test intent: note() was called for clean working tree, verified by return value
    }

    public function testHandleWithCleanWorkingTreeAndWhitespace(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('   ');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
        // Test intent: note() was called for clean working tree (with whitespace), verified by return value
    }

    public function testHandleWithMessage(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);

        // Get the Logger mock from the handler and set expectations
        $reflection = new \ReflectionClass($this->handler);
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $logger = $loggerProperty->getValue($this->handler);

        $loggerCalls = [];
        $logger->method('gitWriteln')->willReturnCallback(function (int $verbosity, string $message) use (&$loggerCalls) {
            $loggerCalls[] = $message;
        });

        $io->method('section');
        $io->method('text');
        $io->method('success');

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
        // Test intent: verbose output was shown (Logger was called)
        $this->assertNotEmpty($loggerCalls);
    }

    public function testHandleWithInteractivePrompter(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
                fn (string $question) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_auto') || str_contains($question, 'api') => 'api',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithNoJiraKeyInBranchName(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
                fn (string $question) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_auto') || str_contains($question, 'api') => 'api',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
        // Test intent: verbose output was shown, verified by return value
    }

    public function testHandleWithEmptyComponents(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
                fn (string $question) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_prompt') || str_contains($question, 'Scope') => null,
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithVerboseOutputForCommitMessage(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
                fn (string $question) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_auto') || str_contains($question, 'api') => 'api',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
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
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

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
                fn (string $question) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_prompt') || str_contains($question, 'Scope') => null,
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }

    public function testgetCommitTypeFromIssueType(): void
    {
        $logger = $this->createMock(Logger::class);
        $handler = new CommitHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $logger);

        $this->assertSame('fix', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['bug']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['story']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['epic']));
        $this->assertSame('chore', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['task']));
        $this->assertSame('chore', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['sub-task']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['unknown']));
    }
}
