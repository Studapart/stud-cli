<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\BranchCleanupPlan;
use App\DTO\MessageRef;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;
use App\Enum\WorkflowChannel;
use App\Service\Prompt\PromptInterface;

class BranchCleanupExecutor
{
    private bool $remoteTrackingRefsPruned = false;

    public function __construct(
        private readonly GitRepository $gitRepository,
        mixed $translator,
        private readonly PromptInterface $prompt,
    ) {
        unset($translator);
    }

    /**
     * Executes branch cleanup plans and returns the number of local branches deleted.
     *
     * @param array<BranchCleanupPlan> $cleanupPlans
     */
    public function execute(array $cleanupPlans, bool $quiet, WorkflowEntryRecorder $recorder): int
    {
        $this->remoteTrackingRefsPruned = false;
        $deletedCount = 0;

        foreach ($cleanupPlans as $plan) {
            if ($this->executeCleanupPlan($plan, $quiet, $recorder)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    protected function executeCleanupPlan(BranchCleanupPlan $plan, bool $quiet, WorkflowEntryRecorder $recorder): bool
    {
        if ($plan->localAction === BranchCleanupLocalAction::SafeDelete) {
            return $this->safeDeleteLocalBranch($plan, $quiet, $recorder);
        }

        if ($plan->localAction === BranchCleanupLocalAction::ForceDelete) {
            return $this->forceDeleteLocalBranch($plan, $quiet, $recorder);
        }

        return false;
    }

    protected function safeDeleteLocalBranch(BranchCleanupPlan $plan, bool $quiet, WorkflowEntryRecorder $recorder): bool
    {
        $branch = $plan->branch;

        try {
            $this->pruneRemoteTrackingRefsIfNeeded($plan, $recorder);
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleting', ['branch' => $branch]), WorkflowChannel::Git);
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=gray>Deleting local branch: {$branch}</>", WorkflowChannel::Git);
            $this->gitRepository->deleteBranch($branch);
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted local branch: {$branch}</>", WorkflowChannel::Git);
            $this->handleRemoteBranchDeletion($plan, $quiet, $recorder);

            return true;
        } catch (\Exception $e) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.error', ['branch' => $branch, 'error' => $e->getMessage()]));
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=red>Deletion failed: {$e->getMessage()}</>", WorkflowChannel::Git);

            return false;
        }
    }

    protected function forceDeleteLocalBranch(BranchCleanupPlan $plan, bool $quiet, WorkflowEntryRecorder $recorder): bool
    {
        $branch = $plan->branch;

        try {
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleting', ['branch' => $branch]), WorkflowChannel::Git);
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=gray>Force deleting local branch: {$branch}</>", WorkflowChannel::Git);
            $this->gitRepository->deleteBranchForce($branch);
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=green>Successfully force-deleted local branch: {$branch}</>", WorkflowChannel::Git);
            $this->handleRemoteBranchDeletion($plan, $quiet, $recorder);

            return true;
        } catch (\Exception $forceException) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.error', ['branch' => $branch, 'error' => $forceException->getMessage()]));
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=red>Force deletion also failed: {$forceException->getMessage()}</>", WorkflowChannel::Git);

            return false;
        }
    }

    protected function pruneRemoteTrackingRefsIfNeeded(BranchCleanupPlan $plan, WorkflowEntryRecorder $recorder): void
    {
        if ($plan->remoteExists || $this->remoteTrackingRefsPruned) {
            return;
        }

        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.pruning_refs'), WorkflowChannel::Git);
        $this->gitRepository->pruneRemoteTrackingRefs();
        $this->remoteTrackingRefsPruned = true;
    }

    protected function handleRemoteBranchDeletion(BranchCleanupPlan $plan, bool $quiet, WorkflowEntryRecorder $recorder): void
    {
        if ($plan->remoteAction === BranchCleanupRemoteAction::Skip) {
            return;
        }

        $branch = $plan->branch;
        if ($quiet || $plan->remoteAction === BranchCleanupRemoteAction::KeepQuiet) {
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.remote_kept_quiet', ['branch' => $branch]), WorkflowChannel::Git);

            return;
        }

        if ($plan->remoteAction !== BranchCleanupRemoteAction::PromptDelete) {
            return;
        }

        $deleteRemote = $this->prompt->confirm(
            MessageRef::key('branches.clean.delete_remote_confirm', ['branch' => $branch]),
            false
        );

        if ($deleteRemote) {
            $this->deleteRemoteBranch($branch, $recorder);
        } else {
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.remote_kept', ['branch' => $branch]), WorkflowChannel::Git);
        }
    }

    protected function deleteRemoteBranch(string $branch, WorkflowEntryRecorder $recorder): void
    {
        try {
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=gray>Deleting remote branch: origin/{$branch}</>", WorkflowChannel::Git);
            $this->gitRepository->deleteRemoteBranch('origin', $branch);
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleted_remote', ['branch' => $branch]), WorkflowChannel::Git);
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted remote branch: origin/{$branch}</>", WorkflowChannel::Git);
        } catch (\Exception $e) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branches.clean.error_remote', ['branch' => $branch, 'error' => $e->getMessage()]));
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_DEBUG, "    <fg=red>Remote deletion failed: {$e->getMessage()}</>", WorkflowChannel::Git);
        }
    }
}
