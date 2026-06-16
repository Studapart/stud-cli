<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\BranchCleanupPlan;
use App\DTO\BranchDeletionEligibility;
use App\DTO\WorkflowRecorder;
use App\Enum\BranchAutoCleanDecision;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;
use App\Handler\BranchCleanHandler;
use App\Service\BranchCleanupExecutor;
use App\Service\BranchCleanupPlanner;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GithubProvider;
use App\Service\Prompt\PromptInterface;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchCleanHandlerTest extends CommandTestCase
{
    private BranchCleanHandler $handler;
    private GithubProvider&MockObject $githubProvider;
    private PromptInterface&MockObject $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->githubProvider = $this->createMock(GithubProvider::class);
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->prompt->method('confirm')->willReturn(true);
        $this->prompt->method('ask')->willReturn('develop');

        $this->handler = $this->createBranchCleanHandler();
    }

    private function createBranchCleanHandler(
        ?string $configuredBaseBranch = 'origin/develop',
        ?PromptInterface $prompt = null,
        ?BranchDeletionEligibilityResolver $resolver = null,
    ): BranchCleanHandler {
        $prompt ??= $this->prompt;
        $resolver ??= new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);

        return new BranchCleanHandler(
            $this->gitRepository,
            $resolver,
            new BranchCleanupExecutor($this->gitRepository, $this->translationService, $prompt),
            new BranchCleanupPlanner($this->gitRepository, $this->gitBranchService, $resolver),
            $configuredBaseBranch,
            $prompt,
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
        $result = $this->handler->handle(true)->exitCode;

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
        $result = $this->handler->handle(true)->exitCode;

        $this->assertSame(0, $result);
    }

    public function testHandleInteractiveCanConfirmManualBranch(): void
    {
        $this->prompt->method('confirm')->willReturn(true);
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
        $result = $this->handler->handle(false)->exitCode;

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
        $result = $this->handler->handle(true)->exitCode;

        $this->assertSame(0, $result);
    }

    public function testHandleQuietKeepsExistingRemoteForProviderMergedBranch(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('confirm');
        $handler = $this->createBranchCleanHandler('origin/develop', $prompt);

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
        $result = $handler->handle(true)->exitCode;

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
        $result = $this->handler->handle(true)->exitCode;

        $this->assertSame(0, $result);
    }

    public function testHandleWithRemoteBranchDeleteConfirmed(): void
    {
        $this->prompt->method('confirm')->willReturnOnConsecutiveCalls(true, true);
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
        $result = $this->handler->handle(false)->exitCode;

        $this->assertSame(0, $result);
    }

    public function testResolveBaseBranchReturnsNullWhenPromptedBranchMissingRemotely(): void
    {
        $this->prompt->method('ask')->willReturn('feature/unknown-base');
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
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(false);
        $handler = $this->createBranchCleanHandler('origin/develop', $prompt);

        $this->initializeRecorder($handler);

        $confirmed = $this->callPrivateMethod($handler, 'confirmDeletion', [[$this->createCleanupPlan('feat/a')], false]);
        $this->assertFalse($confirmed);
    }

    public function testHandleReturnsEarlyWhenDeletionCancelled(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(false);
        $handler = $this->createBranchCleanHandler('origin/develop', $prompt);

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
        $result = $handler->handle(false)->exitCode;
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
        $this->assertSame(0, $this->handler->handle(true)->exitCode);
    }

    public function testAddManuallyConfirmedPlansSupportsSkipAndRemoteAppend(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))->method('confirm')->willReturnOnConsecutiveCalls(false, true);
        $handler = $this->createBranchCleanHandler('origin/develop', $prompt);

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
        $this->initializeRecorder($this->handler);
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
        $this->initializeRecorder($this->handler);
        $result = $this->callPrivateMethod(
            $this->handler,
            'deleteBranches',
            [[$this->createCleanupPlan('main', true, BranchCleanupRemoteAction::Skip, BranchCleanupLocalAction::Skip)], true]
        );
        $this->assertSame(0, $result);
    }

    public function testResolveBaseBranchInteractiveValidationAndSuccessPath(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturnCallback(function ($question, string $default, callable $validator): string {
            try {
                $validator('');
            } catch (\RuntimeException) {
                // expected validation exception path
            }

            return $validator('develop');
        });
        $handler = $this->createBranchCleanHandler(null, $prompt);

        $this->gitRepository->expects($this->exactly(4))
            ->method('remoteBranchExists')
            ->willReturnOnConsecutiveCalls(false, false, false, true);

        $resolved = $this->callPrivateMethod($handler, 'resolveBaseBranch', [false]);
        $this->assertSame('origin/develop', $resolved);
    }

    private function initializeRecorder(BranchCleanHandler $handler): void
    {
        $reflection = new \ReflectionClass($handler);
        $property = $reflection->getProperty('recorder');
        $property->setAccessible(true);
        $property->setValue($handler, new WorkflowRecorder());
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

    public function testFetchAllBranchesDelegatesToPlanner(): void
    {
        $this->initializeRecorder($this->handler);
        $branches = $this->callPrivateMethod($this->handler, 'fetchAllBranches');

        $this->assertIsArray($branches);
    }

    public function testFetchRemoteBranchesSetDelegatesToPlanner(): void
    {
        $this->initializeRecorder($this->handler);
        $set = $this->callPrivateMethod($this->handler, 'fetchRemoteBranchesSet');

        $this->assertIsArray($set);
    }
}
