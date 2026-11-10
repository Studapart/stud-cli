<?php

namespace App\Tests\Handler;

use App\DTO\StateChange;
use App\DTO\WorkflowRecorder;
use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Handler\ItemStartHandler;
use App\Handler\ItemTrackerGateway;
use App\Response\WorkflowResponse;
use App\Service\BranchNameGenerator;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\Prompt\NonInteractivePromptService;
use App\Service\Prompt\PromptInterface;
use App\Service\Prompt\SymfonyPromptService;
use App\Tests\CommandTestCase;
use App\Tests\Support\ItemStartHandlerTestKit;
use App\Tests\TestKernel;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemStartHandlerTest extends CommandTestCase
{
    private ItemStartHandler $handler;
    private PromptInterface&Stub $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createStub(GitRepository::class);
        $this->gitBranchService = $this->createStub(GitBranchService::class);
        $this->issueTracker = $this->createStub(IssueTrackerPort::class);
        $this->gitBranchService->method('resolveLatestBaseBranch')->willReturn('origin/develop');
        $this->prompt = $this->createStub(PromptInterface::class);
        $this->bindSubject();
    }

    private function assertWorkflowExitCode(WorkflowResponse $response, int $expectedExitCode): void
    {
        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertSame($expectedExitCode, $response->exitCode);
    }

    public function testHandle(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->issueTracker
            ->method('getIssue')
            ->willThrowException(new \App\Exception\ApiException('Issue not found', 'HTTP 404', 404));

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
        $this->assertMessageRef($response->getErrorMessage(), 'item.start.error_not_found', ['key' => 'TPW-35']);
    }

    public function testHandleWithIssueNotFoundApiException(): void
    {
        $this->issueTracker
            ->method('getIssue')
            ->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "TPW-35".', 'HTTP 404: Not Found', 404));

        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            []
        );

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
        $this->assertMessageRef($response->getErrorMessage(), 'item.start.error_not_found', ['key' => 'TPW-35']);
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

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testGetBranchPrefixFromIssueType(): void
    {
        $this->assertSame('fix', BranchNameGenerator::prefixForIssueType('bug'));
        $this->assertSame('feat', BranchNameGenerator::prefixForIssueType('story'));
        $this->assertSame('feat', BranchNameGenerator::prefixForIssueType('epic'));
        $this->assertSame('chore', BranchNameGenerator::prefixForIssueType('task'));
        $this->assertSame('chore', BranchNameGenerator::prefixForIssueType('sub-task'));
        $this->assertSame('feat', BranchNameGenerator::prefixForIssueType('unknown'));
    }

    public function testHandleWithTransitionEnabledAndCachedTransition(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        // Mock project config with cached transition
        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithTransitionEnabledAndCachedTransitionNonVerbose(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        // Not setting verbose mode - tests the else block without verbose output

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithTransitionEnabledAndInteractiveSelection(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition
        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
            new StateChange('21', 'Done', 'Done'),
        ];

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn($transitions);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        // Mock writeProjectConfig in case user chooses to save
        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithTransitionEnabledAndUserDeclinesToSave(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition
        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->never())
            ->method('writeProjectConfig'); // User declined to save

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

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
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn([]); // No transitions available
        $this->gitRepository
            ->method('fetch');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.no_transitions', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->issueTracker
            ->method('assign')
            ->willThrowException(new \Exception('Assignment failed'));

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.assign_error');
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->issueTracker
            ->method('assign')
            ->willThrowException(new \Exception('Assignment failed'));

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.assign_error');
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willThrowException(new \Exception('Failed to fetch transitions'));

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_error');
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->issueTracker
            ->method('applyStateChange')
            ->willThrowException(new \Exception('Transition execution failed'));

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_exec_error');
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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

        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->issueTracker
            ->method('assign')
            ->willThrowException(new \App\Exception\ApiException('Failed to assign issue.', 'HTTP 403: Forbidden', 403));

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.assign_error');
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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

        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willThrowException(new \App\Exception\ApiException('Failed to get transitions.', 'HTTP 500: Internal Server Error', 500));

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_error');
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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

        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->issueTracker
            ->method('applyStateChange')
            ->willThrowException(new \App\Exception\ApiException('Failed to execute transition.', 'HTTP 400: Bad Request', 400));

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_exec_error');
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
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

        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithTransitionEnabledAndVerboseSaveMessage(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([]);

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

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
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithTransitionEnabledAndCachedTransitionForDifferentProject(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        // Config has transition for different project
        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'OTHER', 'transitionId' => 11]);

        // Should fall through to interactive selection
        $transitions = [
            new StateChange('22', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 22]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '22');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

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
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithTransitionEnabledAndConfigMissingProjectKey(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        // Config has transitionId but no projectKey
        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['transitionId' => 11]); // Missing projectKey
        // Should fall through to interactive selection
        $transitions = [
            new StateChange('22', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 22]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '22');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

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
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleWithTransitionEnabledAndNonInProgressTransition(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

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
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition
        // Return transitions that are not 'in_progress' - these should now be shown
        $transitions = [
            new StateChange('21', 'Block', 'Blocked'),
        ];

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 21]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '21');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

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
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'TPW-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleTransitionWithInvalidTransitionSelection(): void
    {
        // Test the error path when preg_match fails to extract transition ID
        // The exception is caught and a warning is shown, then the method returns 0
        $prompt = $this->createStub(PromptInterface::class);
        $prompt
            ->method('choice')
            ->willReturn('Invalid Selection Without ID Pattern');

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('TPW');

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn([
                new StateChange('11', 'Start Progress', 'In Progress'),
            ]);

        $recorder = new WorkflowRecorder();
        ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt)
            ->transition($recorder, 'TPW-35', []);

        $response = $recorder->toResponse(0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_error');
    }

    public function testHandleSwitchesToExistingLocalBranch(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->gitBranchService->method('resolveLatestBaseBranch')->willReturn('origin/develop');
        $this->bindSubject();

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

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => ['feat/TPW-35-my-awesome-feature'], 'remote' => []]);

        $this->gitBranchService->expects($this->once())
            ->method('switchBranch')
            ->with('feat/TPW-35-my-awesome-feature');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success_switched', ['branch' => 'feat/TPW-35-my-awesome-feature']);
    }

    public function testHandleSwitchesToExistingRemoteBranch(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->gitBranchService->method('resolveLatestBaseBranch')->willReturn('origin/develop');
        $this->bindSubject();

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

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => ['feat/TPW-35-my-awesome-feature']]);

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/TPW-35-my-awesome-feature');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success_switched', ['branch' => 'feat/TPW-35-my-awesome-feature']);
    }

    public function testHandleCreatesBranchWhenNoneExists(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->gitBranchService->method('resolveLatestBaseBranch')->willReturn('origin/develop');
        $this->bindSubject();

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

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $this->gitBranchService->expects($this->never())
            ->method('switchBranch');

        $this->gitBranchService->expects($this->never())
            ->method('switchToRemoteBranch');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleSwitchesToFirstLocalBranchWhenGeneratedBranchNotExists(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->gitBranchService->method('resolveLatestBaseBranch')->willReturn('origin/develop');
        $this->bindSubject();

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

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => ['feat/TPW-35-different-branch'], 'remote' => []]);

        $this->gitBranchService->expects($this->once())
            ->method('switchBranch')
            ->with('feat/TPW-35-different-branch');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success_switched', ['branch' => 'feat/TPW-35-different-branch']);
    }

    public function testHandleSwitchesToFirstRemoteBranchWhenGeneratedBranchNotExists(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->gitBranchService->method('resolveLatestBaseBranch')->willReturn('origin/develop');
        $this->bindSubject();

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

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => ['feat/TPW-35-different-branch']]);

        $this->gitBranchService->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/TPW-35-different-branch');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success_switched', ['branch' => 'feat/TPW-35-different-branch']);
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

        $gitRepository = $this->createMock(GitRepository::class);
        $gitBranchService = $this->createStub(GitBranchService::class);
        $gitBranchService->method('resolveLatestBaseBranch')
            ->willReturn('develop');

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'develop');

        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($gitRepository, $gitBranchService, 'origin/develop'),
            []
        );

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'develop']);
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

        $gitRepository = $this->createMock(GitRepository::class);
        $gitBranchService = $this->createStub(GitBranchService::class);
        $gitBranchService->method('resolveLatestBaseBranch')
            ->willReturn('origin/develop');

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($gitRepository, $gitBranchService, 'origin/develop'),
            []
        );

        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/TPW-35-my-awesome-feature', 'base' => 'origin/develop']);
    }

    public function testHandleUsesLinearTypeLabelPrefixForBug(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCI-123',
            title: 'Fix login',
            status: 'Todo',
            assignee: 'Ada',
            description: '',
            labels: ['Bug', 'DX'],
            issueType: '',
            components: [],
        );

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([
                'issueTrackerProvider' => IssueTrackerProvider::Linear->value,
                'linearTypeLabelGroupId' => 'group-1',
                'linearTypeBranchPrefixes' => ['Bug' => 'fix', 'Story' => 'feat'],
                'projectKey' => 'SCI',
            ]);

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);
        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('fix/SCI-123-fix-login', 'origin/develop');

        $response = $this->handler->handle('SCI-123');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'fix/SCI-123-fix-login', 'base' => 'origin/develop']);
    }

    public function testHandleUsesLinearTeamKeyFromIssueWhenProjectKeyMissing(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCI-456',
            title: 'Fix login',
            status: 'Todo',
            assignee: 'Ada',
            description: '',
            labels: ['Bug'],
            issueType: '',
            components: [],
        );

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([
                'issueTrackerProvider' => IssueTrackerProvider::Linear->value,
                'linearTypeLabelGroupId' => 'group-1',
                'linearTypeBranchPrefixes' => ['Bug' => 'fix'],
            ]);
        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('SCI');

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);
        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('fix/SCI-456-fix-login', 'origin/develop');

        $response = $this->handler->handle('SCI-456');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'fix/SCI-456-fix-login', 'base' => 'origin/develop']);
    }

    public function testHandleWarnsWhenLinearIssueHasNoTypeLabel(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCI-789',
            title: 'Improve DX',
            status: 'Todo',
            assignee: 'Ada',
            description: '',
            labels: ['DX'],
            issueType: '',
            components: [],
        );

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([
                'issueTrackerProvider' => IssueTrackerProvider::Linear->value,
                'linearTypeLabelGroupId' => 'group-1',
                'linearTypeBranchPrefixes' => ['Bug' => 'fix', 'Story' => 'feat'],
                'projectKey' => 'SCI',
            ]);

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);
        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/SCI-789-improve-dx', 'origin/develop');

        $response = $this->handler->handle('SCI-789');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/SCI-789-improve-dx', 'base' => 'origin/develop']);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.linear_no_type_label');
    }

    public function testHandleLinearUsesCachedStartStateAndAssigns(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCI-35',
            title: 'Linear feature',
            status: 'Todo',
            assignee: 'Unassigned',
            description: '',
            labels: ['Story'],
            issueType: 'Story',
            components: [],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('assign')
            ->with('SCI-35');

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([
                'issueTrackerProvider' => IssueTrackerProvider::Linear->value,
                'linearStartStateId' => 'state-in-progress-uuid',
                'linearTypeLabelGroupId' => 'group-1',
                'linearTypeBranchPrefixes' => ['Story' => 'feat'],
            ]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('SCI-35', 'state-in-progress-uuid');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('SCI-35');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'SCI-35']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/SCI-35-linear-feature', 'base' => 'origin/develop']);
    }

    public function testHandleAutoUsesLinearStartStateWhenIssueMatchesLinearTeamKey(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCIL-196',
            title: 'Harden label sync',
            status: 'Todo',
            assignee: 'Unassigned',
            description: '',
            labels: ['Task'],
            issueType: 'Task',
            components: [],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
                'transitionId' => 31,
                'linearTeamKey' => 'SCIL',
                'linearStartStateId' => 'state-in-progress-uuid',
            ]);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('SCIL');

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('SCIL-196', 'state-in-progress-uuid');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('SCIL-196');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'SCIL-196']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/SCIL-196-harden-label-sync', 'base' => 'origin/develop']);
    }

    public function testHandleAutoKeepsJiraTransitionWhenIssueMatchesJiraProjectKey(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCI-42',
            title: 'Jira under auto',
            status: 'Todo',
            assignee: 'Unassigned',
            description: '',
            labels: [],
            issueType: 'Story',
            components: [],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn([
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
                'transitionId' => 31,
                'linearTeamKey' => 'SCIL',
                'linearStartStateId' => 'state-in-progress-uuid',
            ]);

        $this->gitRepository
            ->method('getProjectKeyFromIssueKey')
            ->willReturn('SCI');

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('SCI-42', '31');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('SCI-42');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'SCI-42']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/SCI-42-jira-under-auto', 'base' => 'origin/develop']);
    }

    public function testUsesLinearStartWorkflowForPinnedJiraAndEmptyLinearTeam(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitRepository->expects($this->never())->method('getProjectKeyFromIssueKey');

        $workflows = ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt);

        $this->assertFalse($workflows->usesLinear('SCI-1', ['issueTrackerProvider' => IssueTrackerProvider::Jira->value]));
        $this->assertFalse($workflows->usesLinear('SCIL-1', [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'linearTeamKey' => '   ',
        ]));
        $this->assertFalse($workflows->issueKeyMatchesLinearTeam('SCIL-1', ['linearTeamKey' => '']));
    }

    public function testHandleLinearAgentModeSkipsTransitionWithoutCachedState(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCI-36',
            title: 'Linear agent start',
            status: 'Todo',
            assignee: 'Unassigned',
            description: '',
            labels: [],
            issueType: 'Story',
            components: [],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, new NonInteractivePromptService()),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['issueTrackerProvider' => IssueTrackerProvider::Linear->value]);

        $this->issueTracker->expects($this->never())->method('applyStateChange');
        $this->issueTracker->expects($this->never())->method('listItemStateChanges');

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('SCI-36');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/SCI-36-linear-agent-start', 'base' => 'origin/develop']);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.no_cached_linear_state_agent', ['key' => 'SCI-36']);
    }

    public function testHandleLinearPromptsAndSavesStartState(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->bindSubject();

        $workItem = new WorkItem(
            id: 'issue-1',
            key: 'SCI-37',
            title: 'Prompted linear start',
            status: 'Todo',
            assignee: 'Unassigned',
            description: '',
            labels: [],
            issueType: 'Story',
            components: [],
        );

        $jiraConfig = ['JIRA_TRANSITION_ENABLED' => true];
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\ny\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $prompt = new SymfonyPromptService(new SymfonyStyle($input, $output));
        $handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            $jiraConfig
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('readProjectConfig')
            ->willReturn(['issueTrackerProvider' => IssueTrackerProvider::Linear->value]);

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn([
                new StateChange(id: 'state-started-uuid', name: 'In Progress', type: 'started'),
            ]);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('SCI-37', 'state-started-uuid');

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['linearStartStateId' => 'state-started-uuid']);

        $this->gitBranchService
            ->method('findBranchesByIssueKey')
            ->willReturn(['local' => [], 'remote' => []]);

        $response = $handler->handle('SCI-37');

        $this->assertWorkflowExitCode($response, 0);
        $this->assertWorkflowEntry($response, 'item.start.transition_success', ['key' => 'SCI-37']);
        $this->assertWorkflowEntry($response, 'item.start.success', ['branch' => 'feat/SCI-37-prompted-linear-start', 'base' => 'origin/develop']);
    }

    public function testPromptForLinearStartStateIdReturnsNullWhenSelectionInvalid(): void
    {
        $this->prompt
            ->method('choice')
            ->willReturn('Invalid option without ID');

        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn([
                new StateChange(id: 'state-1', name: 'Todo', type: 'unstarted'),
            ]);

        $recorder = new WorkflowRecorder();
        $stateId = ItemStartHandlerTestKit::linear($this->gitRepository, $this->issueTracker, $this->prompt)
            ->promptForStartStateId($recorder, 'SCI-38');

        $this->assertNull($stateId);
        $response = $recorder->toResponse(0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_error');
    }

    public function testPromptForLinearStartStateIdReturnsNullWhenNoStates(): void
    {
        $this->issueTracker
            ->method('listItemStateChanges')
            ->willReturn([]);

        $recorder = new WorkflowRecorder();
        $stateId = ItemStartHandlerTestKit::linear($this->gitRepository, $this->issueTracker, $this->prompt)
            ->promptForStartStateId($recorder, 'SCI-39');

        $this->assertNull($stateId);
        $response = $recorder->toResponse(0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.no_linear_states', ['key' => 'SCI-39']);
    }

    public function testPromptForLinearStartStateIdReturnsNullOnApiException(): void
    {
        $this->issueTracker
            ->method('listItemStateChanges')
            ->willThrowException(new \App\Exception\ApiException('Failed', 'HTTP 500', 500));

        $recorder = new WorkflowRecorder();
        $stateId = ItemStartHandlerTestKit::linear($this->gitRepository, $this->issueTracker, $this->prompt)
            ->promptForStartStateId($recorder, 'SCI-40');

        $this->assertNull($stateId);
        $response = $recorder->toResponse(0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_error');
    }

    public function testExecuteStateChangeWithLoggingHandlesGenericException(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('SCI-41', 'state-1')
            ->willThrowException(new \RuntimeException('boom'));

        $recorder = new WorkflowRecorder();
        (new ItemTrackerGateway($this->issueTracker))->applyStateChange($recorder, 'SCI-41', 'state-1');

        $response = $recorder->toResponse(0);
        $this->assertMessageRef($response->getWarnings()[0]->message, 'item.start.transition_exec_error');
    }

    private function bindSubject(): void
    {

        // Note: Castor container is not initialized in tests, so slug() will use the fallback
        // which replicates Castor's behavior (snake_case -> slug -> lowercase)

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$gitBranchService = $this->gitBranchService;
        TestKernel::$issueTracker = $this->issueTracker;
        TestKernel::$translationService = $this->translationService;
        // Default config with transition disabled for existing tests
        $this->handler = ItemStartHandlerTestKit::create(
            ItemStartHandlerTestKit::workflows($this->gitRepository, $this->issueTracker, $this->prompt),
            new \App\Handler\ItemStartBranchPlanner(new \App\Handler\BranchActionResolver()),
            new \App\Handler\ItemBranchWorkspace($this->gitRepository, $this->gitBranchService, 'origin/develop'),
            []
        );
    }
}
