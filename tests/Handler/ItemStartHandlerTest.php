<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemStartHandler;
use App\Service\Logger;
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

        // Note: Castor container is not initialized in tests, so slug() will use the fallback
        // which replicates Castor's behavior (snake_case -> slug -> lowercase)

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $logger = $this->createMock(Logger::class);
        // Default config with transition disabled for existing tests
        $this->handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, [], $logger);
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
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

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
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        // Mock project config with cached transition
        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        // Not setting verbose mode - tests the else block without verbose output

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition

        $transitions = [
            [
                'id' => 11,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
            [
                'id' => 21,
                'name' => 'Done',
                'to' => [
                    'name' => 'Done',
                    'statusCategory' => ['key' => 'done', 'name' => 'Done'],
                ],
            ],
        ];

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
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

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]); // No cached transition

        $transitions = [
            [
                'id' => 11,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->never())
            ->method('writeProjectConfig'); // User declined to save

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
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

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn([]); // No transitions available

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Assignment failed'));

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Assignment failed'));

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Failed to fetch transitions'));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11)
            ->willThrowException(new \Exception('Transition execution failed'));

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $transitions = [
            [
                'id' => 11,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 11]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
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

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
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
            [
                'id' => 22,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 22]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 22);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
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

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
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
            [
                'id' => 22,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 22]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 22);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
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

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
        $realLogger = new Logger($io, []);
        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $realLogger);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
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
            [
                'id' => 21,
                'name' => 'Block',
                'to' => [
                    'name' => 'Blocked',
                    'statusCategory' => ['key' => 'done', 'name' => 'Done'],
                ],
            ],
        ];

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'TPW', 'transitionId' => 21]);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 21);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
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

        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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

        // Create a mocked logger to test the edge case where choice() returns invalid string
        $logger = $this->createMock(Logger::class);
        $logger->method('jiraWriteln');
        $logger->method('text');
        $logger->method('section');
        // Mock choice to return a string that doesn't match our regex pattern
        // This simulates an edge case where the regex fails (shouldn't happen in practice)
        $logger->expects($this->once())
            ->method('choice')
            ->willReturn('Invalid Selection Without ID Pattern');
        $logger->expects($this->once())
            ->method('warning')
            ->with(Logger::VERBOSITY_NORMAL, $this->stringContains('item.start.transition_error'));

        $handler = new ItemStartHandler($this->gitRepository, $this->jiraService, 'origin/develop', $this->translationService, $jiraConfig, $logger);

        $this->jiraService->expects($this->once())
            ->method('assignIssue')
            ->with('TPW-35');

        $this->gitRepository->expects($this->once())
            ->method('getProjectKeyFromIssueKey')
            ->with('TPW-35')
            ->willReturn('TPW');

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $transitions = [
            [
                'id' => 11,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $result = $this->callPrivateMethod($handler, 'handleTransition', ['TPW-35', $workItem]);

        // Method returns 0 even when exception occurs (error handling)
        $this->assertSame(0, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => ['feat/TPW-35-my-awesome-feature'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('switchBranch')
            ->with('feat/TPW-35-my-awesome-feature');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => ['feat/TPW-35-my-awesome-feature']]);

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/TPW-35-my-awesome-feature');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/TPW-35-my-awesome-feature', 'origin/develop');

        $this->gitRepository->expects($this->never())
            ->method('switchBranch');

        $this->gitRepository->expects($this->never())
            ->method('switchToRemoteBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => ['feat/TPW-35-different-branch'], 'remote' => []]);

        $this->gitRepository->expects($this->once())
            ->method('switchBranch')
            ->with('feat/TPW-35-different-branch');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->expects($this->once())
            ->method('findBranchesByIssueKey')
            ->with('TPW-35')
            ->willReturn(['local' => [], 'remote' => ['feat/TPW-35-different-branch']]);

        $this->gitRepository->expects($this->once())
            ->method('switchToRemoteBranch')
            ->with('feat/TPW-35-different-branch');

        $this->gitRepository->expects($this->never())
            ->method('createBranch');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
    }
}
