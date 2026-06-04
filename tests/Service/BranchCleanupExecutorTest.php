<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\BranchCleanupPlan;
use App\DTO\BranchDeletionEligibility;
use App\Enum\BranchAutoCleanDecision;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;
use App\Service\BranchCleanupExecutor;
use App\Service\Logger;
use App\Tests\CommandTestCase;

class BranchCleanupExecutorTest extends CommandTestCase
{
    private Logger $logger;
    private BranchCleanupExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->logger->method('text');
        $this->logger->method('writeln');
        $this->logger->method('warning');

        $this->executor = new BranchCleanupExecutor(
            $this->gitRepository,
            $this->translationService,
            $this->logger
        );
    }

    public function testExecuteSafeDeletePrunesRemoteTrackingRefsOnce(): void
    {
        $plans = [
            $this->createCleanupPlan('feat/one'),
            $this->createCleanupPlan('feat/two'),
        ];

        $this->gitRepository->expects($this->once())->method('pruneRemoteTrackingRefs');
        $this->gitRepository->expects($this->exactly(2))->method('deleteBranch');

        $this->assertSame(2, $this->executor->execute($plans, true));
    }

    public function testExecuteForceDeleteUsesForcePath(): void
    {
        $plan = $this->createCleanupPlan(
            'feat/provider',
            false,
            BranchCleanupRemoteAction::Skip,
            BranchCleanupLocalAction::ForceDelete
        );

        $this->gitRepository->expects($this->never())->method('deleteBranch');
        $this->gitRepository->expects($this->once())->method('deleteBranchForce')->with('feat/provider');

        $this->assertSame(1, $this->executor->execute([$plan], true));
    }

    public function testExecuteSafeDeleteReturnsZeroOnException(): void
    {
        $this->gitRepository->method('deleteBranch')->willThrowException(new \RuntimeException('safe failed'));

        $this->assertSame(0, $this->executor->execute([$this->createCleanupPlan('feat/fail-safe', true)], true));
    }

    public function testExecuteForceDeleteReturnsZeroOnException(): void
    {
        $plan = $this->createCleanupPlan(
            'feat/fail-force',
            false,
            BranchCleanupRemoteAction::Skip,
            BranchCleanupLocalAction::ForceDelete
        );
        $this->gitRepository->method('deleteBranchForce')->willThrowException(new \RuntimeException('force failed'));

        $this->assertSame(0, $this->executor->execute([$plan], true));
    }

    public function testExecutePromptsBeforeDeletingRemoteBranch(): void
    {
        $plan = $this->createCleanupPlan('feat/remote', true, BranchCleanupRemoteAction::PromptDelete);

        $this->logger->expects($this->once())->method('confirm')->willReturn(true);
        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/remote');
        $this->gitRepository->expects($this->once())->method('deleteRemoteBranch')->with('origin', 'feat/remote');

        $this->assertSame(1, $this->executor->execute([$plan], false));
    }

    public function testExecuteKeepsRemoteWhenPromptDenied(): void
    {
        $plan = $this->createCleanupPlan('feat/keep-remote', true, BranchCleanupRemoteAction::PromptDelete);

        $this->logger->expects($this->once())->method('confirm')->willReturn(false);
        $this->gitRepository->expects($this->never())->method('deleteRemoteBranch');

        $this->assertSame(1, $this->executor->execute([$plan], false));
    }

    public function testExecuteKeepsRemoteInQuietMode(): void
    {
        $plan = $this->createCleanupPlan('feat/quiet', true, BranchCleanupRemoteAction::KeepQuiet);

        $this->logger->expects($this->never())->method('confirm');
        $this->gitRepository->expects($this->never())->method('deleteRemoteBranch');

        $this->assertSame(1, $this->executor->execute([$plan], true));
    }

    public function testExecuteHandlesRemoteDeleteException(): void
    {
        $plan = $this->createCleanupPlan('feat/remote-fail', true, BranchCleanupRemoteAction::PromptDelete);

        $this->logger->expects($this->once())->method('confirm')->willReturn(true);
        $this->gitRepository->method('deleteRemoteBranch')->willThrowException(new \RuntimeException('remote failed'));

        $this->assertSame(1, $this->executor->execute([$plan], false));
    }

    public function testExecuteSkipsNonExecutablePlans(): void
    {
        $plan = $this->createCleanupPlan(
            'main',
            true,
            BranchCleanupRemoteAction::Skip,
            BranchCleanupLocalAction::Skip
        );

        $this->assertSame(0, $this->executor->execute([$plan], true));
    }

    public function testExecuteReturnsForManualRemoteAction(): void
    {
        $plan = $this->createCleanupPlan(
            'feat/manual-remote',
            true,
            BranchCleanupRemoteAction::Manual
        );

        $this->logger->expects($this->never())->method('confirm');

        $this->assertSame(1, $this->executor->execute([$plan], false));
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
}
