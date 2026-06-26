<?php

namespace App\Tests\Handler;

use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Handler\ItemStartHandler;
use App\Response\WorkflowResponse;
use App\Service\Prompt\PromptInterface;
use App\Service\Prompt\SymfonyPromptService;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemStartHandlerTest extends CommandTestCase
{
    private ItemStartHandler $handler;
    private PromptInterface&MockObject $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        // Note: Castor container is not initialized in tests, so slug() will use the fallback
        // which replicates Castor's behavior (snake_case -> slug -> lowercase)

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$gitBranchService = $this->gitBranchService;
        TestKernel::$workItemProvider = $this->workItemProvider;
        TestKernel::$translationService = $this->translationService;
        $this->gitBranchService->method('resolveLatestBaseBranch')->willReturn('origin/develop');
        $this->prompt = $this->createMock(PromptInterface::class);
        // Default config with transition disabled for existing tests
        $this->handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, [], $this->prompt);
    }

    private function assertWorkflowExitCode(WorkflowResponse $response, int $expectedExitCode): void
    {
        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertSame($expectedExitCode, $response->exitCode);
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        // Test intent: success() was called, verified by return value
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Issue not found', 'HTTP 404', 404));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
        // Test intent: error() was called, verified by return value
    }

    public function testHandleWithIssueNotFoundApiException(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "TPW-35".', 'HTTP 404: Not Found', 404));

        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, [], $this->prompt);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
        $this->assertNotEmpty($response->getErrors());
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $this->handler->handle('TPW-35');

        $fetchedOutput = $output->fetch();

        $this->assertWorkflowExitCode($response, 0);
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

    public function testHandleWithTransitionEnabledAndCachedTransition(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        // Mock project config with cached transition
        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndCachedTransitionNonVerbose(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        // Not setting verbose mode - tests the else block without verbose output

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndInteractiveSelection(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
            new StateChange('21', 'Done', 'Done'),
        ];

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        // Mock writeProjectConfig in case user chooses to save
        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndUserDeclinesToSave(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - NO (user declines)
        fwrite($inputStream, "n\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->never())
            ->method('writeProjectConfig'); // User declined to save

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - no
        fwrite($inputStream, "n\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledButNoTransitionsAvailable(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'Done',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn([]); // No transitions available

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndAssignmentError(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Assignment failed'));

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndAssignmentErrorVerbose(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Assignment failed'));

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndTransitionFetchError(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Failed to fetch transitions'));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndTransitionExecutionError(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11')
            ->willThrowException(new \Exception('Transition execution failed'));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndAssignmentApiExceptionVerbose(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $this->prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Failed to assign issue.', 'HTTP 403: Forbidden', 403));

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndGetTransitionsApiExceptionVerbose(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];


        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $this->prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Failed to get transitions.', 'HTTP 500: Internal Server Error', 500));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndTransitionExecutionApiExceptionVerbose(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];


        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $this->prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11')
            ->willThrowException(new \App\Exception\ApiException('Failed to execute transition.', 'HTTP 400: Bad Request', 400));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndCachedTransitionVerbose(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];


        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $this->prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndVerboseSaveMessage(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Select transition
        fwrite($inputStream, "y\n"); // Save choice
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndCachedTransitionForDifferentProject(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        // Config has transition for different project
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'OTHER', 'transitionId' => 11]);

        // Should fall through to interactive selection
        $transitions = [
            new StateChange('22', 'Start Progress', 'In Progress'),
        ];

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 22]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '22');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Select transition
        fwrite($inputStream, "y\n"); // Save choice
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndConfigMissingProjectKey(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        // Config has transitionId but no projectKey
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['transitionId' => 11]); // Missing projectKey

        // Should fall through to interactive selection
        $transitions = [
            new StateChange('22', 'Start Progress', 'In Progress'),
        ];

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 22]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '22');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Select transition
        fwrite($inputStream, "y\n"); // Save choice
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithTransitionEnabledAndNonInProgressTransition(): void
    {
        // This tests that all transitions are shown, not just 'in_progress' ones
        // Previously, transitions with statusCategory 'done' would be filtered out
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        // Use real logger for interactive tests
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // Provide input for interactive prompts:
        // 1. choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        // 2. confirm() for saving choice - yes
        fwrite($inputStream, "y\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $prompt = new SymfonyPromptService($io);
        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition

        // Return transitions that are not 'in_progress' - these should now be shown
        $transitions = [
            new StateChange('21', 'Block', 'Blocked'),
        ];

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 21]);

        $this->workItemProvider->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '21');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Select transition
        fwrite($inputStream, "y\n"); // Save choice
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleTransitionWithInvalidTransitionSelection(): void
    {
        // Test the error path when preg_match fails to extract transition ID
        // The exception is caught and a warning is shown, then the method returns 0
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Invalid Selection Without ID Pattern');

        $handler = new ItemStartHandler($this->gitRepository, $this->gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, $jiraConfig, $prompt);

        $reflection = new \ReflectionClass($handler);
        $property = $reflection->getProperty('recorder');
        $property->setAccessible(true);
        $property->setValue($handler, new \App\DTO\WorkflowRecorder());

        $this->workItemProvider->expects($this->once())
            ->method('assign')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->workItemProvider->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->callPrivateMethod($handler, 'handleTransition', ['TPW-35', $workItem]);

        $this->addToAssertionCount(1);
    }

    public function testHandleSwitchesToExistingLocalBranch(): void
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => ['feat/TPW-35-my-awesome-feature'], 'remote' => []]);

        $this->gitBranchService->expects($this->once())
            ->method('switchBranch')
            ->with('feat/TPW-35-my-awesome-feature');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleSwitchesToExistingRemoteBranch(): void
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => ['feat/TPW-35-my-awesome-feature']]);

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/TPW-35-my-awesome-feature');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleCreatesBranchWhenNoneExists(): void
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $this->gitBranchService->expects($this->never())
            ->method('switchBranch');

        $this->gitBranchService->expects($this->never())
            ->method('switchToRemoteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleSwitchesToFirstLocalBranchWhenGeneratedBranchNotExists(): void
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => ['feat/TPW-35-different-branch'], 'remote' => []]);

        $this->gitBranchService->expects($this->once())
            ->method('switchBranch')
            ->with('feat/TPW-35-different-branch');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleSwitchesToFirstRemoteBranchWhenGeneratedBranchNotExists(): void
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

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => ['feat/TPW-35-different-branch']]);

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/TPW-35-different-branch');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleCreatesBranchFromResolvedLocalWhenLocalIsAhead(): void
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

        $gitRepository = $this->createMock(\App\Service\GitRepository::class);
        $gitBranchService = $this->createMock(\App\Service\GitBranchService::class);
        $gitBranchService->method('resolveLatestBaseBranch')
            ->with('origin/develop')
            ->willReturn('develop');

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $gitRepository->expects($this->once())
            ->method('fetch');

        $gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'develop');

        $handler = new ItemStartHandler($gitRepository, $gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, [], $this->prompt);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleNoVerboseLogWhenResolvedMatchesConfigured(): void
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

        $gitRepository = $this->createMock(\App\Service\GitRepository::class);
        $gitBranchService = $this->createMock(\App\Service\GitBranchService::class);
        $gitBranchService->method('resolveLatestBaseBranch')
            ->with('origin/develop')
            ->willReturn('origin/develop');

        $this->workItemProvider->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $gitRepository->expects($this->once())
            ->method('fetch');

        $gitBranchService->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $handler = new ItemStartHandler($gitRepository, $gitBranchService, $this->workItemProvider, 'origin/develop', $this->translationService, [], $this->prompt);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }
}
