<?php

namespace App\Tests\Handler;

use App\DTO\StateChange;
use App\DTO\WorkflowRecorder;
use App\DTO\WorkItem;
use App\Handler\ItemTransitionHandler;
use App\Response\WorkflowResponse;
use App\Service\Prompt\PromptInterface;
use App\Service\Prompt\SymfonyPromptService;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemTransitionHandlerTest extends CommandTestCase
{
    private ItemTransitionHandler $handler;
    private PromptInterface&MockObject $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prompt = $this->createMock(PromptInterface::class);
        $this->handler = new ItemTransitionHandler(
            $this->gitRepository,
            $this->issueTracker,
            $this->translationService,
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
    private function createHandlerWithRealPrompt(SymfonyStyle $io): ItemTransitionHandler
    {
        $prompt = new SymfonyPromptService($io);

        return new ItemTransitionHandler(
            $this->gitRepository,
            $this->issueTracker,
            $this->translationService,
            $prompt
        );
    }

    private function initializeRecorder(ItemTransitionHandler $handler): void
    {
        $reflection = new \ReflectionClass($handler);
        $property = $reflection->getProperty('recorder');
        $property->setAccessible(true);
        $property->setValue($handler, new WorkflowRecorder());
    }

    public function testHandleWithProvidedKey(): void
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

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
            new StateChange('21', 'Done', 'Done'),
        ];

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithKeyDetectionFromBranchAndConfirmation(): void
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

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "y\n");
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle(null);

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithKeyDetectionFromBranchAndRejection(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-36',
            title: 'Another feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-36')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-36')
            ->willReturn($transitions);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-36', '11');

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "n\n");
        fwrite($inputStream, "TPW-36\n");
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle(null);

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithKeyPromptWhenNotDetected(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-37',
            title: 'Yet another feature',
            status: 'To Do',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-37')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-37')
            ->willReturn($transitions);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-37', '11');

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "TPW-37\n");
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle(null);

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testHandleWithInvalidKeyFormat(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "INVALID-KEY\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle(null);

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithEmptyKeyPrompt(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle(null);

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Issue not found'));

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithIssueNotFoundApiException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "TPW-35".', 'HTTP 404: Not Found', 404));

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
        $this->assertNotEmpty($response->getErrors());
    }

    public function testHandleWhenTransitionChoiceDoesNotParseReturnsOne(): void
    {
        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'Feature',
            status: 'To Do',
            assignee: 'Jane',
            description: 'Desc',
            labels: [],
            issueType: 'story',
            components: [],
        );

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker->expects($this->once())->method('getIssue')->with('TPW-35')->willReturn($workItem);
        $this->issueTracker->expects($this->once())->method('listItemStateChanges')->with('TPW-35')->willReturn($transitions);
        $this->issueTracker->expects($this->never())->method('applyStateChange');

        $this->prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Invalid selection without ID');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithNoTransitions(): void
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

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn([]);

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $this->issueTracker->expects($this->never())
            ->method('applyStateChange');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithTransitionFetchError(): void
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

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Failed to fetch transitions'));

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $this->issueTracker->expects($this->never())
            ->method('applyStateChange');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithTransitionFetchErrorApiException(): void
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

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Could not fetch transitions for issue "TPW-35".', 'HTTP 500: Internal Server Error', 500));

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
        $this->assertNotEmpty($response->getErrors());
    }

    public function testHandleWithTransitionExecutionError(): void
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

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11')
            ->willThrowException(new \Exception('Failed to execute transition'));

        $this->issueTracker->expects($this->never())
            ->method('assign');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
    }

    public function testHandleWithTransitionExecutionErrorApiException(): void
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

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11')
            ->willThrowException(new \App\Exception\ApiException('Could not execute transition 11 for issue "TPW-35".', 'HTTP 400: Bad Request', 400));

        $this->prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Start Progress (ID: 11)');

        $response = $this->handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 1);
        $this->assertNotEmpty($response->getErrors());
    }

    public function testHandleWithVerboseOutput(): void
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

        $transitions = [
            new StateChange('11', 'Start Progress', 'In Progress'),
        ];

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->issueTracker->expects($this->once())
            ->method('listItemStateChanges')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->issueTracker->expects($this->once())
            ->method('applyStateChange')
            ->with('TPW-35', '11');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $handler = $this->createHandlerWithRealPrompt($io);
        $response = $handler->handle('TPW-35');

        $this->assertWorkflowExitCode($response, 0);
    }

    public function testResolveKeyWithProvidedKey(): void
    {
        $key = $this->callPrivateMethod($this->handler, 'resolveKey', ['tpw-35']);

        $this->assertSame('TPW-35', $key);
    }

    public function testResolveKeyWithLowercaseKey(): void
    {
        $key = $this->callPrivateMethod($this->handler, 'resolveKey', ['proj-123']);

        $this->assertSame('PROJ-123', $key);
    }

    public function testResolveKeyWithNullPromptedKey(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $this->initializeRecorder($handler);
        $key = $this->callPrivateMethod($handler, 'resolveKey', [null]);

        $this->assertNull($key);
    }

    public function testResolveKeyWithInvalidFormat(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "invalid-key-format\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealPrompt($io);
        $this->initializeRecorder($handler);
        $key = $this->callPrivateMethod($handler, 'resolveKey', [null]);

        $this->assertNull($key);
    }
}
