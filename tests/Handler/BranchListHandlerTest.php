<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\BranchListHandler;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GithubProvider;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class BranchListHandlerTest extends CommandTestCase
{
    private BranchListHandler $handler;
    private GithubProvider&MockObject $githubProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->githubProvider = $this->createMock(GithubProvider::class);
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $this->handler = new BranchListHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            'origin/develop',
            $this->translationService
        );
    }

    public function testHandleWithNoBranchesReturnsEmptyRows(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn([]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(0, $response->rows);
    }

    public function testHandleWithBranchesReturnsRows(): void
    {
        $branches = ['develop', 'feat/PROJ-123', 'main'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['develop', 'main']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitBranchService->method('isBranchMergedInto')->willReturnCallback(
            fn ($branch, $base) => $branch === 'feat/PROJ-123' && $base === 'origin/develop'
        );
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', true],
            ['origin', 'master', false],
        ]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(3, $response->rows);
        $this->assertSame('branches.list.auto_clean.no', $response->rows[0]->autoClean);
    }

    public function testHandleWithMergedByProviderSetsYesAutoClean(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'number' => 123,
                'state' => 'closed',
                'merged_at' => '2026-03-26T10:00:00Z',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ]);
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
        ]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
        $this->assertSame('✓', $response->rows[0]->pr);
        $this->assertSame('branches.list.auto_clean.yes', $response->rows[0]->autoClean);
    }

    public function testHandleWithUnresolvedBaseUsesManualAutoClean(): void
    {
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, null);
        $handler = new BranchListHandler($this->gitRepository, $this->gitBranchService, $resolver, null, $this->translationService);

        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('remoteBranchExists')->willReturn(false);

        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
        $this->assertSame('branches.list.auto_clean.manual', $response->rows[0]->autoClean);
    }
}
