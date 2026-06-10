<?php

namespace App\Tests;

use App\DTO\MessageRef;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

abstract class CommandTestCase extends TestCase
{
    protected GitRepository $gitRepository;
    protected GitBranchService $gitBranchService;
    protected JiraService $jiraService;
    protected TranslationService $translationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->jiraService = $this->createMock(JiraService::class);

        // Mock TranslationService to avoid file system dependencies in unit tests
        // Note: TranslationServiceTest uses real instances for integration testing
        $this->translationService = $this->createMock(TranslationService::class);

        // Set up default mock behavior: return the key as the value for simple testing
        $this->translationService->method('trans')
            ->willReturnCallback(function ($id, $parameters = []) {
                // Return a simple representation for testing
                return $id . (empty($parameters) ? '' : ' ' . json_encode($parameters));
            });

        $this->translationService->method('getLocale')
            ->willReturn('en');

        $this->translationService->method('render')
            ->willReturnCallback(function ($message) {
                if ($message instanceof \App\DTO\MessageRef) {
                    return $this->translationService->trans($message->key, $message->parameters);
                }

                return $message === null ? null : (string) $message;
            });

        $this->translationService->method('renderText')
            ->willReturnCallback(function ($message) {
                $rendered = $this->translationService->render($message);

                return $rendered ?? ($message === null ? '' : (string) $message);
            });

        $this->translationService->method('renderForAgentText')
            ->willReturnCallback(fn ($message): string => $message === null ? '' : (string) $message);

        $this->translationService->method('transForAgentText')
            ->willReturnCallback(fn (string $id, array $parameters = []): string => $id . (empty($parameters) ? '' : ' ' . json_encode($parameters)));
    }

    protected function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

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

    protected function messageRefWithKey(string $key): \PHPUnit\Framework\Constraint\Callback
    {
        return $this->callback(fn (mixed $message): bool => $message instanceof MessageRef && $message->key === $key);
    }

    protected function createMockProcess(bool $isSuccessful): Process
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn($isSuccessful);

        return $process;
    }

    /**
     * Create a Logger that delegates to the given SymfonyStyle (mock or real).
     * When using a mock, stub isQuiet() and isVerbose()/isVeryVerbose()/isDebug() so Logger::getCurrentVerbosity() and shouldDisplay() work.
     */
    protected function createLogger(SymfonyStyle $io, int $verbosity = OutputInterface::VERBOSITY_NORMAL): Logger
    {
        if ($io instanceof SymfonyStyle && ! $this->isMockObject($io)) {
            return new Logger($io, [], messageRenderer: new MessageRenderer($this->translationService));
        }
        $io->method('isQuiet')->willReturn(false);
        $io->method('isVerbose')->willReturn($verbosity >= OutputInterface::VERBOSITY_VERBOSE);
        $io->method('isVeryVerbose')->willReturn($verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE);
        $io->method('isDebug')->willReturn($verbosity >= OutputInterface::VERBOSITY_DEBUG);

        return new Logger($io, [], messageRenderer: new MessageRenderer($this->translationService));
    }

    private function isMockObject(object $obj): bool
    {
        return str_starts_with($obj::class, 'Mock_');
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
        $property->setAccessible(true);
        /** @var BufferedOutput $output */
        $output = $property->getValue($io);

        return $output->fetch();
    }
}
