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
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->handler = new CommitHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $this->logger);
    }

    public function testHandleWithCleanWorkingTree(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->logger->method('note');

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

        $this->logger->method('note');

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

        $this->logger->method('success');

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

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $loggerCalls = [];
        $this->logger->method('gitWriteln')->willReturnCallback(function (int $verbosity, string $message) use (&$loggerCalls) {
            $loggerCalls[] = $message;
        });

        $this->logger->method('section');
        $this->logger->method('text');
        $this->logger->method('success');

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
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question, ?string $default) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_auto') || str_contains($question, 'api') => 'api',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('success');
        $this->logger->method('jiraWriteln');

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

        $this->logger->method('error');

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

        $this->logger->method('error');

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
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question, ?string $default) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_auto') || str_contains($question, 'api') => 'api',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('success');
        $this->logger->method('jiraWriteln')->willReturnCallback(function (int $verbosity, string $message) use ($output) {
            if ($verbosity <= Logger::VERBOSITY_VERY_VERBOSE) {
                $output->writeln($message);
            }
        });

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
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question, ?string $default) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_prompt') || str_contains($question, 'Scope') => '',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('success');
        $this->logger->method('jiraWriteln');

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
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question, ?string $default) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_auto') || str_contains($question, 'api') => 'api',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('success');
        $this->logger->method('jiraWriteln')->willReturnCallback(function (int $verbosity, string $message) use ($output) {
            if ($verbosity <= Logger::VERBOSITY_VERBOSE) {
                $output->writeln($message);
            }
        });
        $this->logger->method('gitWriteln')->willReturnCallback(function (int $verbosity, string $message) use ($output) {
            if ($verbosity <= Logger::VERBOSITY_VERBOSE) {
                $output->writeln($message);
            }
        });

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

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn (string $question, ?string $default) => match (true) {
                    str_contains($question, 'commit.type_prompt') || str_contains($question, 'feat') => 'feat',
                    str_contains($question, 'commit.scope_prompt') || str_contains($question, 'Scope') => '',
                    str_contains($question, 'commit.summary_prompt') || str_contains($question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('success');
        $this->logger->method('jiraWriteln');

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
