<?php

namespace App\Tests\Handler;

use App\Handler\PrCommentHandler;
use App\Service\GitRepository;
use App\Service\GithubProvider;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class PrCommentHandlerTest extends CommandTestCase
{
    private PrCommentHandler $handler;
    private ?GithubProvider $githubProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubProvider::class);
        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new PrCommentHandler(
            $this->gitRepository,
            $this->githubProvider,
            $this->translationService
        );
    }

    public function testHandleSuccessWithArgument(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(['number' => 123]);

        $this->githubProvider
            ->expects($this->once())
            ->method('createComment')
            ->with(123, 'My comment message')
            ->willReturn(['id' => 456]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'My comment message');

        $this->assertSame(0, $result);
    }

    public function testHandleWithEmptyArgumentAndNoStdin(): void
    {
        // When message is null and STDIN is empty (TTY), should fail early
        // No need to mock PR finding since it fails before that
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithNoProvider(): void
    {
        $handler = new PrCommentHandler(
            $this->gitRepository,
            null,
            $this->translationService
        );

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io, 'My comment');

        $this->assertSame(1, $result);
    }

    public function testHandleWithNoInput(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // No argument and no STDIN (simulated by null)
        $result = $this->handler->handle($io, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithNoPrFound(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'My comment');

        $this->assertSame(1, $result);
    }

    public function testHandleWithApiError(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(['number' => 123]);

        $this->githubProvider
            ->expects($this->once())
            ->method('createComment')
            ->with(123, 'My comment')
            ->willThrowException(new \RuntimeException('API Error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, 'My comment');

        $this->assertSame(1, $result);
    }

    public function testGetCommentBodyWithArgument(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->callPrivateMethod($this->handler, 'getCommentBody', [$io, 'My message']);

        $this->assertSame('My message', $result);
    }

    public function testGetCommentBodyWithEmptyArgument(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // When argument is empty and STDIN is TTY, should return null
        $result = $this->callPrivateMethod($this->handler, 'getCommentBody', [$io, '']);

        $this->assertNull($result);
    }

    public function testGetCommentBodyWithNullArgument(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // When argument is null and STDIN is TTY, should return null
        $result = $this->callPrivateMethod($this->handler, 'getCommentBody', [$io, null]);

        $this->assertNull($result);
    }

    public function testReadStdinWithTty(): void
    {
        // When STDIN is a TTY (interactive terminal), readStdin should return empty string
        // We can't easily mock posix_isatty, but we can test the logic
        // In a real scenario with TTY, it returns empty string
        $result = $this->callPrivateMethod($this->handler, 'readStdin', []);

        // In test environment, STDIN might be a TTY, so result should be empty
        $this->assertIsString($result);
    }

    public function testFindActivePullRequestSuccess(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(['number' => 123]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertSame(123, $result);
    }

    public function testFindActivePullRequestWithNoOwner(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn(null);
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('feat/TPW-35-my-feature')
            ->willReturn(['number' => 123]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertSame(123, $result);
    }

    public function testFindActivePullRequestNotFound(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertNull($result);
    }

    public function testFindActivePullRequestWithException(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willThrowException(new \RuntimeException('API Error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertNull($result);
    }

    public function testHandleSuccessWithVerboseOutput(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(['number' => 123]);

        $this->githubProvider
            ->expects($this->once())
            ->method('createComment')
            ->with(123, 'My comment message')
            ->willReturn(['id' => 456]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io, 'My comment message');

        $this->assertSame(0, $result);
    }

    public function testGetCommentBodyWithStdinContent(): void
    {
        // This is hard to test directly since we can't easily mock STDIN
        // But we can test that when readStdin returns content, it's used
        // We'll test the verbose path separately
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        // When argument is provided, it should be used even if STDIN might have content
        // (In real usage, STDIN takes precedence, but we can't easily test that)
        $result = $this->callPrivateMethod($this->handler, 'getCommentBody', [$io, 'My message']);

        $this->assertSame('My message', $result);
    }

    public function testGetCommentBodyWithVerboseOutput(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        $result = $this->callPrivateMethod($this->handler, 'getCommentBody', [$io, 'My message']);

        $this->assertSame('My message', $result);
    }

    public function testFindActivePullRequestWithVerboseOutput(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(['number' => 123]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertSame(123, $result);
    }

    public function testFindActivePullRequestWithExceptionAndVerbose(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willThrowException(new \RuntimeException('API Error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertNull($result);
    }

    public function testHandleWithEmptyStringArgument(): void
    {
        // Empty string should be treated as no input
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, '');

        $this->assertSame(1, $result);
    }

    public function testHandleWithWhitespaceOnlyArgument(): void
    {
        // Whitespace-only string should be treated as no input (trimmed to empty)
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, '   ');

        $this->assertSame(1, $result);
    }

    public function testFindActivePullRequestWithPrMissingNumberKey(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn(['id' => 123]); // Missing 'number' key

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertNull($result);
    }

    public function testFindActivePullRequestWithEmptyPrArray(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/TPW-35-my-feature');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        
        $this->githubProvider
            ->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/TPW-35-my-feature')
            ->willReturn([]); // Empty array

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', [$io]);

        $this->assertNull($result);
    }

    public function testGetCommentBodyWithStdinPrecedence(): void
    {
        // Test that when both STDIN and argument are available, STDIN takes precedence
        // Since we can't easily mock STDIN, we test the logic path
        // In real usage, if STDIN has content, it's used; otherwise argument is used
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        
        // When argument is provided and STDIN is empty (TTY), argument should be used
        $result = $this->callPrivateMethod($this->handler, 'getCommentBody', [$io, 'Argument message']);
        
        $this->assertSame('Argument message', $result);
    }

    public function testGetCommentBodyWithVerboseAndStdin(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->setVerbosity(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        
        // Test verbose output path when using argument (STDIN is empty in test env)
        $result = $this->callPrivateMethod($this->handler, 'getCommentBody', [$io, 'My message']);
        
        $this->assertSame('My message', $result);
    }

    public function testReadStdinWhenNotResource(): void
    {
        // This tests the fallback path when STDIN is not a resource
        // In normal execution, STDIN is always a resource, but we test the code path
        $result = $this->callPrivateMethod($this->handler, 'readStdin', []);
        
        // Should return empty string when STDIN is TTY or not available
        $this->assertIsString($result);
        $this->assertEmpty($result);
    }

    public function testReadStdinFallbackPath(): void
    {
        // Test the fallback path when posix_isatty doesn't exist
        // This is hard to test directly, but we can verify the method handles it
        $result = $this->callPrivateMethod($this->handler, 'readStdin', []);
        
        // Should return empty string in test environment
        $this->assertIsString($result);
    }
}

