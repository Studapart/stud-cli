<?php

namespace App\Tests\Handler;

use App\Handler\BranchListHandler;
use App\Service\GithubProvider;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchListHandlerTest extends CommandTestCase
{
    private BranchListHandler $handler;
    private GithubProvider&MockObject $githubProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubProvider::class);
        $logger = $this->createMock(Logger::class);
        $this->handler = new BranchListHandler($this->gitRepository, $this->githubProvider, $this->translationService, $logger);
    }

    public function testHandleWithNoBranches(): void
    {
        $this->gitRepository->method('getAllLocalBranches')->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranches(): void
    {
        $branches = ['develop', 'feat/PROJ-123', 'main'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['develop', 'main']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturnCallback(function ($branch, $base) {
            return $branch === 'feat/PROJ-123' && $base === 'develop';
        });
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchHavingPr(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(['number' => 123, 'state' => 'open']);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithGithubProviderNull(): void
    {
        $logger = $this->createMock(Logger::class);
        $handler = new BranchListHandler($this->gitRepository, null, $this->translationService, $logger);

        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithGithubProviderException(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('findPullRequestByBranchName')->willThrowException(new \Exception('API error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithMergedBranchOnRemote(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithStaleBranch(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }
}
