<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\BranchCleanupPlan;
use App\DTO\BranchDeletionEligibility;
use App\DTO\MessageRef;
use App\Enum\BranchAutoCleanDecision;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;
use App\Enum\WorkflowChannel;

class BranchCleanupPlanner
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly BranchDeletionEligibilityResolver $eligibilityResolver,
    ) {
    }

    /**
     * @return array{
     *   automatic: array<BranchCleanupPlan>,
     *   current_branch_skipped: bool,
     *   manual: array<BranchCleanupPlan>
     * }
     */
    public function findBranchesToClean(WorkflowEntryRecorder $recorder, ?string $baseBranch, bool $quiet): array
    {
        $allBranches = $this->fetchAllBranches($recorder);
        $remoteBranchesSet = $this->fetchRemoteBranchesSet($recorder);
        $currentBranch = $this->gitRepository->getCurrentBranchName();
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=gray>Current branch: {$currentBranch}</>", WorkflowChannel::Git);
        $prSnapshot = $this->eligibilityResolver->buildPullRequestSnapshot($recorder);

        $automaticPlans = [];
        $currentBranchSkipped = false;
        $manualPlans = [];

        foreach ($allBranches as $branch) {
            $remoteExists = isset($remoteBranchesSet[$branch]);
            $eligibility = $this->eligibilityResolver->evaluate(
                $branch,
                $currentBranch,
                $remoteExists,
                $baseBranch,
                $prSnapshot['map'],
                $prSnapshot['available'],
                $recorder,
            );

            if ($eligibility->reason === 'current_branch') {
                $currentBranchSkipped = true;
            }

            $plan = $this->buildCleanupPlan($branch, $eligibility, $remoteExists, $quiet);
            if ($plan->localAction === BranchCleanupLocalAction::Manual) {
                $manualPlans[] = $plan;
            }

            if (
                $plan->localAction === BranchCleanupLocalAction::SafeDelete
                || $plan->localAction === BranchCleanupLocalAction::ForceDelete
            ) {
                $automaticPlans[] = $plan;
            }
        }

        return [
            'automatic' => $automaticPlans,
            'current_branch_skipped' => $currentBranchSkipped,
            'manual' => $manualPlans,
        ];
    }

    public function buildCleanupPlan(
        string $branch,
        BranchDeletionEligibility $eligibility,
        bool $remoteExists,
        bool $quiet,
    ): BranchCleanupPlan {
        if ($eligibility->decision === BranchAutoCleanDecision::Manual) {
            return new BranchCleanupPlan(
                $branch,
                $eligibility,
                $remoteExists,
                BranchCleanupLocalAction::Manual,
                BranchCleanupRemoteAction::Manual
            );
        }

        if ($eligibility->decision !== BranchAutoCleanDecision::Yes) {
            return new BranchCleanupPlan(
                $branch,
                $eligibility,
                $remoteExists,
                BranchCleanupLocalAction::Skip,
                BranchCleanupRemoteAction::Skip
            );
        }

        $localAction = $eligibility->mergedByGit
            ? BranchCleanupLocalAction::SafeDelete
            : BranchCleanupLocalAction::ForceDelete;

        return new BranchCleanupPlan(
            $branch,
            $eligibility,
            $remoteExists,
            $localAction,
            $this->resolveAutomaticRemoteAction($remoteExists, $quiet)
        );
    }

    public function resolveAutomaticRemoteAction(bool $remoteExists, bool $quiet): BranchCleanupRemoteAction
    {
        if (! $remoteExists) {
            return BranchCleanupRemoteAction::Skip;
        }

        return $quiet ? BranchCleanupRemoteAction::KeepQuiet : BranchCleanupRemoteAction::PromptDelete;
    }

    /**
     * @return array<string>
     */
    public function fetchAllBranches(WorkflowEntryRecorder $recorder): array
    {
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.fetching_local'), WorkflowChannel::Git);
        $allBranches = $this->gitBranchService->getAllLocalBranches();
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($allBranches) . " local branches</>", WorkflowChannel::Git);

        return $allBranches;
    }

    /**
     * @return array<string, int>
     */
    public function fetchRemoteBranchesSet(WorkflowEntryRecorder $recorder): array
    {
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.fetching_remote'), WorkflowChannel::Git);
        $remoteBranches = $this->gitBranchService->getAllRemoteBranches('origin');
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($remoteBranches) . " remote branches on origin</>", WorkflowChannel::Git);

        return array_flip($remoteBranches);
    }
}
