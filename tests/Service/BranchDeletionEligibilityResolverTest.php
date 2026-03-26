<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\BranchAutoCleanDecision;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GitBranchService;
use App\Service\GitProviderInterface;
use App\Service\GitRepository;
use PHPUnit\Framework\TestCase;

class BranchDeletionEligibilityResolverTest extends TestCase
{
    public function testResolveBaseBranchUsesConfiguredThenFallback(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'custom', true],
            ['origin', 'develop', false],
            ['origin', 'main', true],
            ['origin', 'master', false],
        ]);
        $resolver = new BranchDeletionEligibilityResolver($gitRepository, $this->createMock(GitBranchService::class), null);

        $this->assertSame('origin/custom', $resolver->resolveBaseBranch('origin/custom'));
        $this->assertSame('origin/main', $resolver->resolveBaseBranch(null));
    }

    public function testBuildPullRequestSnapshotHandlesProviderFailure(): void
    {
        $provider = $this->createMock(GitProviderInterface::class);
        $provider->method('getAllPullRequests')->willThrowException(new \RuntimeException('api'));
        $resolver = new BranchDeletionEligibilityResolver($this->createMock(GitRepository::class), $this->createMock(GitBranchService::class), $provider);

        $snapshot = $resolver->buildPullRequestSnapshot();
        $this->assertFalse($snapshot['available']);
        $this->assertSame([], $snapshot['map']);
    }

    public function testBuildPullRequestSnapshotFiltersForkPullRequests(): void
    {
        $provider = $this->createMock(GitProviderInterface::class);
        $provider->method('getAllPullRequests')->willReturn([
            ['head' => [], 'base' => ['repo' => ['full_name' => 'org/repo']]],
            ['head' => ['ref' => 'feat/a', 'repo' => ['full_name' => 'org/repo']], 'base' => ['repo' => ['full_name' => 'org/repo']]],
            ['head' => ['ref' => 'feat/fork', 'repo' => ['full_name' => 'fork/repo']], 'base' => ['repo' => ['full_name' => 'org/repo']]],
        ]);
        $resolver = new BranchDeletionEligibilityResolver($this->createMock(GitRepository::class), $this->createMock(GitBranchService::class), $provider);

        $snapshot = $resolver->buildPullRequestSnapshot();
        $this->assertTrue($snapshot['available']);
        $this->assertArrayHasKey('feat/a', $snapshot['map']);
        $this->assertArrayNotHasKey('feat/fork', $snapshot['map']);
    }

    public function testEvaluateReturnsNoForProtectedCurrentAndOpenPr(): void
    {
        $resolver = new BranchDeletionEligibilityResolver($this->createMock(GitRepository::class), $this->createMock(GitBranchService::class), null);

        $protected = $resolver->evaluate('develop', 'main', true, 'origin/develop', [], true);
        $current = $resolver->evaluate('feat/a', 'feat/a', true, 'origin/develop', [], true);
        $openPr = $resolver->evaluate('feat/b', 'main', true, 'origin/develop', ['feat/b' => ['state' => 'open']], true);

        $this->assertSame(BranchAutoCleanDecision::No, $protected->decision);
        $this->assertSame(BranchAutoCleanDecision::No, $current->decision);
        $this->assertSame(BranchAutoCleanDecision::No, $openPr->decision);
    }

    public function testEvaluateReturnsManualForUnresolvedBaseAndProviderUnavailable(): void
    {
        $resolver = new BranchDeletionEligibilityResolver($this->createMock(GitRepository::class), $this->createMock(GitBranchService::class), null);
        $unresolved = $resolver->evaluate('feat/a', 'main', false, null, [], true);
        $providerUnavailable = $resolver->evaluate('feat/a', 'main', false, 'origin/develop', [], false);

        $this->assertSame(BranchAutoCleanDecision::Manual, $unresolved->decision);
        $this->assertSame('base_branch_unresolved', $unresolved->reason);
        $this->assertSame(BranchAutoCleanDecision::Manual, $providerUnavailable->decision);
    }

    public function testEvaluateReturnsYesWhenMergedByGitOrProvider(): void
    {
        $gitBranchService = $this->createMock(GitBranchService::class);
        $gitBranchService->method('isBranchMergedInto')->willReturnMap([
            ['feat/git', 'origin/develop', true],
            ['feat/provider', 'origin/develop', false],
        ]);
        $resolver = new BranchDeletionEligibilityResolver($this->createMock(GitRepository::class), $gitBranchService, null);

        $mergedByGit = $resolver->evaluate('feat/git', 'main', true, 'origin/develop', [], true);
        $mergedByProvider = $resolver->evaluate(
            'feat/provider',
            'main',
            false,
            'origin/develop',
            ['feat/provider' => ['state' => 'closed', 'merged_at' => '2026-03-26T10:00:00Z']],
            true
        );

        $this->assertSame(BranchAutoCleanDecision::Yes, $mergedByGit->decision);
        $this->assertSame('merged', $mergedByGit->status);
        $this->assertSame(BranchAutoCleanDecision::Yes, $mergedByProvider->decision);
        $this->assertSame('stale', $mergedByProvider->status);
    }

    public function testEvaluateReturnsManualOnClosedUnmergedPullRequest(): void
    {
        $gitBranchService = $this->createMock(GitBranchService::class);
        $gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $resolver = new BranchDeletionEligibilityResolver($this->createMock(GitRepository::class), $gitBranchService, null);

        $eligibility = $resolver->evaluate(
            'feat/closed',
            'main',
            true,
            'origin/develop',
            ['feat/closed' => ['state' => 'closed']],
            true
        );

        $this->assertSame(BranchAutoCleanDecision::Manual, $eligibility->decision);
        $this->assertSame('closed_pull_request_unmerged', $eligibility->reason);
    }

    public function testEvaluateReturnsManualWhenMergeCheckThrows(): void
    {
        $gitBranchService = $this->createMock(GitBranchService::class);
        $gitBranchService->method('isBranchMergedInto')->willThrowException(new \RuntimeException('boom'));
        $resolver = new BranchDeletionEligibilityResolver($this->createMock(GitRepository::class), $gitBranchService, null);

        $eligibility = $resolver->evaluate('feat/ex', 'main', false, 'origin/develop', [], true);

        $this->assertSame(BranchAutoCleanDecision::Manual, $eligibility->decision);
        $this->assertSame('merge_check_failed', $eligibility->reason);
    }
}
