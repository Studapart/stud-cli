<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemTransitionHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemTransitionHandlerTest extends CommandTestCase
{
    private ItemTransitionHandler $handler;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->handler = new ItemTransitionHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->translationService,
            $this->logger
        );
    }

    /**
     * Creates a handler with a real Logger instance for interactive tests.
     */
    private function createHandlerWithRealLogger(SymfonyStyle $io): ItemTransitionHandler
    {
        $realLogger = new Logger($io, []);

        return new ItemTransitionHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->translationService,
            $realLogger
        );
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
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
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
            [
                'id' => 11,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for detected key - yes
        fwrite($inputStream, "y\n");
        // choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, null);

        $this->assertSame(0, $result);
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
            [
                'id' => 11,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn('TPW-35');

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-36')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-36')
            ->willReturn($transitions);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-36', 11);

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // confirm() for detected key - no
        fwrite($inputStream, "n\n");
        // ask() for key - TPW-36
        fwrite($inputStream, "TPW-36\n");
        // choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, null);

        $this->assertSame(0, $result);
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
            [
                'id' => 11,
                'name' => 'Start Progress',
                'to' => [
                    'name' => 'In Progress',
                    'statusCategory' => ['key' => 'in_progress', 'name' => 'In Progress'],
                ],
            ],
        ];

        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-37')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-37')
            ->willReturn($transitions);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-37', 11);

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // ask() for key - TPW-37
        fwrite($inputStream, "TPW-37\n");
        // choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithInvalidKeyFormat(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // ask() for key - invalid key
        fwrite($inputStream, "INVALID-KEY\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithEmptyKeyPrompt(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getJiraKeyFromBranchName')
            ->willReturn(null);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // ask() for key - empty string
        fwrite($inputStream, "\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Issue not found'));

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
    }

    public function testHandleWithIssueNotFoundApiException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "TPW-35".', 'HTTP 404: Not Found', 404));

        $this->logger->expects($this->once())
            ->method('errorWithDetails')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $this->stringContains('item.transition.error_not_found'),
                'HTTP 404: Not Found'
            );
        $this->logger->method('section');
        $this->logger->method('jiraWriteln');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn([]);

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        // Verify transitionIssue is never called
        $this->jiraService->expects($this->never())
            ->method('transitionIssue');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willThrowException(new \Exception('Failed to fetch transitions'));

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        // Verify transitionIssue is never called
        $this->jiraService->expects($this->never())
            ->method('transitionIssue');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
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

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willThrowException(new \App\Exception\ApiException('Could not fetch transitions for issue "TPW-35".', 'HTTP 500: Internal Server Error', 500));

        $this->logger->expects($this->once())
            ->method('errorWithDetails')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $this->stringContains('item.transition.error_fetch'),
                'HTTP 500: Internal Server Error'
            );
        $this->logger->method('section');
        $this->logger->method('jiraWriteln');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
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
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11)
            ->willThrowException(new \Exception('Failed to execute transition'));

        // Verify assignIssue is never called
        $this->jiraService->expects($this->never())
            ->method('assignIssue');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
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
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11)
            ->willThrowException(new \App\Exception\ApiException('Could not execute transition 11 for issue "TPW-35".', 'HTTP 400: Bad Request', 400));

        $this->logger->expects($this->once())
            ->method('errorWithDetails')
            ->with(
                \App\Service\Logger::VERBOSITY_NORMAL,
                $this->stringContains('item.transition.error_execute'),
                'HTTP 400: Bad Request'
            );
        $this->logger->method('section');
        $this->logger->method('jiraWriteln');
        $this->logger->method('listing');
        $this->logger->method('choice')
            ->willReturn('Start Progress (ID: 11)');
        $this->logger->method('success');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io, 'TPW-35');

        $this->assertSame(1, $result);
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
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->jiraService->expects($this->once())
            ->method('getTransitions')
            ->with('TPW-35')
            ->willReturn($transitions);

        $this->jiraService->expects($this->once())
            ->method('transitionIssue')
            ->with('TPW-35', 11);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() for transition selection - select first option (index 0)
        fwrite($inputStream, "0\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $handler = $this->createHandlerWithRealLogger($io);
        $result = $handler->handle($io, 'TPW-35');

        $this->assertSame(0, $result);
    }

    public function testResolveKeyWithProvidedKey(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $key = $this->callPrivateMethod($this->handler, 'resolveKey', [ 'tpw-35']);

        $this->assertSame('TPW-35', $key);
    }

    public function testResolveKeyWithLowercaseKey(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $key = $this->callPrivateMethod($this->handler, 'resolveKey', [ 'proj-123']);

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
        // ask() for key - null (simulated by empty input that gets trimmed)
        fwrite($inputStream, "\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $key = $this->callPrivateMethod($this->handler, 'resolveKey', [ null]);

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
        // ask() for key - provide invalid format (doesn't match /^[A-Z]+-\d+$/)
        fwrite($inputStream, "invalid-key-format\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $key = $this->callPrivateMethod($handler, 'resolveKey', [ null]);

        $this->assertNull($key);
    }
}
