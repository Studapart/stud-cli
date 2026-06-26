<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemStartHandler;
use App\Handler\ItemTakeoverHandler;
use App\Response\WorkflowResponse;
use App\Service\Prompt\PromptInterface;
use App\Service\Prompt\SymfonyPromptService;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemTakeoverHandlerTest extends CommandTestCase
{
    private ItemTakeoverHandler $handler;
    private ItemStartHandler $itemStartHandler;
    private PromptInterface&MockObject $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prompt = $this->createMock(PromptInterface::class);
        $this->itemStartHandler = $this->createMock(ItemStartHandler::class);
        $this->handler = new ItemTakeoverHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $this->workItemProvider,
            $this->itemStartHandler,
            'origin/develop',
            $this->translationService,
            [],
            $this->prompt
        );
    }

    private function assertWorkflowExitCode(WorkflowResponse $response, int $expectedExitCode): void
    {
        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertSame($expectedExitCode, $response->exitCode);
    }

    /**
     * Creates a handler with a real prompt service for interactive tests.
     */
    private function createHandlerWithRealPrompt(SymfonyStyle $io): ItemTakeoverHandler
    {
        $prompt = new SymfonyPromptService($io);

        return new ItemTakeoverHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $this->workItemProvider,
            $this->itemStartHandler,
            'origin/develop',
            $this->translationService,
            [],
            $prompt
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willThrowException(new \App\Exception\ApiException('Issue not found', 'HTTP 404', 404));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for switching - yes (only one branch, so no choice needed)
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithIssueNotFoundApiException(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "PROJ-123".', 'HTTP 404: Not Found', 404));

        $handler = new ItemTakeoverHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $this->workItemProvider,
            $this->itemStartHandler,
            'origin/develop',
            $this->translationService,
            [],
            $this->prompt
        );

        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 1);
        $this->assertNotEmpty($response->getErrors());
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123')
            ->willThrowException(new \RuntimeException('Assignment failed'));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->itemStartHandler->expects($this->once())
            ->method('handle')
            ->willReturn(WorkflowResponse::fromExitCode(0));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for starting fresh - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->itemStartHandler->expects($this->once())
            ->method('handle')
            ->willReturn(WorkflowResponse::fromExitCode(0));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for starting fresh - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithAssignmentApiExceptionVerbose(): void
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123')
            ->willThrowException(new \App\Exception\ApiException('Failed to assign issue.', 'HTTP 403: Forbidden', 403));

        $this->prompt->method('confirm')
            ->willReturn(true); // User confirms to start fresh

        $handler = new ItemTakeoverHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $this->workItemProvider,
            $this->itemStartHandler,
            'origin/develop',
            $this->translationService,
            [],
            $this->prompt
        );

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->itemStartHandler->expects($this->once())
            ->method('handle')
            ->willReturn(WorkflowResponse::fromExitCode(0));

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for starting fresh - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => ['feat/PROJ-123-title'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', null)
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => ['feat/PROJ-123-title'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->never())
            ->method('switchBranch');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', null)
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => ['feat/PROJ-123-title'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', null)
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 2,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 2,
                'ahead_remote' => 1,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn([
                'local' => ['feat/PROJ-123-branch1'],
                'remote' => ['feat/PROJ-123-branch2', 'fix/PROJ-123-branch3'],
            ]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-branch2');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-branch2', 'origin/develop', 'origin/feat/PROJ-123-branch2')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithMultipleBranchesQuietSelectsFirstWithoutPrompting(): void
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn([
                'local' => ['feat/PROJ-123-branch1'],
                'remote' => ['feat/PROJ-123-branch2', 'fix/PROJ-123-branch3'],
            ]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-branch2');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-branch2', 'origin/develop', 'origin/feat/PROJ-123-branch2')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
            ->method('isBranchBasedOn')
            ->with('feat/PROJ-123-branch2', 'origin/develop')
            ->willReturn(true);

        $this->prompt->expects($this->never())
            ->method('choice');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('PROJ-123', true);

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn([
                'local' => ['feat/PROJ-123-branch1'],
                'remote' => ['feat/PROJ-123-branch2'],
            ]);

        // extractBranchNameFromSelection returns null when no match is found
        // This simulates user canceling or invalid selection
        // Return a selection that doesn't match any branch label
        $this->prompt->method('choice')
            ->willReturn('Invalid Selection That Does Not Match');

        // When selection doesn't match, getCurrentBranchName is never called
        $this->gitRepository->expects($this->never())
            ->method('getCurrentBranchName');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $response = $this->handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 3, // Branch is ahead of remote
                'behind_base' => 0,
                'ahead_base' => 5,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 2, // Branch is behind base
                'ahead_base' => 0,
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('PROJ-123')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('PROJ-123');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('PROJ-123')
            ->willReturn(['local' => [], 'remote' => ['feat/PROJ-123-title']]);

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/PROJ-123-title');

        $this->gitBranchService->expects($this->once())
            ->method('getBranchStatus')
            ->with('feat/PROJ-123-title', 'origin/develop', 'origin/feat/PROJ-123-title')
            ->willReturn([
                'behind_remote' => 0,
                'ahead_remote' => 0,
                'behind_base' => 0,
                'ahead_base' => 0, // Branch is in sync with base
            ]);

        $this->gitBranchService->expects($this->once())
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

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('PROJ-123');

        $this->assertWorkflowExitCode($response, 0);
    }
}
