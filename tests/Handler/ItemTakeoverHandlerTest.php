<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemStartHandler;
use App\Handler\ItemTakeoverHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemTakeoverHandlerTest extends CommandTestCase
{
    private ItemTakeoverHandler $handler;
    private ItemStartHandler $itemStartHandler;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->itemStartHandler = $this->createMock(ItemStartHandler::class);
        $this->handler = new ItemTakeoverHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->itemStartHandler,
            'origin/develop',
            $this->translationService,
            [],
            $this->logger
        );
    }

    /**
     * Creates a handler with a real Logger instance for interactive tests.
     */
    private function createHandlerWithRealLogger(SymfonyStyle $io): ItemTakeoverHandler
    {
        $realLogger = new Logger($io, []);

        return new ItemTakeoverHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->itemStartHandler,
            'origin/develop',
            $this->translationService,
            [],
            $realLogger
        );
    }

    public function testHandleWithDirtyWorkingDirectory(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn(" M file.php\n");

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes (only one branch, so no choice needed)
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(1, $result);
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willThrowException(new \RuntimeException('Issue not found'));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes (only one branch, so no choice needed)
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(1, $result);
    }

    public function testHandleWithAssignmentFailure(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123')
            ->willThrowException(new \RuntimeException('Assignment failed'));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->itemStartHandler->expects($this->once())
            ->method('handle')
            ->willReturn(0);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for starting fresh - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithNoBranchesAndStartFresh(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->itemStartHandler->expects($this->once())
            ->method('handle')
            ->willReturn(0);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for starting fresh - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithSingleRemoteBranch(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithSingleLocalBranch(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => ['feat/PROJ-123-title'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', null)
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes (only one branch, so no choice needed)
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleAlreadyOnBranch(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => ['feat/PROJ-123-title'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('feat/PROJ-123-title');

        $this->gitRepository->expects($this->never())
            ->method('switchBranch');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', null)
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithWrongBaseBranch(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => ['feat/PROJ-123-title'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', null)
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(false);

        // Create a mock IO that will return true for confirm() and handle other methods
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchBehindRemote(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 2,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $this->gitRepository->expects($this->once())
            ->method('pullWithRebase')
            ->with('origin', 'feat/PROJ-123-title');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithDivergedBranch(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 2,
                'ahead_remote' => 1,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('pullWithRebase');

        // Create a mock IO that will return true for confirm() and handle other methods
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithNoBranchesAndUserDeclinesStartFresh(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->itemStartHandler->expects($this->never())
            ->method('handle');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() - no
        fwrite($inputStream, "n\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithMultipleBranches(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn([
                'local' => ['feat/PROJ-123-branch1'],
                'remote' => ['feat/PROJ-123-branch2', 'fix/PROJ-123-branch3'],
            ]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-branch2');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-branch2', 'origin/develop', 'origin/feat/PROJ-123-branch2')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-branch2', 'origin/develop')
            ->willReturn(true);

        // Create a mock IO that will return a choice selection
        // The translation service mock returns: "item.takeover.branch_remote {\"branch\":\"feat\/PROJ-123-branch2\"}"
        // Note: combineBranches prioritizes remote branches, so the order is:
        // 1. feat/PROJ-123-branch2 (remote)
        // 2. fix/PROJ-123-branch3 (remote)
        // 3. feat/PROJ-123-branch1 (local)
        $mockIo = $this->createMock(SymfonyStyle::class);
        $mockIo->method('section');
        $mockIo->method('text')
            ->willReturnCallback(function ($message) {
                // Allow text() to be called multiple times
            });
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() for branch selection - select first option (index 0) which is "feat/PROJ-123-branch2"
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithMultipleBranchesUserCancels(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn([
                'local' => ['feat/PROJ-123-branch1'],
                'remote' => ['feat/PROJ-123-branch2'],
            ]);

        // extractBranchNameFromSelection returns null when no match is found
        // This simulates user canceling or invalid selection
        // Mock the logger's choice() method to return an invalid selection
        $this->logger->method('section');
        $this->logger->method('text');
        $this->logger->method('jiraWriteln');
        $this->logger->method('warning');
        // Return a selection that doesn't match any branch label
        $this->logger->method('choice')
            ->willReturn('Invalid Selection That Does Not Match');

        // When selection doesn't match, getCurrentBranchName is never called
        $this->gitRepository->expects($this->never())
            ->method('getCurrentBranchName');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $result = $this->handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithSingleRemoteBranchUserDeclines(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() - no
        fwrite($inputStream, "n\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchAheadOfRemote(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 3, // Branch is ahead of remote
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('pullWithRebase');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchBehindBase(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 2, // Branch is behind base
                'ahead_base' => 0,
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchSyncWithBase(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'PROJ-123',
            title: 'Test issue',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story'
        );

        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitRepository->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 0, // Branch is in sync with base
            ]);

        $this->gitRepository->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-title', 'origin/develop')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'PROJ-123');

        $this->assertSame(0, $result);
    }
}
