<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\BranchCleanupPlan;
use App\DTO\BranchDeletionEligibility;
use App\Enum\BranchAutoCleanDecision;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;
use App\Handler\BranchCleanHandler;
use App\Service\BranchCleanupExecutor;
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
        $this->logger->method('addSection');
        $this->logger->method('addNote');
        $this->logger->method('addText');
        $this->logger->method('addLine');
        $this->logger->method('addWarning');
        $this->logger->method('addSuccess');
        $this->logger->method('confirm')->willReturn(true);
        $this->logger->method('ask')->willReturn('develop');

        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $this->handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            new BranchCleanupExecutor($this->gitRepository, $this->translationService, $this->logger),
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

        $this->gitRepository->expects($this->once())->method('pruneRemoteTrackingRefs');
        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/merged');
        $this->gitRepository->expects($this->never())->method('deleteBranchForce');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle(true);

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
        $result = $this->handler->handle(true);

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

        $this->gitRepository->expects($this->once())->method('pruneRemoteTrackingRefs');
        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/manual');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle(false);

        $this->assertSame(0, $result);
    }

    public function testHandleProviderMergedBranchUsesForceDeleteDirectly(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/fallback']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/fallback', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'state' => 'closed',
                'merged_at' => '2026-06-04T10:00:00Z',
                'head' => ['ref' => 'feat/fallback', 'repo' => ['full_name' => 'owner/repo']],
                'base' => ['repo' => ['full_name' => 'owner/repo']],
            ],
        ]);
        $this->gitRepository->expects($this->never())->method('deleteBranch');
        $this->gitRepository->expects($this->once())->method('deleteBranchForce')->with('feat/fallback');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle(true);

        $this->assertSame(0, $result);
    }

    public function testHandleQuietKeepsExistingRemoteForProviderMergedBranch(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('addSection');
        $logger->method('addNote');
        $logger->method('addText');
        $logger->method('addLine');
        $logger->method('addWarning');
        $logger->method('addSuccess');
        $logger->expects($this->never())->method('confirm');
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            new BranchCleanupExecutor($this->gitRepository, $this->translationService, $logger),
            'origin/develop',
            $this->translationService,
            $logger
        );

        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/provider-remote']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/provider-remote']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'state' => 'closed',
                'merged_at' => '2026-06-04T10:00:00Z',
                'head' => ['ref' => 'feat/provider-remote', 'repo' => ['full_name' => 'owner/repo']],
                'base' => ['repo' => ['full_name' => 'owner/repo']],
            ],
        ]);

        $this->gitRepository->expects($this->once())->method('deleteBranchForce')->with('feat/provider-remote');
        $this->gitRepository->expects($this->never())->method('deleteRemoteBranch');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $handler->handle(true);

        $this->assertSame(0, $result);
    }

    public function testHandleDoesNotRecheckRemoteStateDuringDeletion(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/merged']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->expects($this->once())
            ->method('remoteBranchExists')
            ->with('origin', 'develop')
            ->willReturn(true);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/merged');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle(true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithRemoteBranchDeleteConfirmed(): void
    {
        $this->logger->method('confirm')->willReturnOnConsecutiveCalls(true, true);
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/remote']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/remote']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/remote', true],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);
        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/remote');
        $this->gitRepository->expects($this->once())->method('deleteRemoteBranch')->with('origin', 'feat/remote');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle(false);

        $this->assertSame(0, $result);
    }

    public function testResolveBaseBranchReturnsNullWhenPromptedBranchMissingRemotely(): void
    {
        $this->logger->method('ask')->willReturn('feature/unknown-base');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', false],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feature/unknown-base', false],
        ]);

        $resolved = $this->callPrivateMethod($this->handler, 'resolveBaseBranch', [false]);
        $this->assertNull($resolved);
    }

    public function testShouldExitEarlyReturnsFalseWhenManualBranchesExist(): void
    {
        $manualPlan = $this->createManualCleanupPlan('feat/x');
        $result = $this->callPrivateMethod($this->handler, 'shouldExitEarly', [[], false, [$manualPlan]]);
        $this->assertFalse($result);
    }

    public function testShouldExitEarlyReturnsTrueWhenOnlyCurrentSkipped(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'shouldExitEarly', [[], true, []]);
        $this->assertTrue($result);
    }

    public function testConfirmDeletionReturnsFalseWhenCancelled(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $logger->method('addText');
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            new BranchCleanupExecutor($this->gitRepository, $this->translationService, $logger),
            'origin/develop',
            $this->translationService,
            $logger
        );

        $confirmed = $this->callPrivateMethod($handler, 'confirmDeletion', [[$this->createCleanupPlan('feat/a')], false]);
        $this->assertFalse($confirmed);
    }

    public function testHandleReturnsEarlyWhenDeletionCancelled(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('addSection');
        $logger->method('addNote');
        $logger->method('addText');
        $logger->method('addLine');
        $logger->method('addWarning');
        $logger->method('addSuccess');
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            new BranchCleanupExecutor($this->gitRepository, $this->translationService, $logger),
            'origin/develop',
            $this->translationService,
            $logger
        );

        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/cancel']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/cancel', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $handler->handle(false);
        $this->assertSame(0, $result);
    }

    public function testHandleCurrentBranchSkippedTriggersNotifyPath(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/current', 'feat/merged']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/current');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/merged', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturnCallback(fn (string $b) => $b === 'feat/merged');
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $this->gitRepository->expects($this->once())->method('pruneRemoteTrackingRefs');
        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/merged');
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $this->assertSame(0, $this->handler->handle(true));
    }

    public function testAddManuallyConfirmedPlansSupportsSkipAndRemoteAppend(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->exactly(2))->method('confirm')->willReturnOnConsecutiveCalls(false, true);
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            new BranchCleanupExecutor($this->gitRepository, $this->translationService, $logger),
            'origin/develop',
            $this->translationService,
            $logger
        );

        $cleanupPlans = [];
        $manual = [
            $this->createManualCleanupPlan('feat/skip'),
            $this->createManualCleanupPlan('feat/remote-manual', true),
        ];

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('addManuallyConfirmedPlans');
        $method->setAccessible(true);
        $args = [$manual, &$cleanupPlans, false];
        $method->invokeArgs($handler, $args);

        $this->assertCount(1, $cleanupPlans);
        $this->assertSame('feat/remote-manual', $cleanupPlans[0]->branch);
        $this->assertSame(BranchCleanupLocalAction::SafeDelete, $cleanupPlans[0]->localAction);
        $this->assertSame(BranchCleanupRemoteAction::PromptDelete, $cleanupPlans[0]->remoteAction);
    }

    public function testDisplayManualBranchesReportWithEntries(): void
    {
        $this->callPrivateMethod($this->handler, 'displayManualBranchesReport', [[$this->createManualCleanupPlan('feat/manual')]]);
        $this->assertTrue(true);
    }

    public function testBuildCleanupPlanSkipsProtected(): void
    {
        $plan = $this->callPrivateMethod(
            $this->handler,
            'buildCleanupPlan',
            [
                'main',
                new BranchDeletionEligibility(BranchAutoCleanDecision::No, 'protected_branch', 'active', false),
                true,
                true,
            ]
        );

        $this->assertSame(BranchCleanupLocalAction::Skip, $plan->localAction);
        $this->assertSame(BranchCleanupRemoteAction::Skip, $plan->remoteAction);
    }

    public function testDeleteBranchesSkipsNonExecutablePlans(): void
    {
        $result = $this->callPrivateMethod(
            $this->handler,
            'deleteBranches',
            [[$this->createCleanupPlan('main', true, BranchCleanupRemoteAction::Skip, BranchCleanupLocalAction::Skip)], true]
        );
        $this->assertSame(0, $result);
    }

    public function testResolveBaseBranchInteractiveValidationAndSuccessPath(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('ask')->willReturnCallback(function ($question, string $default, callable $validator): string {
            try {
                $validator('');
            } catch (\RuntimeException) {
                // expected validation exception path
            }

            return $validator('develop');
        });
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            new BranchCleanupExecutor($this->gitRepository, $this->translationService, $logger),
            null,
            $this->translationService,
            $logger
        );

        $this->gitRepository->expects($this->exactly(4))
            ->method('remoteBranchExists')
            ->willReturnOnConsecutiveCalls(false, false, false, true);

        $resolved = $this->callPrivateMethod($handler, 'resolveBaseBranch', [false]);
        $this->assertSame('origin/develop', $resolved);
    }

    private function createCleanupPlan(
        string $branch,
        bool $remoteExists = false,
        BranchCleanupRemoteAction $remoteAction = BranchCleanupRemoteAction::Skip,
        BranchCleanupLocalAction $localAction = BranchCleanupLocalAction::SafeDelete
    ): BranchCleanupPlan {
        return new BranchCleanupPlan(
            $branch,
            new BranchDeletionEligibility(
                BranchAutoCleanDecision::Yes,
                'merged',
                $remoteExists ? 'merged' : 'stale',
                false,
                $localAction === BranchCleanupLocalAction::SafeDelete,
                $localAction === BranchCleanupLocalAction::ForceDelete
            ),
            $remoteExists,
            $localAction,
            $remoteAction
        );
    }

    private function createManualCleanupPlan(string $branch, bool $remoteExists = false): BranchCleanupPlan
    {
        return new BranchCleanupPlan(
            $branch,
            new BranchDeletionEligibility(
                BranchAutoCleanDecision::Manual,
                'provider_unavailable',
                'active',
                false
            ),
            $remoteExists,
            BranchCleanupLocalAction::Manual,
            BranchCleanupRemoteAction::Manual
        );
    }
}
