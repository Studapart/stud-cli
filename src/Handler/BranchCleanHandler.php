<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\BranchCleanupPlan;
use App\DTO\BranchDeletionEligibility;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;
use App\Enum\WorkflowChannel;
use App\Guard\Capability\GitRepositoryAware;
use App\Response\WorkflowResponse;
use App\Service\BranchCleanupExecutor;
use App\Service\BranchCleanupPlanner;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;

class BranchCleanHandler implements GitRepositoryAware
{
    private WorkflowEntryRecorder $recorder;

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly BranchDeletionEligibilityResolver $eligibilityResolver,
        private readonly BranchCleanupExecutor $cleanupExecutor,
        private readonly BranchCleanupPlanner $cleanupPlanner,
        private readonly ?string $configuredBaseBranch,
        private readonly PromptInterface $prompt,
    ) {
    }

    public function handle(bool $quiet = false): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $this->displayHeader();

        $baseBranch = $this->resolveBaseBranch($quiet);
        $result = $this->findBranchesToClean($baseBranch, $quiet);
        $cleanupPlans = $result['automatic'];
        $currentBranchSkipped = $result['current_branch_skipped'];
        $manualPlans = $result['manual'];

        if (! $quiet) {
            $this->addManuallyConfirmedPlans($manualPlans, $cleanupPlans, $quiet);
        }

        if ($this->shouldExitEarly($cleanupPlans, $currentBranchSkipped, $manualPlans)) {
            if ($quiet) {
                $this->displayManualBranchesReport($manualPlans);
            }

            return $this->recorder->toResponse(0);
        }

        $this->notifyCurrentBranchSkipped($currentBranchSkipped);
        $this->displayBranchesToClean($cleanupPlans, $quiet);

        if (! $this->confirmDeletion($cleanupPlans, $quiet)) {
            return $this->recorder->toResponse(0);
        }

        $deletedCount = $this->deleteBranches($cleanupPlans, $quiet);
        $this->displayDeletionResult($deletedCount);
        if ($quiet) {
            $this->displayManualBranchesReport($manualPlans);
        }

        return $this->recorder->toResponse(0);
    }

    protected function displayHeader(): void
    {
        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.section'));
        $this->recorder->addNote(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.note_origin'));
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.scanning'), WorkflowChannel::Git);
        $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.fetching_local'), WorkflowChannel::Git);
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     * @param array<BranchCleanupPlan> $manualPlans
     */
    protected function shouldExitEarly(array $cleanupPlans, bool $currentBranchSkipped, array $manualPlans): bool
    {
        $totalBranches = count($cleanupPlans);
        $hasManualBranches = ! empty($manualPlans);

        if ($totalBranches === 0 && ! $currentBranchSkipped && ! $hasManualBranches) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.none'));

            return true;
        }

        if ($totalBranches === 0 && ! $hasManualBranches) {
            return true;
        }

        return false;
    }

    protected function notifyCurrentBranchSkipped(bool $currentBranchSkipped): void
    {
        if ($currentBranchSkipped) {
            $currentBranch = $this->gitRepository->getCurrentBranchName();
            $this->recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.current_branch_skipped', ['branch' => $currentBranch]));
        }
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     */
    protected function displayBranchesToClean(array $cleanupPlans, bool $quiet): void
    {
        $branchesToCleanLocal = $this->getLocalOnlyBranchNames($cleanupPlans);
        $branchesToCleanRemote = $this->getRemoteBranchNames($cleanupPlans);
        $totalBranches = count($cleanupPlans);
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.found', ['count' => $totalBranches]));

        if (! empty($branchesToCleanLocal)) {
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.local_only_header', ['count' => count($branchesToCleanLocal)]));
            $this->displayBranchesList($branchesToCleanLocal);
        }

        if (! empty($branchesToCleanRemote)) {
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.with_remote_header', ['count' => count($branchesToCleanRemote)]));
            $this->displayBranchesList($branchesToCleanRemote);
            if (! $quiet) {
                $this->recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.with_remote_note'));
            }
        }
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     */
    protected function confirmDeletion(array $cleanupPlans, bool $quiet): bool
    {
        if ($quiet) {
            return true;
        }

        $totalBranches = count($cleanupPlans);
        $confirmed = $this->prompt->confirm(
            MessageRef::key('branches.clean.confirm', ['count' => $totalBranches]),
            true
        );

        if (! $confirmed) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.cancelled'));

            return false;
        }

        return true;
    }

    protected function displayDeletionResult(int $deletedCount): void
    {
        if ($deletedCount > 0) {
            $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleted', ['count' => $deletedCount]));
        }
    }

    /**
     * @return array{
     *   automatic: array<BranchCleanupPlan>,
     *   current_branch_skipped: bool,
     *   manual: array<BranchCleanupPlan>
     * }
     */
    protected function findBranchesToClean(?string $baseBranch, bool $quiet): array
    {
        return $this->cleanupPlanner->findBranchesToClean($this->recorder, $baseBranch, $quiet);
    }

    protected function buildCleanupPlan(
        string $branch,
        BranchDeletionEligibility $eligibility,
        bool $remoteExists,
        bool $quiet,
    ): BranchCleanupPlan {
        return $this->cleanupPlanner->buildCleanupPlan($branch, $eligibility, $remoteExists, $quiet);
    }

    protected function resolveAutomaticRemoteAction(bool $remoteExists, bool $quiet): BranchCleanupRemoteAction
    {
        return $this->cleanupPlanner->resolveAutomaticRemoteAction($remoteExists, $quiet);
    }

    /**
     * @return array<string>
     */
    protected function fetchAllBranches(): array
    {
        return $this->cleanupPlanner->fetchAllBranches($this->recorder);
    }

    /**
     * @return array<string, int>
     */
    protected function fetchRemoteBranchesSet(): array
    {
        return $this->cleanupPlanner->fetchRemoteBranchesSet($this->recorder);
    }

    protected function resolveBaseBranch(bool $quiet): ?string
    {
        $resolved = $this->eligibilityResolver->resolveBaseBranch($this->configuredBaseBranch);
        if ($resolved !== null || $quiet) {
            return $resolved;
        }

        $enteredBranch = $this->prompt->ask(
            MessageRef::key('branches.clean.base_branch_prompt'),
            'develop',
            function (?string $value): string {
                $branch = trim((string) $value);
                if ($branch === '') {
                    throw new \RuntimeException('Base branch cannot be empty.');
                }

                return $branch;
            }
        );
        if ($enteredBranch === null || ! $this->gitRepository->remoteBranchExists('origin', $enteredBranch)) {
            return null;
        }

        return 'origin/' . $enteredBranch;
    }

    /**
     * @param array<BranchCleanupPlan> $manualPlans
     * @param array<BranchCleanupPlan> $cleanupPlans
     */
    protected function addManuallyConfirmedPlans(array $manualPlans, array &$cleanupPlans, bool $quiet): void
    {
        foreach ($manualPlans as $manualPlan) {
            $confirm = $this->prompt->confirm(
                MessageRef::key('branches.clean.manual_confirm', [
                    'branch' => $manualPlan->branch,
                    'reason' => $this->translateReason($manualPlan->eligibility->reason),
                ]),
                false
            );
            if (! $confirm) {
                continue;
            }

            $cleanupPlans[] = $this->buildManuallyConfirmedPlan($manualPlan, $quiet);
        }
    }

    /**
     * @param array<BranchCleanupPlan> $manualPlans
     */
    protected function displayManualBranchesReport(array $manualPlans): void
    {
        if ($manualPlans === []) {
            return;
        }

        $this->recorder->addNote(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('branches.clean.manual_report_header', ['count' => count($manualPlans)])
        );

        foreach ($manualPlans as $manualPlan) {
            $this->recorder->addText(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('branches.clean.manual_report_row', [
                    'branch' => $manualPlan->branch,
                    'reason' => $this->translateReason($manualPlan->eligibility->reason),
                ])
            );
        }

        $this->recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.manual_report_hint'));
    }

    protected function translateReason(string $reason): MessageRef
    {
        return MessageRef::key("branches.clean.reason.{$reason}");
    }

    protected function buildManuallyConfirmedPlan(BranchCleanupPlan $manualPlan, bool $quiet): BranchCleanupPlan
    {
        return new BranchCleanupPlan(
            $manualPlan->branch,
            $manualPlan->eligibility,
            $manualPlan->remoteExists,
            BranchCleanupLocalAction::SafeDelete,
            $this->resolveAutomaticRemoteAction($manualPlan->remoteExists, $quiet)
        );
    }

    /**
     * @param array<string> $branches
     */
    protected function displayBranchesList(array $branches): void
    {
        foreach ($branches as $branch) {
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, "  - {$branch}");
        }
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     */
    protected function deleteBranches(array $cleanupPlans, bool $quiet): int
    {
        return $this->cleanupExecutor->execute($cleanupPlans, $quiet, $this->recorder);
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     *
     * @return array<string>
     */
    protected function getLocalOnlyBranchNames(array $cleanupPlans): array
    {
        return array_values(array_map(
            fn (BranchCleanupPlan $plan): string => $plan->branch,
            array_filter($cleanupPlans, fn (BranchCleanupPlan $plan): bool => ! $plan->remoteExists)
        ));
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     *
     * @return array<string>
     */
    protected function getRemoteBranchNames(array $cleanupPlans): array
    {
        return array_values(array_map(
            fn (BranchCleanupPlan $plan): string => $plan->branch,
            array_filter($cleanupPlans, fn (BranchCleanupPlan $plan): bool => $plan->remoteExists)
        ));
    }
}
