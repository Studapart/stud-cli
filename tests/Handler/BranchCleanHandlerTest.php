<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\BranchCleanHandler;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GithubProvider;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchCleanHandlerTest extends CommandTestCase
{
    private BranchCleanHandler $handler;
    private GithubProvider&MockObject $githubProvider;
    private Logger&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->githubProvider = $this->createMock(GithubProvider::class);
        $this->logger = $this->createMock(Logger::class);
        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('writeln');
        $this->logger->method('warning');
        $this->logger->method('success');
        $this->logger->method('confirm')->willReturn(true);
        $this->logger->method('ask')->willReturn('develop');

        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $this->handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            'origin/develop',
            $this->translationService,
            $this->logger
        );
    }

    public function testHandleQuietDeletesOnlyYesBranches(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/merged', 'feat/manual']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/merged', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturnCallback(
            fn (string $branch, string $base) => $branch === 'feat/merged' && $base === 'origin/develop'
        );
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/merged', false);

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleSkipsOpenPullRequestBranch(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/open-pr']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/open-pr']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturn(true);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'state' => 'open',
                'head' => ['ref' => 'feat/open-pr', 'repo' => ['full_name' => 'owner/repo']],
                'base' => ['repo' => ['full_name' => 'owner/repo']],
            ],
        ]);

        $this->gitRepository->expects($this->never())->method('deleteBranch');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleInteractiveCanConfirmManualBranch(): void
    {
        $this->logger->method('confirm')->willReturn(true);
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/manual']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', false],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/manual', false],
        ]);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/manual', false);

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }
}
