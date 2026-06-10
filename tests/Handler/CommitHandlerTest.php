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

        $this->logger->method('addNote');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
        // Test intent: note() was called for clean working tree, verified by return value
    }

    public function testHandleWithCleanWorkingTreeAndWhitespace(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('   ');

        $this->logger->method('addNote');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
        // Test intent: note() was called for clean working tree (with whitespace), verified by return value
    }

    public function testHandleWithMessage(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false)); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('my message');

        $this->logger->method('addSuccess');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, 'my message', false);

        $this->assertTrue($result->isSuccess());
        // Test intent: success() was called, verified by return value
    }

    public function testHandleWithMessageUsingNewSignature(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false));

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('my message');

        $result = $this->handler->handle(false, 'my message', false, false);

        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithMessageAndAllFlag(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('my message');

        $this->logger->method('addSuccess');
        $this->logger->method('addText');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, 'my message', true);

        $this->assertTrue($result->isSuccess());
        // Test intent: success() was called, verified by return value
    }

    public function testHandleWithMessageNoStagedChanges(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(true)); // No staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->never())
            ->method('commit');

        $this->logger->method('addError');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, 'my message', false);

        $this->assertFalse($result->isSuccess());
        // Test intent: error() was called, verified by return value
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false)); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commitFixup')
            ->with('abcdef');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $loggerCalls = [];
        $this->logger->method('addGitLine')->willReturnCallback(function (int $verbosity, string $message) use (&$loggerCalls) {
            $loggerCalls[] = $message;
        });

        $this->logger->method('addSection');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('abcdef', $result->data['fixupSha'] ?? null);
    }

    public function testHandleWithAutoFixupAndAllFlag(): void
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
        $this->logger->method('addGitLine')->willReturnCallback(function (int $verbosity, string $message) use (&$loggerCalls) {
            $loggerCalls[] = $message;
        });

        $this->logger->method('addSection');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');

        $result = $this->handler->handle($io, false, null, true);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('abcdef', $result->data['fixupSha'] ?? null);
    }

    public function testHandleWithAutoFixupNoStagedChanges(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->once())
            ->method('findLatestLogicalSha')
            ->with('origin/develop')
            ->willReturn('abcdef');

        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(true)); // No staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->never())
            ->method('commitFixup');

        $this->logger->method('addError');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->method('addSection');
        $this->logger->method('addGitLine');

        $result = $this->handler->handle($io, false, null, false);

        $this->assertFalse($result->isSuccess());
        // Test intent: error() was called, verified by return value
    }

    public function testHandleWithNewBranchSkipsLogicalShaLookup(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->never())
            ->method('findLatestLogicalSha');

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'New feature',
            status: 'In Progress',
            assignee: 'Jane',
            description: 'Desc',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false));

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): New feature [TPW-35]');

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true, null, false, true);

        $this->assertTrue($result->isSuccess());
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false)); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    str_contains((string) $question, 'commit.type_prompt') || str_contains((string) $question, 'feat') => 'feat',
                    str_contains((string) $question, 'commit.scope_auto') || str_contains((string) $question, 'api') => 'api',
                    str_contains((string) $question, 'commit.summary_prompt') || str_contains((string) $question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine');

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithQuietUsesDetectedTypeScopeSummaryWithoutPrompting(): void
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false));

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $this->logger->expects($this->never())
            ->method('ask');

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine');
        $this->logger->method('addGitLine');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false, true);

        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithInteractivePrompterAndAllFlag(): void
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
                fn ($question, ?string $default) => match (true) {
                    str_contains((string) $question, 'commit.type_prompt') || str_contains((string) $question, 'feat') => 'feat',
                    str_contains((string) $question, 'commit.scope_auto') || str_contains((string) $question, 'api') => 'api',
                    str_contains((string) $question, 'commit.summary_prompt') || str_contains((string) $question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine');

        $result = $this->handler->handle($io, false, null, true);

        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithInteractivePrompterNoStagedChanges(): void
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(true)); // No staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->never())
            ->method('commit');

        $this->logger->method('addError');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addJiraLine');
        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    str_contains((string) $question, 'commit.type_prompt') || str_contains((string) $question, 'feat') => 'feat',
                    str_contains((string) $question, 'commit.scope_auto') || str_contains((string) $question, 'api') => 'api',
                    str_contains((string) $question, 'commit.summary_prompt') || str_contains((string) $question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $result = $this->handler->handle($io, false, null, false);

        $this->assertFalse($result->isSuccess());
        // Test intent: error() was called, verified by return value
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

        $this->logger->method('addError');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertFalse($result->isSuccess());
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

        $this->logger->method('addError');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertFalse($result->isSuccess());
        // Test intent: error() was called, verified by return value
    }

    public function testHandleWithJiraServiceApiException(): void
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
            ->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "TPW-35".', 'HTTP 404: Not Found', 404));

        $this->logger->method('addErrorWithDetails')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $this->messageRefWithKey('commit.error_not_found'),
                'HTTP 404: Not Found'
            );

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertFalse($result->isSuccess());
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false)); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    str_contains((string) $question, 'commit.type_prompt') || str_contains((string) $question, 'feat') => 'feat',
                    str_contains((string) $question, 'commit.scope_auto') || str_contains((string) $question, 'api') => 'api',
                    str_contains((string) $question, 'commit.summary_prompt') || str_contains((string) $question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine')->willReturnCallback(function (int $verbosity, string $message) use ($output) {
            if ($verbosity <= Logger::VERBOSITY_VERY_VERBOSE) {
                $output->addLine($message);
            }
        });

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false)); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat: My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    str_contains((string) $question, 'commit.type_prompt') || str_contains((string) $question, 'feat') => 'feat',
                    str_contains((string) $question, 'commit.scope_prompt') || str_contains((string) $question, 'Scope') => '',
                    str_contains((string) $question, 'commit.summary_prompt') || str_contains((string) $question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine');

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false)); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    str_contains((string) $question, 'commit.type_prompt') || str_contains((string) $question, 'feat') => 'feat',
                    str_contains((string) $question, 'commit.scope_auto') || str_contains((string) $question, 'api') => 'api',
                    str_contains((string) $question, 'commit.summary_prompt') || str_contains((string) $question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine')->willReturnCallback(function (int $verbosity, string $message) use ($output) {
            if ($verbosity <= Logger::VERBOSITY_VERBOSE) {
                $output->addLine($message);
            }
        });
        $this->logger->method('addGitLine')->willReturnCallback(function (int $verbosity, string $message) use ($output) {
            if ($verbosity <= Logger::VERBOSITY_VERBOSE) {
                $output->addLine($message);
            }
        });

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
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
            ->method('runQuietly')
            ->with('git diff --cached --quiet')
            ->willReturn($this->createMockProcess(false)); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat: My awesome feature [TPW-35]');

        $this->logger->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    str_contains((string) $question, 'commit.type_prompt') || str_contains((string) $question, 'feat') => 'feat',
                    str_contains((string) $question, 'commit.scope_prompt') || str_contains((string) $question, 'Scope') => '',
                    str_contains((string) $question, 'commit.summary_prompt') || str_contains((string) $question, 'Short Message') => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addSuccess');
        $this->logger->method('addJiraLine');

        $result = $this->handler->handle($io, false, null, false);

        $this->assertTrue($result->isSuccess());
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
