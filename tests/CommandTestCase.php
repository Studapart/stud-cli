<?php

namespace App\Tests;

use App\DTO\MessageRef;
use App\Response\WorkflowResponse;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\JiraApiClient;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class CommandTestCase extends TestCase
{
    protected GitRepository $gitRepository;
    protected GitBranchService $gitBranchService;
    protected JiraApiClient $jiraApiClient;
    protected IssueTrackerPort $issueTracker;
    protected TranslationService $translationService;

    protected function setUp(): void
    {
        parent::setUp();

        // Shared doubles are stubs. A test class that verifies calls on one of them
        // replaces it with createMock() right after parent::setUp().
        $this->gitRepository = $this->createStub(GitRepository::class);
        $this->gitBranchService = $this->createStub(GitBranchService::class);
        $this->jiraApiClient = $this->createStub(JiraApiClient::class);
        $this->issueTracker = $this->createStub(IssueTrackerPort::class);

        // TranslationServiceTest uses real instances for integration testing
        $this->translationService = $this->createStub(TranslationService::class);
        $this->configureTranslationDefaults($this->translationService);
    }

    protected function createTranslationMock(): TranslationService&MockObject
    {
        $translationService = $this->createMock(TranslationService::class);
        $this->configureTranslationDefaults($translationService);

        return $translationService;
    }

    private function configureTranslationDefaults(TranslationService&Stub $translationService): void
    {
        // Return the key as the value for simple testing
        $translationService->method('trans')
            ->willReturnCallback(function ($id, $parameters = []) {
                return $id . (empty($parameters) ? '' : ' ' . json_encode($parameters));
            });

        $translationService->method('getLocale')
            ->willReturn('en');

        $translationService->method('render')
            ->willReturnCallback(function ($message) use ($translationService) {
                if ($message instanceof MessageRef) {
                    return $translationService->trans($message->key, $message->parameters);
                }

                return $message === null ? null : (string) $message;
            });

        $translationService->method('renderText')
            ->willReturnCallback(function ($message) use ($translationService) {
                $rendered = $translationService->render($message);

                return $rendered ?? ($message === null ? '' : (string) $message);
            });

        $translationService->method('renderForAgentText')
            ->willReturnCallback(fn ($message): string => $message === null ? '' : (string) $message);

        $translationService->method('transForAgentText')
            ->willReturnCallback(fn (string $id, array $parameters = []): string => $id . (empty($parameters) ? '' : ' ' . json_encode($parameters)));
    }

    protected function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * @param array<string, mixed>|null $parameters
     */
    protected function assertMessageRef(mixed $message, string $key, ?array $parameters = null): MessageRef
    {
        $this->assertInstanceOf(MessageRef::class, $message);
        $this->assertSame($key, $message->key);
        if ($parameters !== null) {
            $this->assertSame($parameters, $message->parameters);
        }

        return $message;
    }

    /**
     * @param array<string, mixed>|null $parameters
     */
    protected function assertWorkflowEntry(WorkflowResponse $response, string $key, ?array $parameters = null): MessageRef
    {
        foreach ($response->entries as $entry) {
            if ($entry->message instanceof MessageRef && $entry->message->key === $key) {
                return $this->assertMessageRef($entry->message, $key, $parameters);
            }
        }

        $present = [];
        foreach ($response->entries as $entry) {
            if ($entry->message instanceof MessageRef) {
                $present[] = $entry->message->key;
            }
        }

        $this->fail(sprintf('No workflow entry "%s". Present: %s', $key, implode(', ', $present)));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $expectedDataSubset
     */
    protected function assertAgentJsonSuccess(array $payload, ?array $expectedDataSubset = null): void
    {
        $this->assertTrue($payload['success']);
        if ($expectedDataSubset !== null) {
            foreach ($expectedDataSubset as $key => $expectedValue) {
                $this->assertSame($expectedValue, $payload['data'][$key]);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function assertAgentJsonErrorString(array $payload, string $expectedError): void
    {
        $this->assertFalse($payload['success']);
        $this->assertSame($expectedError, $payload['error']);
    }

    protected function messageRefWithKey(string $key): \PHPUnit\Framework\Constraint\Callback
    {
        return $this->callback(fn (mixed $message): bool => $message instanceof MessageRef && $message->key === $key);
    }

    /**
     * Create a Logger that delegates to the given SymfonyStyle (mock or real).
     * When using a mock, stub isQuiet() and isVerbose()/isVeryVerbose()/isDebug() so Logger::getCurrentVerbosity() and shouldDisplay() work.
     */
    protected function createLogger(SymfonyStyle $io, int $verbosity = OutputInterface::VERBOSITY_NORMAL): Logger
    {
        if (! $io instanceof Stub) {
            return new Logger($io, new \App\Service\WorkflowChannelColorizer([]), new \App\Service\OutputVerbosityGate($io), messageRenderer: new MessageRenderer($this->translationService));
        }
        $io->method('isQuiet')->willReturn(false);
        $io->method('isVerbose')->willReturn($verbosity >= OutputInterface::VERBOSITY_VERBOSE);
        $io->method('isVeryVerbose')->willReturn($verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE);
        $io->method('isDebug')->willReturn($verbosity >= OutputInterface::VERBOSITY_DEBUG);

        return new Logger($io, new \App\Service\WorkflowChannelColorizer([]), new \App\Service\OutputVerbosityGate($io), messageRenderer: new MessageRenderer($this->translationService));
    }

    protected function createSymfonyStyle(int $verbosity = OutputInterface::VERBOSITY_NORMAL): SymfonyStyle
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput($verbosity);

        return new SymfonyStyle($input, $output);
    }

    protected function getOutput(SymfonyStyle $io): string
    {
        $reflection = new \ReflectionClass($io);
        $property = $reflection->getProperty('output');
        \App\Util\ReflectionAccessor::ensureAccessible($property);
        /** @var BufferedOutput $output */
        $output = $property->getValue($io);

        return $output->fetch();
    }
}
