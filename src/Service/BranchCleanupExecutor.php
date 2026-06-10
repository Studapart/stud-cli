<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\BranchCleanupPlan;
use App\DTO\MessageRef;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;

class BranchCleanupExecutor
{
    private bool $remoteTrackingRefsPruned = false;

    public function __construct(
        private readonly GitRepository $gitRepository,
        mixed $translator,
        private readonly WorkflowOutput $logger
    ) {
        unset($translator);
    }

    /**
     * Executes branch cleanup plans and returns the number of local branches deleted.
     *
     * @param array<BranchCleanupPlan> $cleanupPlans
     */
    public function execute(array $cleanupPlans, bool $quiet): int
    {
        $this->remoteTrackingRefsPruned = false;
        $deletedCount = 0;

        foreach ($cleanupPlans as $plan) {
            if ($this->executeCleanupPlan($plan, $quiet)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    protected function executeCleanupPlan(BranchCleanupPlan $plan, bool $quiet): bool
    {
        if ($plan->localAction === BranchCleanupLocalAction::SafeDelete) {
            return $this->safeDeleteLocalBranch($plan, $quiet);
        }

        if ($plan->localAction === BranchCleanupLocalAction::ForceDelete) {
            return $this->forceDeleteLocalBranch($plan, $quiet);
        }

        return false;
    }

    protected function safeDeleteLocalBranch(BranchCleanupPlan $plan, bool $quiet): bool
    {
        $branch = $plan->branch;

        try {
            $this->pruneRemoteTrackingRefsIfNeeded($plan);
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleting', ['branch' => $branch]));
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=gray>Deleting local branch: {$branch}</>");
            $this->gitRepository->deleteBranch($branch);
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted local branch: {$branch}</>");
            $this->handleRemoteBranchDeletion($plan, $quiet);

            return true;
        } catch (\Exception $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.error', ['branch' => $branch, 'error' => $e->getMessage()]));
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=red>Deletion failed: {$e->getMessage()}</>");

            return false;
        }
    }

    protected function forceDeleteLocalBranch(BranchCleanupPlan $plan, bool $quiet): bool
    {
        $branch = $plan->branch;

        try {
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleting', ['branch' => $branch]));
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=gray>Force deleting local branch: {$branch}</>");
            $this->gitRepository->deleteBranchForce($branch);
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=green>Successfully force-deleted local branch: {$branch}</>");
            $this->handleRemoteBranchDeletion($plan, $quiet);

            return true;
        } catch (\Exception $forceException) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.error', ['branch' => $branch, 'error' => $forceException->getMessage()]));
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=red>Force deletion also failed: {$forceException->getMessage()}</>");

            return false;
        }
    }

    protected function pruneRemoteTrackingRefsIfNeeded(BranchCleanupPlan $plan): void
    {
        if ($plan->remoteExists || $this->remoteTrackingRefsPruned) {
            return;
        }

        $this->logger->addLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.pruning_refs'));
        $this->gitRepository->pruneRemoteTrackingRefs();
        $this->remoteTrackingRefsPruned = true;
    }

    protected function handleRemoteBranchDeletion(BranchCleanupPlan $plan, bool $quiet): void
    {
        if ($plan->remoteAction === BranchCleanupRemoteAction::Skip) {
            return;
        }

        $branch = $plan->branch;
        if ($quiet || $plan->remoteAction === BranchCleanupRemoteAction::KeepQuiet) {
            $this->logger->addLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.remote_kept_quiet', ['branch' => $branch]));

            return;
        }

        if ($plan->remoteAction !== BranchCleanupRemoteAction::PromptDelete) {
            return;
        }

        $deleteRemote = $this->logger->confirm(
            MessageRef::key('branches.clean.delete_remote_confirm', ['branch' => $branch]),
            false
        );

        if ($deleteRemote) {
            $this->deleteRemoteBranch($branch);
        } else {
            $this->logger->addLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.remote_kept', ['branch' => $branch]));
        }
    }

    protected function deleteRemoteBranch(string $branch): void
    {
        try {
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=gray>Deleting remote branch: origin/{$branch}</>");
            $this->gitRepository->deleteRemoteBranch('origin', $branch);
            $this->logger->addLine(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleted_remote', ['branch' => $branch]));
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=green>Successfully deleted remote branch: origin/{$branch}</>");
        } catch (\Exception $e) {
            $this->logger->addWarning(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.error_remote', ['branch' => $branch, 'error' => $e->getMessage()]));
            $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=red>Remote deletion failed: {$e->getMessage()}</>");
        }
    }
}
