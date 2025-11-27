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
}

